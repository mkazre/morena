<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankReconciliation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['statement_date' => 'date', 'statement_balance' => 'float', 'ledger_balance' => 'float'];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }
}
