<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OnboardingChecklist extends Model
{
    protected $guarded = ['id'];

    public const DEFAULT_TASKS = [
        'Contract signed', 'FICA documents', 'FFC registration', 'System access granted', 'Induction training',
    ];

    protected function casts(): array
    {
        return ['start_date' => 'date'];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(OnboardingTask::class, 'checklist_id')->orderBy('order');
    }
}
