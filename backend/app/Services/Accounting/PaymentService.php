<?php

namespace App\Services\Accounting;

use App\Models\Accounting\Account;
use App\Models\Accounting\Bill;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\Payment;
use App\Models\Accounting\TaxRate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Money in and out of bank accounts.
 *  - Against documents:  received → Dr Bank / Cr AR;   made → Dr AP / Cr Bank
 *  - Direct ("spend / receive money", e.g. bank charges, sundry income): lines are coded straight to accounts,
 *    VAT-inclusive amounts are split into net + VAT.
 */
class PaymentService
{
    public function __construct(private Ledger $ledger) {}

    /**
     * @param  array{direction:string, bank_account_id:int, date:string, contact_id?:?int, method?:string, reference?:?string,
     *               allocations?:array<int,array{type:string,id:int,amount:float}>,
     *               lines?:array<int,array{account_id:int,amount:float,tax_rate_id?:?int,description?:?string}>}  $data
     */
    public function record(array $data, ?User $by): Payment
    {
        $bank = Account::findOrFail($data['bank_account_id']);
        if ($bank->subtype !== 'bank') {
            throw ValidationException::withMessages(['bank_account_id' => 'Choose a bank, cash or card account.']);
        }
        $received = $data['direction'] === 'received';

        return DB::transaction(function () use ($data, $bank, $received, $by) {
            [$amount, $lines, $docs, $contactId] = ! empty($data['allocations'])
                ? $this->allocationLines($data, $received)
                : $this->directLines($data, $received);

            $payment = Payment::create([
                'direction' => $data['direction'], 'contact_id' => $contactId, 'bank_account_id' => $bank->id,
                'date' => $data['date'], 'amount' => $amount, 'method' => $data['method'] ?? 'eft',
                'reference' => $data['reference'] ?? null, 'created_by' => $by?->id,
            ]);
            $payment->update(['number' => ($received ? 'RCT-' : 'PAY-').str_pad((string) $payment->id, 5, '0', STR_PAD_LEFT)]);

            $lines[] = ['account_id' => $bank->id, 'contact_id' => $payment->contact_id,
                'description' => ($received ? 'Received ' : 'Paid ').($data['reference'] ?? ''), ($received ? 'debit' : 'credit') => $amount];
            $entry = $this->ledger->post(
                ['date' => $payment->date, 'reference' => $payment->number, 'memo' => $data['memo'] ?? (($received ? 'Receipt ' : 'Payment ').$payment->number)],
                $lines, $payment, $by
            );
            $payment->update(['journal_entry_id' => $entry->id]);

            foreach ($docs as [$doc, $amt]) {
                $payment->allocations()->create(['document_type' => $doc::class, 'document_id' => $doc->id, 'amount' => $amt]);
                $doc->refreshPaymentStatus();
            }

            return $payment->load('allocations');
        });
    }

    public function void(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment) {
            if ($payment->journal) {
                $this->ledger->void($payment->journal);
            }
            $payment->update(['status' => 'void']);
            foreach ($payment->allocations()->with('document')->get() as $a) {
                $a->document?->refreshPaymentStatus();
            }

            return $payment;
        });
    }

    private function allocationLines(array $data, bool $received): array
    {
        $control = Account::system($received ? 'ar' : 'ap');
        $class = $received ? Invoice::class : Bill::class;
        $lines = [];
        $docs = [];
        $total = 0;
        foreach ($data['allocations'] as $i => $a) {
            $doc = $class::findOrFail($a['id']);
            $amt = round((float) $a['amount'], 2);
            if (! in_array($doc->status, ['approved', 'partial'], true)) {
                throw ValidationException::withMessages(["allocations.$i" => "{$doc->number} is not awaiting payment."]);
            }
            if ($amt <= 0 || $amt > $doc->balanceDue() + 0.004) {
                throw ValidationException::withMessages(["allocations.$i" => "{$doc->number} only has R".number_format($doc->balanceDue(), 2).' outstanding.']);
            }
            if (! empty($data['contact_id']) && (int) $data['contact_id'] !== $doc->contact_id) {
                throw ValidationException::withMessages(["allocations.$i" => "{$doc->number} belongs to a different contact."]);
            }
            $data['contact_id'] ??= $doc->contact_id;
            $lines[] = ['account_id' => $control->id, 'contact_id' => $doc->contact_id, 'description' => $doc->number,
                ($received ? 'credit' : 'debit') => $amt];
            $docs[] = [$doc, $amt];
            $total += Ledger::cents($amt);
        }

        return [$total / 100, $lines, $docs, $data['contact_id'] ?? null];
    }

    private function directLines(array $data, bool $received): array
    {
        if (empty($data['lines'])) {
            throw ValidationException::withMessages(['lines' => 'Add at least one line, or choose invoices/bills to pay.']);
        }
        $rates = TaxRate::all()->keyBy('id');
        $vatAccount = Account::system($received ? 'vat_output' : 'vat_input');
        $side = $received ? 'credit' : 'debit';
        $lines = [];
        $total = 0;
        foreach ($data['lines'] as $l) {
            $gross = Ledger::cents($l['amount']);
            $rate = $rates->get($l['tax_rate_id'] ?? 0)?->rate ?? 0;
            $vat = $rate > 0 ? (int) round($gross * $rate / (100 + $rate)) : 0; // amounts entered VAT-inclusive
            $lines[] = ['account_id' => $l['account_id'], 'contact_id' => $data['contact_id'] ?? null, 'tax_rate_id' => $l['tax_rate_id'] ?? null,
                'description' => $l['description'] ?? null, $side => ($gross - $vat) / 100];
            if ($vat) {
                $lines[] = ['account_id' => $vatAccount->id, 'contact_id' => $data['contact_id'] ?? null, 'tax_rate_id' => $l['tax_rate_id'],
                    'description' => 'VAT', $side => $vat / 100, 'tax_base' => ($gross - $vat) / 100];
            }
            $total += $gross;
        }

        return [$total / 100, $lines, [], $data['contact_id'] ?? null];
    }
}
