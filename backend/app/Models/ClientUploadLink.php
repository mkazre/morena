<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ClientUploadLink extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'last_sent_at' => 'datetime'];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'agent_id');
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ClientUploadLinkItem::class, 'link_id');
    }

    public static function newToken(): string
    {
        do {
            $token = Str::lower(Str::random(10));
        } while (static::where('token', $token)->exists());

        return $token;
    }

    /** Usable by the client: not expired, not already filed. */
    public function isOpen(): bool
    {
        return $this->status !== 'expired' && $this->status !== 'filed' && $this->expires_at->isFuture();
    }

    /** Lazily flips past-expiry links so listings always show the true state. */
    public function refreshExpiry(): self
    {
        if ($this->status === 'awaiting_client' && $this->expires_at->isPast()) {
            $this->update(['status' => 'expired']);
        }

        return $this;
    }
}
