<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionSetting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'base_rate_percent' => 'float',
            'vat_registered' => 'boolean',
            'vat_rate' => 'float',
            'co_listing_split_default_percent' => 'float',
            'referral_fee_percent' => 'float',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1]);
    }
}
