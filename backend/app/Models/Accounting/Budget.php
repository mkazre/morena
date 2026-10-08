<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Budget extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'float'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
