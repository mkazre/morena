<?php

namespace Tests\Feature;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingSetting;
use App\Models\Accounting\BankTransaction;
use App\Models\Accounting\Contact;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\TaxRate;
use App\Models\Deal;
use App\Models\PipelineStage;
use App\Services\Accounting\BankService;
use App\Services\Accounting\DocumentService;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\PaymentService;
use App\Services\Accounting\Reports;
use App\Services\DealService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountingTest extends TestCase
{
    use RefreshDatabase;

    private Contact $customer;

    private Contact $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        $this->customer = Contact::create(['name' => 'Seller Family', 'type' => 'customer']);
        $this->supplier = Contact::create(['name' => 'Property24', 'type' => 'supplier']);
    }

    private function acc(string $key): int
    {
        return Account::system($key)->id;
    }

    private function std(): int
    {
        return TaxRate::where('code', 'STD')->value('id');
    }

    private function invoice(float $price = 100000, string $date = '2026-09-10'): Invoice
    {
        $docs = app(DocumentService::class);
        $inv = $docs->save(new Invoice, ['contact_id' => $this->customer->id, 'date' => $date, 'due_date' => '2026-10-10',
            'lines' => [['account_id' => $this->acc('commission_income'), 'tax_rate_id' => $this->std(), 'description' => 'Commission', 'quantity' => 1, 'unit_price' => $price]]], null);

        return $docs->approve($inv, null);
    }

    public function test_invoice_posts_receivable_income_and_vat(): void
    {
        $inv = $this->invoice();
        $this->assertSame(115000.0, $inv->total);
        $this->assertSame(15000.0, $inv->vat_total);
        $this->assertStringStartsWith('INV-', $inv->number);

        $r = app(Reports::class);
        $this->assertSame(115000.0, $r->balance(Account::system('ar')));
        $this->assertSame(100000.0, $r->balance(Account::system('commission_income')));
        $this->assertSame(15000.0, $r->balance(Account::system('vat_output')));
    }

    public function test_partial_then_full_payment_updates_status_and_bank(): void
    {
        $inv = $this->invoice();
        $pay = app(PaymentService::class);
        $pay->record(['direction' => 'received', 'bank_account_id' => $this->acc('bank_main'), 'date' => '2026-09-20',
            'allocations' => [['id' => $inv->id, 'amount' => 15000]]], null);
        $this->assertSame('partial', $inv->fresh()->status);

        $p2 = $pay->record(['direction' => 'received', 'bank_account_id' => $this->acc('bank_main'), 'date' => '2026-09-25',
            'allocations' => [['id' => $inv->id, 'amount' => 100000]]], null);
        $this->assertSame('paid', $inv->fresh()->status);
        $this->assertSame($this->customer->id, $p2->contact_id);
        $this->assertSame(115000.0, app(Reports::class)->balance(Account::system('bank_main')));
        $this->assertSame(0.0, app(Reports::class)->balance(Account::system('ar')));

        // Overpaying is refused; voiding a payment re-opens the invoice.
        $this->expectException(ValidationException::class);
        try {
            $pay->record(['direction' => 'received', 'bank_account_id' => $this->acc('bank_main'), 'date' => '2026-09-26',
                'allocations' => [['id' => $inv->id, 'amount' => 1]]], null);
        } finally {
            $pay->void($p2);
            $this->assertSame('partial', $inv->fresh()->status);
        }
    }

    public function test_direct_expense_splits_vat_from_inclusive_amount(): void
    {
        app(PaymentService::class)->record(['direction' => 'made', 'bank_account_id' => $this->acc('bank_main'), 'date' => '2026-09-05',
            'contact_id' => $this->supplier->id,
            'lines' => [['account_id' => Account::where('code', '6000')->value('id'), 'amount' => 1150, 'tax_rate_id' => $this->std()]]], null);

        $r = app(Reports::class);
        $this->assertSame(1000.0, $r->balance(Account::where('code', '6000')->first()));
        $this->assertSame(150.0, $r->balance(Account::system('vat_input')));
        $this->assertSame(-1150.0, $r->balance(Account::system('bank_main')));
    }

    public function test_unbalanced_journal_is_rejected_and_lock_date_enforced(): void
    {
        $ledger = app(Ledger::class);
        try {
            $ledger->post(['date' => '2026-09-01'], [
                ['account_id' => $this->acc('bank_main'), 'debit' => 100],
                ['account_id' => $this->acc('commission_income'), 'credit' => 99.99],
            ]);
            $this->fail('Unbalanced journal accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('must be equal', $e->getMessage());
        }

        AccountingSetting::current()->update(['lock_date' => '2026-08-31']);
        $this->expectException(ValidationException::class);
        $ledger->post(['date' => '2026-08-15'], [
            ['account_id' => $this->acc('bank_main'), 'debit' => 100],
            ['account_id' => $this->acc('commission_income'), 'credit' => 100],
        ]);
    }

    public function test_statements_balance_and_cash_flow_reconciles(): void
    {
        $inv = $this->invoice(200000, '2026-03-15');
        $pay = app(PaymentService::class);
        $pay->record(['direction' => 'received', 'bank_account_id' => $this->acc('bank_main'), 'date' => '2026-04-01',
            'allocations' => [['id' => $inv->id, 'amount' => 230000]]], null);
        $pay->record(['direction' => 'made', 'bank_account_id' => $this->acc('bank_main'), 'date' => '2026-04-02',
            'lines' => [['account_id' => $this->acc('agent_commission'), 'amount' => 140000]]], null);
        $pay->record(['direction' => 'made', 'bank_account_id' => $this->acc('bank_main'), 'date' => '2026-04-03',
            'lines' => [['account_id' => Account::where('code', '1510')->value('id'), 'amount' => 23000, 'tax_rate_id' => $this->std()]]], null);
        // A prior-year sale lands in retained earnings, not current earnings (FY starts 1 March).
        $this->invoice(10000, '2026-02-10');

        $r = app(Reports::class);
        $tb = $r->trialBalance('2026-09-30');
        $this->assertSame($tb['total_debit'], $tb['total_credit']);

        $bs = $r->balanceSheet('2026-09-30');
        $this->assertTrue($bs['balanced'], json_encode($bs));
        $current = collect($bs['equity']['rows'])->firstWhere('name', 'Current year earnings')['amount'];
        $this->assertSame(60000.0, $current); // 200 000 income − 140 000 payouts
        $this->assertSame(10000.0, collect($bs['equity']['rows'])->firstWhere('name', 'Retained Earnings')['amount']);

        $pl = $r->profitAndLoss('2026-03-01', '2026-09-30');
        $this->assertSame(200000.0, $pl['income']['total']);
        $this->assertSame(60000.0, $pl['gross_profit']);
        $this->assertSame(60000.0, $pl['net_profit']);

        $cf = $r->cashFlow('2026-03-01', '2026-09-30');
        $this->assertSame(round($cf['closing_cash'] - $cf['opening_cash'], 2), $cf['net_change']);
        $this->assertSame(-20000.0, $cf['investing']['total']); // computer bought for R20 000 + VAT

        $vat = $r->vat('2026-03-01', '2026-04-30');
        $this->assertSame(30000.0, $vat['output_vat']);
        $this->assertSame(3000.0, $vat['input_vat']);
        $this->assertSame(27000.0, $vat['net_vat']);
        $this->assertSame(200000.0, $vat['supplies']['standard_excl']);
    }

    public function test_aging_buckets_overdue_invoices(): void
    {
        $this->invoice(1000, '2026-06-01'); // due 2026-10-10
        $aging = app(Reports::class)->aging('receivables', '2026-12-01');
        $this->assertSame(1150.0, $aging['totals']['d60']); // 52 days late
    }

    public function test_bank_import_dedupes_matches_and_reconciles(): void
    {
        $bank = Account::system('bank_main');
        $csv = "Account,62000000001\nDate,Description,Amount,Balance\n2026/09/01,Opening deposit,5000.00,5000.00\n2026/09/02,MONTHLY FEE,-65.00,4935.00\n";
        $svc = app(BankService::class);
        $this->assertSame(['imported' => 2, 'skipped' => 0], $svc->import($bank, $csv));
        $this->assertSame(['imported' => 0, 'skipped' => 2], $svc->import($bank, $csv));

        [$deposit, $fee] = BankTransaction::orderBy('date')->get()->all();
        $svc->create($deposit, ['account_id' => Account::system('opening_balance')->id], null);
        $svc->create($fee, ['account_id' => Account::system('bank_charges')->id, 'tax_rate_id' => $this->std()], null);
        $this->assertSame(4935.0, app(Reports::class)->balance($bank));

        try {
            $svc->reconcile($bank, '2026-09-30', 5000, null);
            $this->fail('Wrong statement balance accepted');
        } catch (ValidationException) {
        }
        $rec = $svc->reconcile($bank, '2026-09-30', 4935, null);
        $this->assertSame(4935.0, $rec->ledger_balance);
        $this->assertSame(2, BankTransaction::where('status', 'reconciled')->count());
    }

    public function test_closing_a_deal_raises_commission_invoice_and_payout_posts(): void
    {
        $agent = $this->makeAgent();
        $deals = app(DealService::class);
        $deal = $deals->create(['property_title' => '7 Test Rd', 'sale_value' => 1_000_000, 'seller_name' => 'J Seller', 'selling_agent_id' => $agent->id], null);
        while ($deal->fresh()->status !== 'closed') {
            $deals->advance($deal->fresh(), null);
        }
        $deals->update($deal->fresh(), ['buyer_name' => 'B'], null); // re-saving a closed deal must not duplicate the invoice

        $invoices = Invoice::where('deal_id', $deal->id)->get();
        $this->assertCount(1, $invoices);
        $this->assertSame(69000.0, $invoices[0]->total); // 6% + 15% VAT
        $this->assertSame('J Seller', $invoices[0]->contact->name);

        $this->actingAs($this->makeUser('admin'));
        $commission = $deal->fresh()->commission;
        $this->postJson("/api/v1/commissions/{$commission->id}/mark-paid")->assertOk();
        $je = JournalEntry::where('source_type', \App\Models\Commission::class)->first();
        $this->assertNotNull($je);
        $this->assertSame(24000.0, (float) $je->lines->sum('debit')); // junior 40% of 60 000
    }
}
