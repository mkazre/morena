<?php

namespace App\Services\Accounting;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingSetting;
use App\Models\Accounting\Contact;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\TaxRate;
use App\Models\Commission;
use App\Models\Deal;
use App\Models\Staff;
use App\Models\User;

/**
 * Posts CRM events into the books so sales and finance never disagree:
 *  - Deal reaches the final stage  → commission invoice to the seller (Dr Debtors / Cr Commission income / Cr VAT)
 *  - Agent commission marked paid  → payout journal (Dr Agent commission payouts / Cr Bank)
 */
class Automation
{
    public function __construct(private DocumentService $documents, private Ledger $ledger) {}

    public static function enabled(): bool
    {
        return Account::where('system_key', 'ar')->exists();
    }

    public function invoiceDealCommission(Deal $deal, ?User $by): ?Invoice
    {
        $settings = AccountingSetting::current();
        $commission = $deal->commission;
        if (! self::enabled() || ! $settings->auto_invoice_on_deal_close || ! $commission
            || Invoice::where('deal_id', $deal->id)->where('status', '!=', 'void')->exists()) {
            return null;
        }

        $contact = Contact::firstOrCreate(
            ['name' => $deal->seller_name ?: 'Seller — '.$deal->property_title, 'type' => 'customer'],
            ['email' => $deal->seller?->email, 'phone' => $deal->seller?->phone]
        );
        $rate = $commission->vat_amount > 0 ? TaxRate::where('code', 'STD')->first() : TaxRate::where('code', 'NONE')->first();
        $pct = $deal->sale_value > 0 ? round($commission->commission_amount / $deal->sale_value * 100, 2) : 0;

        $invoice = $this->documents->save(new Invoice, [
            'contact_id' => $contact->id, 'deal_id' => $deal->id,
            'date' => now()->toDateString(), 'due_date' => now()->addDays($settings->invoice_due_days)->toDateString(),
            'reference' => $deal->property_title,
            'notes' => 'Commission payable on registration of transfer.',
            'lines' => [[
                'account_id' => Account::system('commission_income')->id, 'tax_rate_id' => $rate?->id,
                'description' => "Sales commission — {$deal->property_title} (R".number_format($deal->sale_value)." @ {$pct}%)",
                'quantity' => 1, 'unit_price' => $commission->commission_amount,
            ]],
        ], $by);

        return $this->documents->approve($invoice, $by);
    }

    public function postCommissionPayout(Commission $commission, ?User $by): ?JournalEntry
    {
        $bankId = AccountingSetting::current()->default_bank_account_id;
        if (! self::enabled() || ! $bankId) {
            return null;
        }
        $expense = Account::system('agent_commission')->id;
        $lines = [];
        $total = 0;
        foreach ([[$commission->agent_id, $commission->agent_share], [$commission->co_agent_id, $commission->co_agent_share], [$commission->referral_agent_id, $commission->referral_fee_amount]] as [$staffId, $amount]) {
            if (! $staffId || $amount <= 0) {
                continue;
            }
            $lines[] = ['account_id' => $expense, 'contact_id' => $this->agentContact($staffId)->id, 'debit' => $amount,
                'description' => 'Commission payout — '.$commission->deal->property_title];
            $total += $amount;
        }
        if (! $lines) {
            return null;
        }
        $lines[] = ['account_id' => $bankId, 'credit' => round($total, 2), 'description' => 'Agent commission payout'];

        return $this->ledger->post(
            ['date' => now()->toDateString(), 'reference' => $commission->deal->property_title, 'memo' => 'Agent commission payout — '.$commission->deal->property_title],
            $lines, $commission, $by
        );
    }

    /** Agents are paid as independent contractors, so each has a supplier contact. */
    public function agentContact(int $staffId): Contact
    {
        $staff = Staff::with('user')->findOrFail($staffId);

        return Contact::firstOrCreate(['staff_id' => $staff->id], [
            'type' => 'supplier', 'name' => $staff->user->name, 'email' => $staff->user->email, 'phone' => $staff->user->phone,
        ]);
    }
}
