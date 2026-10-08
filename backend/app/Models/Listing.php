<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Listing extends Model
{
    protected $guarded = ['id'];

    public const PROPERTY_TYPES = [
        'house' => 'Freestanding house', 'sectional_title' => 'Sectional title / apartment',
        'townhouse' => 'Townhouse', 'vacant_land' => 'Vacant land', 'commercial' => 'Commercial',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'listed_at' => 'date',
            'mandate_expiry' => 'date',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'agent_id');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function documents()
    {
        return $this->morphMany(Document::class, 'linked_model');
    }
}
