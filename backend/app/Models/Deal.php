<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Deal extends Model
{
    use \App\Models\Concerns\Audited;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sale_value' => 'decimal:2',
            'co_listing_split_percent' => 'decimal:2',
            'bond_required' => 'boolean',
            'expected_close_date' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'pipeline_stage_id');
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function listingAgent(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'listing_agent_id');
    }

    public function sellingAgent(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'selling_agent_id');
    }

    public function referralAgent(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'referral_agent_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }

    public function stageHistory(): HasMany
    {
        return $this->hasMany(DealStageHistory::class);
    }

    public function activity(): HasMany
    {
        return $this->hasMany(DealActivityLog::class)->latest('created_at');
    }

    public function commission(): HasOne
    {
        return $this->hasOne(Commission::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'linked_model');
    }

    /** The agent a deal is attributed to: selling agent, else listing agent. */
    public function primaryAgent(): ?Staff
    {
        return $this->sellingAgent ?? $this->listingAgent;
    }

    public function scopeForAgent(Builder $q, int $staffId): Builder
    {
        return $q->where(fn ($w) => $w->where('listing_agent_id', $staffId)->orWhere('selling_agent_id', $staffId));
    }

    public function scopeForBranch(Builder $q, int $branchId): Builder
    {
        return $q->where('branch_id', $branchId);
    }

    public function daysInStage(): int
    {
        $entered = $this->stageHistory->whereNull('exited_at')->sortByDesc('entered_at')->first()?->entered_at
            ?? $this->updated_at;

        return (int) $entered->diffInDays(now());
    }

    public function auditLabel(): string
    {
        return 'Deal '.$this->property_title;
    }
}
