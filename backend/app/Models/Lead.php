<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Lead extends Model
{
    use \App\Models\Concerns\Audited;

    protected $guarded = ['id'];

    public const SOURCES = [
        'referral' => 'Referral', 'website' => 'Website', 'whatsapp' => 'WhatsApp',
        'walk_in' => 'Walk-in', 'portal' => 'Portals (Property24/P24)',
    ];

    protected function casts(): array
    {
        return ['marketing_consent' => 'boolean', 'consent_recorded_at' => 'datetime', 'last_contacted_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (Lead $l) {
            if ($l->isDirty('marketing_consent') && $l->marketing_consent) {
                $l->consent_recorded_at ??= now();
            }
            if ($l->isDirty('status') && $l->status === 'contacted') {
                $l->last_contacted_at = now();
            }
            $l->score = \App\Services\LeadScoring::score($l);
        });
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'assigned_agent_id');
    }

    public function deal(): HasOne
    {
        return $this->hasOne(Deal::class);
    }

    public function auditLabel(): string
    {
        return 'Lead '.$this->name;
    }
}
