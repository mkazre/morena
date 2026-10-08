<?php

namespace App\Models\Accounting;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

class AccountingSetting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lock_date' => 'date', 'auto_invoice_on_deal_close' => 'boolean'];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1]);
    }

    /** First day of the financial year containing $date (SA default: 1 March). */
    public function yearStart(DateTimeInterface $date): CarbonImmutable
    {
        $d = CarbonImmutable::instance($date)->startOfDay();
        $start = $d->setDate($d->year, $this->financial_year_start_month, 1);

        return $start->greaterThan($d) ? $start->subYear() : $start;
    }
}
