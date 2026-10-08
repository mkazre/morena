<?php

namespace App\Services\Accounting;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingSetting;
use App\Models\Accounting\Bill;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\TaxRate;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Invoices (receivables) and bills (payables). Drafts don't touch the ledger; approving posts:
 *   Invoice: Dr Accounts Receivable (total) / Cr income lines (net) / Cr VAT Output
 *   Bill:    Dr expense lines (net) / Dr VAT Input / Cr Accounts Payable (total)
 */
class DocumentService
{
    public function __construct(private Ledger $ledger) {}

    /** @param Invoice|Bill $doc  (new or existing) */
    public function save(Model $doc, array $data, ?User $by): Model
    {
        if ($doc->exists && in_array($doc->status, ['paid', 'void'], true)) {
            throw ValidationException::withMessages(['status' => 'Paid or voided documents can no longer be edited.']);
        }
        $rates = TaxRate::all()->keyBy('id');
        $lines = [];
        $subtotal = 0;
        $vat = 0;
        foreach (array_values($data['lines']) as $i => $l) {
            $net = Ledger::cents(round((float) $l['quantity'] * (float) $l['unit_price'], 2));
            $rate = $rates->get($l['tax_rate_id'] ?? 0)?->rate ?? 0;
            $tax = (int) round($net * $rate / 100);
            $subtotal += $net;
            $vat += $tax;
            $lines[] = [
                'account_id' => $l['account_id'], 'tax_rate_id' => $l['tax_rate_id'] ?? null,
                'description' => $l['description'], 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'],
                'line_total' => $net / 100, 'vat_amount' => $tax / 100, 'position' => $i,
            ];
        }
        $total = ($subtotal + $vat) / 100;
        if ($doc->exists && $total + 0.004 < $doc->amount_paid) {
            throw ValidationException::withMessages(['lines' => 'The new total is less than what has already been paid (R'.number_format($doc->amount_paid, 2).').']);
        }

        return DB::transaction(function () use ($doc, $data, $lines, $subtotal, $vat, $total, $by) {
            $wasPosted = $doc->exists && $doc->status !== 'draft';
            $doc->fill([
                'contact_id' => $data['contact_id'], 'date' => $data['date'], 'due_date' => $data['due_date'],
                'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null,
                'subtotal' => $subtotal / 100, 'vat_total' => $vat / 100, 'total' => $total,
            ]);
            if ($doc instanceof Invoice && array_key_exists('deal_id', $data)) {
                $doc->deal_id = $data['deal_id'];
            }
            if (! $doc->exists) {
                $doc->created_by = $by?->id;
                $doc->status = 'draft';
            }
            $doc->save();
            if (! $doc->number) {
                $prefix = $doc instanceof Invoice ? AccountingSetting::current()->invoice_prefix : 'BILL-';
                $doc->update(['number' => $prefix.str_pad((string) $doc->id, 5, '0', STR_PAD_LEFT)]);
            }
            $doc->lines()->delete();
            $doc->lines()->createMany($lines);

            // Editing an approved document re-posts it so the ledger always matches the document.
            if ($wasPosted) {
                if ($doc->journal) {
                    $this->ledger->void($doc->journal);
                }
                $this->postDocument($doc->fresh('lines'), $by);
                $doc->refreshPaymentStatus();
            }

            return $doc->fresh(['lines', 'contact']);
        });
    }

    public function approve(Model $doc, ?User $by): Model
    {
        if ($doc->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only drafts can be approved.']);
        }

        return DB::transaction(function () use ($doc, $by) {
            $this->postDocument($doc->load('lines'), $by);
            $doc->update(['status' => 'approved']);
            $doc->refreshPaymentStatus();

            return $doc->fresh(['lines', 'contact']);
        });
    }

    public function void(Model $doc): Model
    {
        if ($doc->amount_paid > 0) {
            throw ValidationException::withMessages(['status' => 'Void the payments on this document first.']);
        }

        return DB::transaction(function () use ($doc) {
            if ($doc->journal) {
                $this->ledger->void($doc->journal);
            }
            $doc->update(['status' => 'void']);

            return $doc->fresh(['lines', 'contact']);
        });
    }

    private function postDocument(Model $doc, ?User $by): void
    {
        $isInvoice = $doc instanceof Invoice;
        $control = Account::system($isInvoice ? 'ar' : 'ap');
        $vatAccount = Account::system($isInvoice ? 'vat_output' : 'vat_input');
        $side = $isInvoice ? 'credit' : 'debit';   // side the document's lines go on
        $other = $isInvoice ? 'debit' : 'credit';

        $lines = [[
            'account_id' => $control->id, 'contact_id' => $doc->contact_id, $other => $doc->total,
            'description' => ($isInvoice ? 'Invoice ' : 'Bill ').$doc->number,
        ]];
        foreach ($doc->lines as $l) {
            $lines[] = ['account_id' => $l->account_id, 'contact_id' => $doc->contact_id, 'tax_rate_id' => $l->tax_rate_id,
                'description' => $l->description, $side => $l->line_total];
        }
        // One VAT line per rate so the VAT report can show the base each rate applied to.
        foreach ($doc->lines->where('vat_amount', '>', 0)->groupBy('tax_rate_id') as $rateId => $group) {
            $lines[] = ['account_id' => $vatAccount->id, 'contact_id' => $doc->contact_id, 'tax_rate_id' => $rateId,
                'description' => 'VAT', $side => round($group->sum('vat_amount'), 2), 'tax_base' => round($group->sum('line_total'), 2)];
        }

        $entry = $this->ledger->post(
            ['date' => $doc->date, 'reference' => $doc->number, 'memo' => ($isInvoice ? 'Invoice ' : 'Bill ').$doc->number.' — '.$doc->contact->name],
            $lines, $doc, $by
        );
        $doc->update(['journal_entry_id' => $entry->id]);
    }

    public static function modelFor(string $kind): string
    {
        return $kind === 'bill' ? Bill::class : Invoice::class;
    }
}
