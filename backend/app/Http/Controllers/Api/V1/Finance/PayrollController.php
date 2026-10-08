<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Models\Accounting\PayrollProfile;
use App\Models\Accounting\PayrollRun;
use App\Models\Accounting\PayrollTaxTable;
use App\Models\Accounting\Payslip;
use App\Models\Setting;
use App\Models\Staff;
use App\Services\Accounting\PayrollService;
use Illuminate\Http\Request;

class PayrollController extends Controller
{
    public function __construct(private PayrollService $payroll) {}

    /** Every non-terminated staff member, with their payroll profile if they have one. */
    public function employees()
    {
        $profiles = PayrollProfile::all()->keyBy('staff_id');

        return ['data' => Staff::with('user')->where('status', '!=', 'terminated')->get()->sortBy('user.name')->values()->map(function ($s) use ($profiles) {
            $p = $profiles->get($s->id);

            return [
                'staff_id' => $s->id, 'name' => $s->user->name, 'role_title' => $s->role_title, 'employment_type' => $s->employment_type,
                'profile' => $p ? $p->only(['basic_salary', 'taxable_allowances', 'retirement_contribution', 'medical_aid_members',
                    'other_deductions', 'tax_number', 'id_number', 'bank_name', 'bank_account_number', 'uif_exempt', 'is_active'])
                    + ['date_of_birth' => $p->date_of_birth?->toDateString()] : null,
            ];
        })];
    }

    public function saveProfile(Request $request, Staff $staff)
    {
        $data = $request->validate([
            'basic_salary' => ['required', 'numeric', 'min:0'], 'taxable_allowances' => ['nullable', 'numeric', 'min:0'],
            'retirement_contribution' => ['nullable', 'numeric', 'min:0'], 'medical_aid_members' => ['nullable', 'integer', 'between:0,20'],
            'other_deductions' => ['nullable', 'numeric', 'min:0'], 'date_of_birth' => ['nullable', 'date', 'before:today'],
            'tax_number' => ['nullable', 'string', 'max:20'], 'id_number' => ['nullable', 'string', 'max:20'],
            'bank_name' => ['nullable', 'string', 'max:60'], 'bank_account_number' => ['nullable', 'string', 'max:30'],
            'uif_exempt' => ['sometimes', 'boolean'], 'is_active' => ['sometimes', 'boolean'],
        ]);
        foreach (['taxable_allowances', 'retirement_contribution', 'medical_aid_members', 'other_deductions'] as $k) {
            $data[$k] = $data[$k] ?? 0;
        }

        return PayrollProfile::updateOrCreate(['staff_id' => $staff->id], $data);
    }

    public function taxTables()
    {
        return ['data' => PayrollTaxTable::orderByDesc('starts_on')->get()];
    }

    /** Create a new tax year (usually by copying the previous one and updating figures from the SARS Budget). */
    public function saveTaxTable(Request $request, ?PayrollTaxTable $table = null)
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:20'], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after:starts_on'],
            'brackets' => ['required', 'array', 'min:1'], 'brackets.*.from' => ['required', 'numeric', 'min:0'],
            'brackets.*.rate' => ['required', 'numeric', 'between:0,100'], 'brackets.*.base' => ['required', 'numeric', 'min:0'],
            'rebate_primary' => ['required', 'numeric'], 'rebate_secondary' => ['required', 'numeric'], 'rebate_tertiary' => ['required', 'numeric'],
            'medical_credit_main' => ['required', 'numeric'], 'medical_credit_additional' => ['required', 'numeric'],
            'uif_rate' => ['required', 'numeric'], 'uif_monthly_ceiling' => ['required', 'numeric'],
            'sdl_rate' => ['required', 'numeric'], 'sdl_annual_threshold' => ['required', 'numeric'],
            'retirement_cap_percent' => ['required', 'numeric'], 'retirement_cap_annual' => ['required', 'numeric'],
            'source_note' => ['nullable', 'string', 'max:255'],
        ]);
        $overlap = PayrollTaxTable::where('id', '!=', $table?->id ?? 0)
            ->where('starts_on', '<=', $data['ends_on'])->where('ends_on', '>=', $data['starts_on'])->exists();
        abort_if($overlap, 422, 'Another tax table already covers part of these dates.');
        abort_if($table && PayrollRun::where('tax_table_id', $table->id)->where('status', 'finalised')->exists(), 422,
            'This table was used by a finalised payroll and can no longer be changed.');

        return $table ? tap($table)->update($data) : PayrollTaxTable::create($data);
    }

    public function runs()
    {
        return ['data' => PayrollRun::withCount('payslips')->orderByDesc('period')->get()->map(fn ($r) => [
            'id' => $r->id, 'period' => $r->period, 'pay_date' => $r->pay_date->toDateString(), 'status' => $r->status,
            'employees' => $r->payslips_count, 'total_gross' => $r->total_gross, 'total_paye' => $r->total_paye,
            'total_uif' => $r->total_uif, 'total_sdl' => $r->total_sdl, 'total_net' => $r->total_net,
        ])];
    }

    public function calculate(Request $request)
    {
        $data = $request->validate(['period' => ['required', 'date_format:Y-m'], 'pay_date' => ['required', 'date']]);

        return $this->presentRun($this->payroll->calculate($data['period'], $data['pay_date']));
    }

    public function showRun(PayrollRun $run)
    {
        return $this->presentRun($run->load(['payslips.staff.user', 'taxTable']));
    }

    public function finalise(Request $request, PayrollRun $run)
    {
        return $this->presentRun($this->payroll->finalise($run->load('payslips'), $request->user()));
    }

    public function payslip(Payslip $payslip)
    {
        $payslip->load(['staff.user', 'staff.branch', 'run']);
        $profile = PayrollProfile::where('staff_id', $payslip->staff_id)->first();

        return [
            'company' => Setting::current()->only(['company_name', 'vat_number']),
            'employee' => ['name' => $payslip->staff->user->name, 'role' => $payslip->staff->role_title, 'branch' => $payslip->staff->branch?->name,
                'tax_number' => $profile?->tax_number, 'id_number' => $profile?->id_number, 'bank' => $profile?->bank_name,
                'account' => $profile?->bank_account_number ? '••••'.substr($profile->bank_account_number, -4) : null],
            'period' => $payslip->run->period, 'pay_date' => $payslip->run->pay_date->toDateString(), 'status' => $payslip->run->status,
        ] + $payslip->only(['id', 'basic_salary', 'allowances', 'gross', 'retirement', 'paye', 'uif_employee', 'uif_employer', 'sdl', 'other_deductions', 'net', 'workings']);
    }

    private function presentRun(PayrollRun $run): array
    {
        return [
            'id' => $run->id, 'period' => $run->period, 'pay_date' => $run->pay_date->toDateString(), 'status' => $run->status,
            'tax_table' => $run->taxTable?->label, 'journal_entry_id' => $run->journal_entry_id,
            'totals' => $run->only(['total_gross', 'total_paye', 'total_uif', 'total_sdl', 'total_net']),
            'emp201' => $this->payroll->emp201($run),
            'payslips' => $run->payslips->map(fn ($p) => [
                'id' => $p->id, 'name' => $p->staff->user->name, 'gross' => $p->gross, 'paye' => $p->paye,
                'uif' => $p->uif_employee, 'sdl' => $p->sdl, 'deductions' => round($p->retirement + $p->other_deductions, 2), 'net' => $p->net,
            ]),
        ];
    }
}
