<?php

namespace App\Casts;

use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores calendar dates as "YYYY-MM-DD" on every driver. Laravel's built-in "date" cast writes
 * "YYYY-MM-DD 00:00:00", which on SQLite makes `date <= '2026-02-28'` skip that day's rows —
 * fatal for period-based accounting reports.
 */
class DateOnly implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value)->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null || $value === '' ? null : Carbon::parse($value)->toDateString();
    }
}
