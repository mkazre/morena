<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use \App\Models\Concerns\Audited;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payprop_connected' => 'boolean', 'whatsapp_connected' => 'boolean'];
    }

    public static function current(): self
    {
        $s = static::query()->firstOrCreate(['id' => 1]);

        // A just-created row has no DB defaults loaded yet.
        return $s->wasRecentlyCreated ? $s->fresh() : $s;
    }
}
