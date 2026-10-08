<?php

namespace App\Services\Accounting;

use App\Models\Accounting\Account;
use App\Models\Accounting\PayrollProfile;
use App\Models\Accounting\PayrollRun;
use App\Models\Accounting\PayrollTaxTable;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Monthly payroll using SARS's annualisation method for PAYE.
 * Finalising posts:  Dr Salaries & Wages (gross) + Dr Employer UIF & SDL
 *                    Cr PAYE / UIF (employee + employer) / SDL payable, Cr Accrued (other deductions), Cr Net salaries payable
 * Paying staff and SARS afterwards is a normal payment against those liability accounts.
 */
class PayrollService
{
    public function __construct(private Ledger $ledger) {}

    /** Calculate (or recalculate) a draft run for "YYYY-MM". */
    public function calculate(string $period, string $payDate): PayrollRun
    {
        $table = PayrollTaxTable::forDate($payDate);
        if (! $table) {
            throw ValidationException::withMessages(['pay_date' => 'No SARS tax table covers '.Carbon::parse($payDate)->format('j M Y')
                .'. Add the current tax year under Payroll → Tax tables (copy last year and update it from the SARS Budget tables).']);
        }
        $run = PayrollRun::firstOrNew(['period' => $period]);
        if ($run->status === 'finalised') {
            throw ValidationException::withMessages(['period' => "Payroll for $period is already finalised."]);
        }
        $profiles = PayrollProfile::with('staff.user')->where('is_active', true)->get();
        if ($profiles->isEmpty()) {
            throw ValidationException::withMessages(['period' => 'No employees have a payroll profile yet.']);
        }
        // SDL only applies when annual payroll exceeds the threshold.
        $sdlApplies = $profiles->sum(fn ($p) => $p->basic_salary + $p->taxable_allowances) * 12 > $table->sdl_annual_threshold;

        return DB::transaction(function () use ($run, $payDate, $table, $profiles, $sdlApplies) {
            $run->fill(['pay_date' => $payDate, 'tax_table_id' => $table->id, 'status' => 'draft'])->save();
            $run->payslips()->delete();
            foreach ($profiles as $p) {
                $run->payslips()->create($this->payslip($p, $table, Carbon::parse($payDate), $sdlApplies));
            }
            $s = $run->payslips();
            $run->update([
                'total_gross' => (clone $s)->sum('gross'), 'total_paye' => (clone $s)->sum('paye'),
                'total_uif' => (float) (clone $s)->sum('uif_employee') + (float) (clone $s)->sum('uif_employer'),
                'total_sdl' => (clone $s)->sum('sdl'), 'total_net' => (clone $s)->sum('net'),
            ]);

            return $run->fresh(['payslips.staff.user', 'taxTable']);
        });
    }

    public function payslip(PayrollProfile $p, PayrollTaxTable $t, Carbon $payDate, bool $sdlApplies): array
    {
        $gross = round($p->basic_salary + $p->taxable_allowances, 2);
        // Retirement contributions are deductible up to 27.5% of remuneration, capped annually.
        $retirementAnnual = min($p->retirement_contribution * 12, $gross * 12 * $t->retirement_cap_percent / 100, $t->retirement_cap_annual);
        $annualTaxable = max(round($gross * 12 - $retirementAnnual, 2), 0);
        $taxBeforeRebates = $t->annualTax($annualTaxable);

        $age = $p->date_of_birth ? $p->date_of_birth->diffInYears($t->ends_on) : 0;
        $rebates = $t->rebate_primary + ($age >= 65 ? $t->rebate_secondary : 0) + ($age >= 75 ? $t->rebate_tertiary : 0);
        $members = $p->medical_aid_members;
        $medicalMonthly = $members >= 1 ? $t->medical_credit_main * min($members, 2) + $t->medical_credit_additional * max($members - 2, 0) : 0;

        $annualPaye = max($taxBeforeRebates - $rebates - $medicalMonthly * 12, 0);
        $paye = round($annualPaye / 12, 2);
        $uifBase = min($gross, $t->uif_monthly_ceiling);
        $uif = $p->uif_exempt ? 0 : round($uifBase * $t->uif_rate / 100, 2);
        $sdl = $sdlApplies ? round($gross * $t->sdl_rate / 100, 2) : 0;
        $net = round($gross - $p->retirement_contribution - $paye - $uif - $p->other_deductions, 2);

        return [
            'staff_id' => $p->staff_id, 'basic_salary' => $p->basic_salary, 'allowances' => $p->taxable_allowances, 'gross' => $gross,
            'retirement' => $p->retirement_contribution, 'paye' => $paye, 'uif_employee' => $uif, 'uif_employer' => $uif, 'sdl' => $sdl,
            'other_deductions' => $p->other_deductions, 'net' => $net,
            'workings' => [
                'tax_year' => $t->label, 'annual_taxable_income' => $annualTaxable, 'retirement_deduction_annual' => round($retirementAnnual, 2),
                'tax_before_rebates' => $taxBeforeRebates, 'rebates' => $rebates, 'age' => $age,
                'medical_credits_monthly' => $medicalMonthly, 'annual_paye' => round($annualPaye, 2), 'uif_base' => $uifBase,
            ],
        ];
    }

    public function finalise(PayrollRun $run, ?User $by): PayrollRun
    {
        if ($run->status === 'finalised') {
            throw ValidationException::withMessages(['status' => 'Already finalised.']);
        }
        $slips = $run->payslips;
        $uifEe = round($slips->sum('uif_employee'), 2);
        $uifEr = round($slips->sum('uif_employer'), 2);
        $lines = [
            ['account_id' => Account::system('salaries')->id, 'debit' => round($slips->sum('gross'), 2), 'description' => 'Gross salaries'],
            ['account_id' => Account::system('employer_contributions')->id, 'debit' => round($uifEr + $slips->sum('sdl'), 2), 'description' => 'Employer UIF & SDL'],
            ['account_id' => Account::system('paye_payable')->id, 'credit' => round($slips->sum('paye'), 2), 'description' => 'PAYE'],
            ['account_id' => Account::system('uif_payable')->id, 'credit' => round($uifEe + $uifEr, 2), 'description' => 'UIF (employee + employer)'],
            ['account_id' => Account::system('sdl_payable')->id, 'credit' => round($slips->sum('sdl'), 2), 'description' => 'SDL'],
            ['account_id' => Account::where('code', '2400')->value('id') ?? Account::system('salaries_payable')->id,
                'credit' => round($slips->sum('retirement') + $slips->sum('other_deductions'), 2), 'description' => 'Retirement & other deductions'],
            ['account_id' => Account::system('salaries_payable')->id, 'credit' => round($slips->sum('net'), 2), 'description' => 'Net salaries payable'],
        ];

        return DB::transaction(function () use ($run, $lines, $by) {
            $entry = $this->ledger->post(['date' => $run->pay_date, 'reference' => 'Payroll '.$run->period, 'memo' => 'Payroll '.$run->period], $lines, $run, $by);
            $run->update(['status' => 'finalised', 'journal_entry_id' => $entry->id, 'finalised_by' => $by?->id]);

            return $run->fresh(['payslips.staff.user', 'taxTable']);
        });
    }

    /** EMP201 monthly return figures for a finalised period. */
    public function emp201(PayrollRun $run): array
    {
        return [
            'period' => $run->period, 'paye' => $run->total_paye, 'uif' => $run->total_uif, 'sdl' => $run->total_sdl,
            'total' => round($run->total_paye + $run->total_uif + $run->total_sdl, 2),
            'due_date' => Carbon::parse($run->period.'-01')->addMonth()->day(7)->toDateString(), // EMP201 due by the 7th
        ];
    }
}
