<?php

namespace Tests\Feature;

use App\Models\Accounting\Account;
use App\Models\Accounting\PayrollProfile;
use App\Models\Accounting\PayrollTaxTable;
use App\Services\Accounting\PayrollService;
use App\Services\Accounting\Reports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PayrollTest extends TestCase
{
    use RefreshDatabase;

    public function test_paye_uif_follow_2025_26_tables_and_finalising_posts_balanced_journal(): void
    {
        $this->seedReference();
        $staff = $this->makeAgent();
        PayrollProfile::create(['staff_id' => $staff->id, 'basic_salary' => 30000, 'date_of_birth' => '1990-01-01']);
        $svc = app(PayrollService::class);

        $run = $svc->calculate('2026-01', '2026-01-25');
        $slip = $run->payslips->first();
        // R360 000 a year: 42 678 + 26% × 122 900 = 74 632 − primary rebate 17 235 = 57 397 / 12
        $this->assertSame(4783.08, $slip->paye);
        $this->assertSame(177.12, $slip->uif_employee);   // 1% of the R17 712 ceiling
        $this->assertSame(0.0, $slip->sdl);               // payroll under R500 000 a year
        $this->assertSame(25039.80, $slip->net);

        $run = $svc->finalise($run, null);
        $this->assertSame('finalised', $run->status);
        $r = app(Reports::class);
        $this->assertSame(30177.12, $r->balance(Account::system('salaries')) + $r->balance(Account::system('employer_contributions')));
        $this->assertSame(4783.08, $r->balance(Account::system('paye_payable')));
        $this->assertSame(354.24, $r->balance(Account::system('uif_payable')));
        $this->assertSame(['period' => '2026-01', 'paye' => 4783.08, 'uif' => 354.24, 'sdl' => 0.0, 'total' => 5137.32, 'due_date' => '2026-02-07'], $svc->emp201($run));
    }

    public function test_medical_credits_and_age_rebates(): void
    {
        $this->seedReference();
        $t = PayrollTaxTable::first();
        $p = new PayrollProfile(['basic_salary' => 30000, 'medical_aid_members' => 3, 'date_of_birth' => '1955-06-01']);
        $slip = app(PayrollService::class)->payslip($p, $t, now(), false);
        // Age 70 at year end → primary + secondary rebate; 3 members → 364 × 2 + 246 credits.
        $this->assertSame(17235.0 + 9444.0, $slip['workings']['rebates']);
        $this->assertSame(974.0, $slip['workings']['medical_credits_monthly']);
        $this->assertSame(round((74632 - 26679 - 974 * 12) / 12, 2), $slip['paye']);
    }

    public function test_refuses_to_run_without_a_tax_table_for_the_period(): void
    {
        $this->seedReference();
        PayrollProfile::create(['staff_id' => $this->makeAgent()->id, 'basic_salary' => 20000]);
        $this->expectException(ValidationException::class);
        app(PayrollService::class)->calculate('2026-10', '2026-10-25'); // 2026/27 not loaded yet
    }
}
