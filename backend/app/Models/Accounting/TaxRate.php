<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

class TaxRate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rate' => 'float', 'is_default' => 'boolean'];
    }
}
