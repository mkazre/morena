<?php

namespace App\Services\Accounting;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingSetting;
use App\Models\Accounting\JournalEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only place journal entries are written. Enforces double-entry (debits = credits to the cent),
 * active accounts, and the lock date. Every other accounting service posts through here.
 */
class Ledger
{
    /**
     * @param  array{date:string|\DateTimeInterface, reference?:?string, memo?:?string}  $header
     * @param  array<int, array{account_id:int, debit?:float, credit?:float, description?:?string, contact_id?:?int, tax_rate_id?:?int, tax_base?:?float}>  $lines
     */
    public function post(array $header, array $lines, ?Model $source = null, ?User $by = null): JournalEntry
    {
        $date = Carbon::parse($header['date'])->startOfDay();
        $this->assertOpen($date);
        $lines = $this->normalise($lines);

        return DB::transaction(function () use ($header, $lines, $date, $source, $by) {
            $entry = JournalEntry::create([
                'date' => $date->toDateString(),
                'reference' => $header['reference'] ?? null,
                'memo' => $header['memo'] ?? null,
                'source_type' => $source ? $source::class : null,
                'source_id' => $source?->getKey(),
                'reverses_id' => $header['reverses_id'] ?? null,
                'created_by' => $by?->id,
            ]);
            $entry->update(['number' => 'JE-'.str_pad((string) $entry->id, 6, '0', STR_PAD_LEFT)]);
            $entry->lines()->createMany($lines);

            return $entry->load('lines');
        });
    }

    /** Voided entries stay in the audit trail but drop out of every balance and report. */
    public function void(JournalEntry $entry): void
    {
        if ($entry->status === 'void') {
            return;
        }
        $this->assertOpen($entry->date);
        $entry->update(['status' => 'void']);
    }

    /** Posts the mirror image of $entry on $date (e.g. reversing an accrual next period). */
    public function reverse(JournalEntry $entry, string $date, ?User $by = null): JournalEntry
    {
        abort_if($entry->status !== 'posted', 422, 'Only posted entries can be reversed.');
        $lines = $entry->lines->map(fn ($l) => [
            'account_id' => $l->account_id, 'contact_id' => $l->contact_id, 'tax_rate_id' => $l->tax_rate_id,
            'description' => $l->description, 'debit' => $l->credit, 'credit' => $l->debit,
            'tax_base' => $l->tax_base !== null ? -$l->tax_base : null,
        ])->all();

        return $this->post([
            'date' => $date, 'reference' => $entry->number, 'memo' => 'Reversal of '.$entry->number.($entry->memo ? ' — '.$entry->memo : ''),
            'reverses_id' => $entry->id,
        ], $lines, null, $by);
    }

    public function assertOpen(\DateTimeInterface $date): void
    {
        $lock = AccountingSetting::current()->lock_date;
        if ($lock && Carbon::instance($date)->lte($lock)) {
            throw ValidationException::withMessages([
                'date' => 'The books are locked up to '.$lock->format('j M Y').'. Use a later date or ask an administrator to move the lock date.',
            ]);
        }
    }

    /** Rounds, nets debit/credit per line, drops zero lines and checks the entry balances. */
    private function normalise(array $lines): array
    {
        $out = [];
        $dr = 0;
        $cr = 0;
        $accounts = Account::whereIn('id', array_column($lines, 'account_id'))->get()->keyBy('id');

        foreach ($lines as $i => $l) {
            $account = $accounts->get($l['account_id'] ?? 0);
            if (! $account) {
                throw ValidationException::withMessages(["lines.$i.account_id" => 'Choose an account for every line.']);
            }
            if (! $account->is_active) {
                throw ValidationException::withMessages(["lines.$i.account_id" => "Account {$account->code} {$account->name} is archived."]);
            }
            $net = self::cents($l['debit'] ?? 0) - self::cents($l['credit'] ?? 0);
            if ($net === 0) {
                continue;
            }
            $dr += max($net, 0);
            $cr += max(-$net, 0);
            $out[] = [
                'account_id' => $account->id,
                'contact_id' => $l['contact_id'] ?? null,
                'tax_rate_id' => $l['tax_rate_id'] ?? null,
                'description' => $l['description'] ?? null,
                'debit' => max($net, 0) / 100,
                'credit' => max(-$net, 0) / 100,
                'tax_base' => isset($l['tax_base']) ? round((float) $l['tax_base'], 2) : null,
            ];
        }

        if (count($out) < 2) {
            throw ValidationException::withMessages(['lines' => 'A journal needs at least one debit and one credit line.']);
        }
        if ($dr !== $cr) {
            throw ValidationException::withMessages(['lines' => sprintf(
                'Debits (R%s) and credits (R%s) must be equal — difference R%s.',
                number_format($dr / 100, 2), number_format($cr / 100, 2), number_format(abs($dr - $cr) / 100, 2)
            )]);
        }

        return $out;
    }

    public static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
