<?php

namespace App\Models\Accounting;

use App\Models\Staff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payslip extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'basic_salary' => 'float', 'allowances' => 'float', 'gross' => 'float', 'retirement' => 'float', 'paye' => 'float',
            'uif_employee' => 'float', 'uif_employer' => 'float', 'sdl' => 'float', 'other_deductions' => 'float', 'net' => 'float',
            'workings' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
