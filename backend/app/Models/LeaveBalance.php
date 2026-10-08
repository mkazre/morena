<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalance extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['annual_days' => 'float', 'sick_days' => 'float', 'taken_days' => 'float'];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
