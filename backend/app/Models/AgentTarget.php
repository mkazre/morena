<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentTarget extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['value_target' => 'float'];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
