<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    protected $guarded = ['id'];

    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }
}
