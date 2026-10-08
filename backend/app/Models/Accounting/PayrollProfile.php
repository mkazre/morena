<?php

namespace App\Models\Accounting;

use App\Casts\DateOnly;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollProfile extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'basic_salary' => 'float', 'taxable_allowances' => 'float', 'retirement_contribution' => 'float',
            'other_deductions' => 'float', 'date_of_birth' => DateOnly::class, 'uif_exempt' => 'boolean', 'is_active' => 'boolean',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
