<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Lead extends Model
{
    protected $guarded = ['id'];

    public const SOURCES = [
        'referral' => 'Referral', 'website' => 'Website', 'whatsapp' => 'WhatsApp',
        'walk_in' => 'Walk-in', 'portal' => 'Portals (Property24/P24)',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'assigned_agent_id');
    }

    public function deal(): HasOne
    {
        return $this->hasOne(Deal::class);
    }
}
