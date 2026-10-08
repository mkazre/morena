<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Deal;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every page's read endpoint returns 200 for its role against the full demo dataset. */
class EndpointSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_endpoint_responds_for_its_role(): void
    {
        $this->seedReference();
        $this->seed(DemoSeeder::class);
        $deal = Deal::where('property_title', '14 Rivonia Views')->first();
        $naledi = Staff::whereHas('user', fn ($u) => $u->where('name', 'Naledi Mokoena'))->first();

        $byRole = [
            'thandiwe@morenaproperties.co.za' => [
                '/api/v1/dashboard/admin', '/api/v1/dashboard/admin?period=last_month', '/api/v1/dashboard/admin?period=quarter',
                '/api/v1/dashboard/admin?period=ytd', '/api/v1/analytics?range=ytd', '/api/v1/analytics?range=quarter', '/api/v1/analytics?range=month', '/api/v1/analytics?range=12m&format=csv',
                '/api/v1/pipeline-stages', '/api/v1/deals', '/api/v1/deals?status=open&per_page=200', "/api/v1/deals/{$deal->id}",
                '/api/v1/leads', '/api/v1/listings', '/api/v1/commissions', '/api/v1/commission-settings', '/api/v1/commission-tiers',
                '/api/v1/staff', "/api/v1/staff/{$naledi->id}", '/api/v1/contracts', '/api/v1/leave-requests?status=pending', '/api/v1/leave-balances',
                '/api/v1/onboarding', '/api/v1/documents', '/api/v1/document-categories', '/api/v1/document-templates', '/api/v1/upload-links',
                '/api/v1/retention/summary', '/api/v1/retention/expiring?days=90', '/api/v1/courses', '/api/v1/courses/'.Course::first()->id,
                '/api/v1/certifications', '/api/v1/users', '/api/v1/settings', '/api/v1/branches',
                '/api/v1/finance/dashboard', '/api/v1/finance/settings', '/api/v1/finance/accounts', '/api/v1/finance/tax-rates',
                '/api/v1/finance/contacts', '/api/v1/finance/journals', '/api/v1/finance/invoices', '/api/v1/finance/invoices?status=overdue',
                '/api/v1/finance/bills', '/api/v1/finance/payments', '/api/v1/finance/bank-accounts', '/api/v1/finance/reconciliations',
                '/api/v1/finance/bank-transactions?bank_account_id='.\App\Models\Accounting\Account::system('bank_main')->id,
                '/api/v1/finance/invoices/'.\App\Models\Accounting\Invoice::value('id'), '/api/v1/finance/bills/'.\App\Models\Accounting\Bill::value('id'),
                '/api/v1/finance/journals/'.\App\Models\Accounting\JournalEntry::value('id'),
                '/api/v1/finance/reports/trial-balance', '/api/v1/finance/reports/balance-sheet', '/api/v1/finance/reports/profit-loss?compare=1',
                '/api/v1/finance/reports/cash-flow', '/api/v1/finance/reports/aged-receivables', '/api/v1/finance/reports/aged-payables',
                '/api/v1/finance/reports/vat', '/api/v1/finance/reports/trial-balance?format=csv',
                '/api/v1/finance/reports/general-ledger?account_id='.\App\Models\Accounting\Account::system('bank_main')->id,
                '/api/v1/finance/payroll/employees', '/api/v1/finance/payroll/tax-tables', '/api/v1/finance/payroll/runs',
                '/api/v1/finance/payroll/runs/'.\App\Models\Accounting\PayrollRun::value('id'),
                '/api/v1/finance/payroll/payslips/'.\App\Models\Accounting\Payslip::value('id'),
            ],
            'naledi.m@morenaproperties.co.za' => [
                '/api/v1/dashboard/agent', '/api/v1/deals', '/api/v1/leads', '/api/v1/commissions', '/api/v1/documents',
                '/api/v1/document-templates', '/api/v1/upload-links', '/api/v1/courses', "/api/v1/staff/{$naledi->id}",
            ],
            'kagiso.m@morenaproperties.co.za' => ['/api/v1/dashboard/manager', '/api/v1/leave-requests?status=pending', '/api/v1/deals?status=open'],
            'busisiwe.n@morenaproperties.co.za' => ['/api/v1/staff', '/api/v1/contracts', '/api/v1/leave-balances', '/api/v1/onboarding', '/api/v1/courses'],
            'abrahams@example.co.za' => ['/api/v1/dashboard/landlord', '/api/v1/documents'],
            'anita.s@morenaproperties.co.za' => ['/api/v1/finance/dashboard', '/api/v1/finance/reports/profit-loss', '/api/v1/finance/payroll/runs'],
            'r.dube@example.co.za' => ['/api/v1/dashboard/tenant', '/api/v1/documents'],
        ];

        foreach ($byRole as $email => $urls) {
            $this->actingAs(User::where('email', $email)->first());
            foreach ($urls as $url) {
                $res = $this->get($url, ['Accept' => 'application/json']);
                $this->assertSame(200, $res->status(), "$email → $url: ".substr($res->getContent(), 0, 300));
            }
        }

        // Finance is admin/finance only.
        $this->actingAs(User::where('email', 'naledi.m@morenaproperties.co.za')->first());
        $this->getJson('/api/v1/finance/dashboard')->assertForbidden();

        // Portal scoping on real data.
        $this->actingAs(User::where('email', 'abrahams@example.co.za')->first());
        $this->getJson('/api/v1/dashboard/landlord')->assertJsonPath('sale.property', '14 Rivonia Views');
        $this->getJson('/api/v1/documents')->assertJsonCount(3, 'data');
    }
}
