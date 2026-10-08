<?php

namespace App\Models\Accounting;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;

class PayrollTaxTable extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_on' => DateOnly::class, 'ends_on' => DateOnly::class, 'brackets' => 'array',
            'rebate_primary' => 'float', 'rebate_secondary' => 'float', 'rebate_tertiary' => 'float',
            'medical_credit_main' => 'float', 'medical_credit_additional' => 'float',
            'uif_rate' => 'float', 'uif_monthly_ceiling' => 'float', 'sdl_rate' => 'float', 'sdl_annual_threshold' => 'float',
            'retirement_cap_percent' => 'float', 'retirement_cap_annual' => 'float',
        ];
    }

    public static function forDate(string $date): ?self
    {
        return static::where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->first();
    }

    /** Annual tax on $income before rebates, from the bracket table [{from, rate, base}]. */
    public function annualTax(float $income): float
    {
        $tax = 0.0;
        foreach (collect($this->brackets)->sortBy('from') as $b) {
            if ($income > $b['from']) {
                $tax = $b['base'] + ($income - $b['from']) * $b['rate'] / 100;
            }
        }

        return round($tax, 2);
    }
}
