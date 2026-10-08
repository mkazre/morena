<?php

namespace App\Services\Accounting;

use App\Models\Accounting\Account;
use App\Models\Accounting\BankReconciliation;
use App\Models\Accounting\BankTransaction;
use App\Models\Accounting\Bill;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\JournalLine;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class BankService
{
    public function __construct(private Ledger $ledger, private PaymentService $payments, private Reports $reports) {}

    /**
     * Imports a CSV bank statement. Columns are detected by header name, which covers the standard exports from
     * FNB, Standard Bank, Absa, Nedbank and Capitec: a date column, a description/narrative column, and either one
     * signed amount column or separate debit/credit columns. Already-imported lines are skipped.
     *
     * @return array{imported:int, skipped:int}
     */
    public function import(Account $bank, string $csv): array
    {
        $rows = array_values(array_filter(array_map(fn ($line) => str_getcsv($line), preg_split('/\r\n|\n|\r/', trim($csv))), fn ($r) => count(array_filter($r, fn ($c) => trim((string) $c) !== '')) > 1));
        $headerIdx = null;
        $map = [];
        foreach (array_slice($rows, 0, 15) as $i => $r) { // banks often put account info above the header row
            $m = $this->detectColumns($r);
            if (isset($m['date'], $m['description']) && (isset($m['amount']) || isset($m['debit']) || isset($m['credit']))) {
                $headerIdx = $i;
                $map = $m;

                break;
            }
        }
        if ($headerIdx === null) {
            throw ValidationException::withMessages(['file' => 'Could not find the header row. The CSV needs Date, Description and Amount (or Debit/Credit) columns.']);
        }

        $imported = 0;
        $skipped = 0;
        $seen = [];
        DB::transaction(function () use ($rows, $headerIdx, $map, $bank, &$imported, &$skipped, &$seen) {
            foreach (array_slice($rows, $headerIdx + 1) as $r) {
                $date = $this->parseDate($r[$map['date']] ?? '');
                if (! $date) {
                    continue; // totals / footer lines
                }
                $amount = isset($map['amount'])
                    ? $this->parseAmount($r[$map['amount']] ?? '')
                    : $this->parseAmount($r[$map['credit']] ?? '') - abs($this->parseAmount($r[$map['debit']] ?? ''));
                if (abs($amount) < 0.005) {
                    continue;
                }
                $desc = trim(preg_replace('/\s+/', ' ', (string) ($r[$map['description']] ?? '')));
                $ref = isset($map['reference']) ? trim((string) ($r[$map['reference']] ?? '')) : null;
                // Identical lines on the same day are legitimate (two R50 fees) — keep them apart by occurrence.
                $key = $bank->id.'|'.$date.'|'.number_format($amount, 2, '.', '').'|'.$desc.'|'.$ref;
                $seen[$key] = ($seen[$key] ?? 0) + 1;
                $hash = hash('sha256', $key.'|'.$seen[$key]);
                if (BankTransaction::where('import_hash', $hash)->exists()) {
                    $skipped++;

                    continue;
                }
                BankTransaction::create(['bank_account_id' => $bank->id, 'date' => $date, 'description' => $desc ?: '(no description)',
                    'reference' => $ref ?: null, 'amount' => $amount, 'import_hash' => $hash]);
                $imported++;
            }
        });

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /** Ledger lines on this bank account (same amount, ±10 days, not yet matched) + open documents of that amount. */
    public function suggestions(BankTransaction $t): array
    {
        $matched = BankTransaction::whereNotNull('journal_entry_id')->pluck('journal_entry_id');
        $side = $t->amount > 0 ? 'debit' : 'credit';
        $ledger = JournalLine::with('entry')
            ->join('journal_entries as je', 'je.id', '=', 'journal_lines.journal_entry_id')
            ->where('je.status', 'posted')->where('journal_lines.account_id', $t->bank_account_id)
            ->where("journal_lines.$side", abs($t->amount))
            ->whereBetween('je.date', [$t->date->copy()->subDays(10), $t->date->copy()->addDays(10)])
            ->whereNotIn('je.id', $matched)
            ->select('journal_lines.*')->limit(5)->get()
            ->map(fn ($l) => ['type' => 'journal', 'journal_entry_id' => $l->journal_entry_id, 'label' => $l->entry->number.' · '.($l->entry->memo ?? ''), 'date' => $l->entry->date->toDateString()]);

        $class = $t->amount > 0 ? Invoice::class : Bill::class;
        $docs = $class::with('contact')->whereIn('status', ['approved', 'partial'])->get()
            ->filter(fn ($d) => abs($d->balanceDue() - abs($t->amount)) < 0.005)->take(5)
            ->map(fn ($d) => ['type' => $t->amount > 0 ? 'invoice' : 'bill', 'id' => $d->id, 'label' => $d->number.' · '.$d->contact->name, 'date' => $d->date->toDateString()])
            ->values();

        return $ledger->concat($docs)->values()->all();
    }

    /** Link the statement line to an existing ledger entry. */
    public function match(BankTransaction $t, int $entryId): BankTransaction
    {
        $this->assertUnreconciled($t);
        $t->update(['journal_entry_id' => $entryId, 'status' => 'matched']);

        return $t;
    }

    /** Create the ledger entry from the statement line: pay an invoice/bill, or code it to an account. */
    public function create(BankTransaction $t, array $data, ?User $by): BankTransaction
    {
        $this->assertUnreconciled($t);

        return DB::transaction(function () use ($t, $data, $by) {
            $direction = $t->amount > 0 ? 'received' : 'made';
            $payload = ['direction' => $direction, 'bank_account_id' => $t->bank_account_id, 'date' => $t->date->toDateString(),
                'contact_id' => $data['contact_id'] ?? null, 'reference' => $t->reference ?: mb_substr($t->description, 0, 60), 'memo' => $t->description];
            if (! empty($data['document_id'])) {
                $payload['allocations'] = [['id' => $data['document_id'], 'amount' => abs($t->amount)]];
            } else {
                $payload['lines'] = [['account_id' => $data['account_id'], 'amount' => abs($t->amount), 'tax_rate_id' => $data['tax_rate_id'] ?? null, 'description' => $data['description'] ?? $t->description]];
            }
            $payment = $this->payments->record($payload, $by);
            $t->update(['journal_entry_id' => $payment->journal_entry_id, 'status' => 'matched']);

            return $t;
        });
    }

    public function unmatch(BankTransaction $t): BankTransaction
    {
        $this->assertUnreconciled($t);
        $t->update(['journal_entry_id' => null, 'status' => 'unmatched']);

        return $t;
    }

    /**
     * Locks in matched statement lines up to $date. The closing statement balance must equal the last reconciled
     * balance plus the lines being reconciled — i.e. every statement line in the period has been imported and matched.
     */
    public function reconcile(Account $bank, string $date, float $statementBalance, ?User $by): BankReconciliation
    {
        $last = BankReconciliation::where('bank_account_id', $bank->id)->latest('statement_date')->first();
        $pending = BankTransaction::where('bank_account_id', $bank->id)->where('status', 'matched')->where('date', '<=', $date);
        $unmatched = BankTransaction::where('bank_account_id', $bank->id)->where('status', 'unmatched')->where('date', '<=', $date)->count();
        if ($unmatched) {
            throw ValidationException::withMessages(['statement_date' => "$unmatched statement line(s) up to this date are still unmatched."]);
        }
        $opening = $last ? $last->statement_balance : $this->openingStatementBalance($bank);
        $expected = round($opening + (float) (clone $pending)->sum('amount'), 2);
        if (abs($expected - $statementBalance) >= 0.01) {
            throw ValidationException::withMessages(['statement_balance' => sprintf(
                'Out by R%s: opening R%s + matched lines = R%s, but the statement shows R%s. Import any missing statement lines.',
                number_format(abs($expected - $statementBalance), 2), number_format($opening, 2), number_format($expected, 2), number_format($statementBalance, 2)
            )]);
        }

        return DB::transaction(function () use ($bank, $date, $statementBalance, $pending, $by) {
            $rec = BankReconciliation::create([
                'bank_account_id' => $bank->id, 'statement_date' => $date, 'statement_balance' => $statementBalance,
                'ledger_balance' => $this->reports->balance($bank, $date), 'completed_by' => $by?->id,
            ]);
            (clone $pending)->update(['status' => 'reconciled', 'reconciliation_id' => $rec->id]);

            return $rec;
        });
    }

    /** Before the first reconciliation, the opening balance is whatever the ledger held before the first statement line. */
    private function openingStatementBalance(Account $bank): float
    {
        $first = BankTransaction::where('bank_account_id', $bank->id)->min('date');

        return $first ? $this->reports->balance($bank, Carbon::parse($first)->subDay()->toDateString()) : 0.0;
    }

    private function assertUnreconciled(BankTransaction $t): void
    {
        if ($t->status === 'reconciled') {
            throw ValidationException::withMessages(['status' => 'This line is already reconciled.']);
        }
    }

    private function detectColumns(array $header): array
    {
        $map = [];
        foreach ($header as $i => $h) {
            $h = strtolower(trim((string) $h));
            $field = match (true) {
                str_contains($h, 'date') && ! str_contains($h, 'value') => 'date',
                in_array($h, ['description', 'narrative', 'details', 'transaction description', 'description 1', 'transaction details'], true) => 'description',
                in_array($h, ['amount', 'amount (zar)', 'transaction amount'], true) => 'amount',
                in_array($h, ['debit', 'debits', 'debit amount', 'money out', 'withdrawals'], true) => 'debit',
                in_array($h, ['credit', 'credits', 'credit amount', 'money in', 'deposits'], true) => 'credit',
                in_array($h, ['reference', 'ref', 'cheque number', 'description 2'], true) => 'reference',
                default => null,
            };
            if ($field && ! isset($map[$field])) {
                $map[$field] = $i;
            }
        }

        return $map;
    }

    private function parseDate(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        foreach (['Y-m-d', 'Y/m/d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Ymd', 'd M Y', 'j M Y', 'd F Y', 'Y-m-d H:i:s'] as $f) {
            try {
                $d = Carbon::createFromFormat('!'.$f, $v);
                if ($d && $d->format($f) === $v || ($d && $f === 'Y-m-d H:i:s')) {
                    return $d->toDateString();
                }
            } catch (Throwable) {
            }
        }

        return null;
    }

    private function parseAmount(string $v): float
    {
        $v = trim(str_replace(['R', ' ', "\u{a0}"], '', $v));
        if ($v === '') {
            return 0.0;
        }
        $neg = str_starts_with($v, '-') || (str_starts_with($v, '(') && str_ends_with($v, ')')) || str_ends_with($v, 'Dr');
        $v = preg_replace('/[^0-9.,]/', '', $v);
        // "1 234,56" / "1.234,56" → decimal comma; otherwise commas are thousands separators.
        if (preg_match('/,\d{2}$/', $v)) {
            $v = str_replace(['.', ','], ['', '.'], $v);
        } else {
            $v = str_replace(',', '', $v);
        }

        return ($neg ? -1 : 1) * (float) $v;
    }
}
