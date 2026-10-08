<?php

namespace App\Models\Accounting;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A line from an imported bank statement, matched to the ledger during reconciliation. */
class BankTransaction extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => DateOnly::class, 'amount' => 'float'];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
