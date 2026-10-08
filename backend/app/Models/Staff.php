<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Staff extends Model
{
    use \App\Models\Concerns\Audited;

    protected $table = 'staff';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'ffc_expiry' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function listingDeals(): HasMany
    {
        return $this->hasMany(Deal::class, 'listing_agent_id');
    }

    public function sellingDeals(): HasMany
    {
        return $this->hasMany(Deal::class, 'selling_agent_id');
    }

    /** Deals where this agent is listing or selling agent. */
    public function deals()
    {
        return Deal::query()->where(fn ($q) => $q->where('listing_agent_id', $this->id)->orWhere('selling_agent_id', $this->id));
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'assigned_agent_id');
    }

    public function certification(): HasOne
    {
        return $this->hasOne(Certification::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function leaveBalance(): HasOne
    {
        return $this->hasOne(LeaveBalance::class)->where('year', now()->year);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function onboardingChecklist(): HasOne
    {
        return $this->hasOne(OnboardingChecklist::class);
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_completions')
            ->withPivot('status', 'completed_at')
            ->withTimestamps();
    }

    public function documents()
    {
        return $this->morphMany(Document::class, 'linked_model');
    }

    public function isAgent(): bool
    {
        return $this->department === 'sales';
    }

    public function auditLabel(): string
    {
        return 'Staff '.($this->user?->name ?? '#'.$this->id);
    }
}
