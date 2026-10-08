<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Commission extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sale_value' => 'float',
            'commission_amount' => 'float',
            'vat_amount' => 'float',
            'agent_share' => 'float',
            'co_agent_share' => 'float',
            'referral_fee_amount' => 'float',
            'agency_share' => 'float',
            'paid_at' => 'datetime',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'agent_id');
    }

    public function coAgent(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'co_agent_id');
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(CommissionTier::class, 'commission_tier_id');
    }

    /** Commission including VAT — what the seller is invoiced. */
    public function grossAmount(): float
    {
        return $this->commission_amount + $this->vat_amount;
    }

    public function scopeInvolvingAgent(Builder $q, int $staffId): Builder
    {
        return $q->where(fn ($w) => $w->where('agent_id', $staffId)
            ->orWhere('co_agent_id', $staffId)
            ->orWhere('referral_agent_id', $staffId));
    }

    /** This agent's personal payout on the commission (primary, co-listing, or referral portion). */
    public function shareFor(int $staffId): float
    {
        return ($this->agent_id === $staffId ? $this->agent_share : 0)
            + ($this->co_agent_id === $staffId ? $this->co_agent_share : 0)
            + ($this->referral_agent_id === $staffId ? $this->referral_fee_amount : 0);
    }
}
