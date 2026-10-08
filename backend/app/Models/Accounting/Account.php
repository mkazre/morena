<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $guarded = ['id'];

    /** Debit-normal types increase with debits; the rest with credits. */
    public const DEBIT_NORMAL = ['asset', 'expense'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function isDebitNormal(): bool
    {
        return in_array($this->type, self::DEBIT_NORMAL, true);
    }

    /** Account a posting rule depends on, e.g. Account::system('ar'). */
    public static function system(string $key): self
    {
        return static::where('system_key', $key)->firstOrFail();
    }

    public function scopeBank($q)
    {
        return $q->where('subtype', 'bank');
    }
}
