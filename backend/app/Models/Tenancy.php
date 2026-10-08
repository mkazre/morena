<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Tenancy extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lease_start' => 'date', 'lease_end' => 'date'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_user_id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'linked_model');
    }
}
