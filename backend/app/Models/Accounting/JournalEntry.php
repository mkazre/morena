<?php

namespace App\Models\Accounting;

use App\Casts\DateOnly;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class JournalEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => DateOnly::class];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePosted($q)
    {
        return $q->where('status', 'posted');
    }

    public function sourceLabel(): string
    {
        return match ($this->source_type) {
            Invoice::class => 'Invoice',
            Bill::class => 'Bill',
            Payment::class => 'Payment',
            BankTransaction::class => 'Bank transaction',
            PayrollRun::class => 'Payroll',
            null => $this->reverses_id ? 'Reversal' : 'Manual journal',
            default => class_basename($this->source_type),
        };
    }
}
