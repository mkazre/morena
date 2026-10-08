<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    use \App\Models\Concerns\Audited;

    protected $guarded = ['id'];

    public const TYPES = ['annual' => 'Annual Leave', 'sick' => 'Sick Leave', 'other' => 'Other Leave'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'days' => 'float',
            'decided_at' => 'datetime',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function auditLabel(): string
    {
        return 'Leave request #'.$this->id;
    }
}
