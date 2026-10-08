<?php

namespace App\Models\Accounting;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    use \App\Models\Concerns\Audited;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'pay_date' => DateOnly::class, 'total_gross' => 'float', 'total_paye' => 'float', 'total_uif' => 'float',
            'total_sdl' => 'float', 'total_net' => 'float',
        ];
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function taxTable(): BelongsTo
    {
        return $this->belongsTo(PayrollTaxTable::class, 'tax_table_id');
    }
}
