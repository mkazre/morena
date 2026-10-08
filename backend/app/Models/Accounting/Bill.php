<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A supplier invoice (accounts payable). */
class Bill extends Model
{
    use AccountingDocument;

    protected $guarded = ['id'];

    public function lines(): HasMany
    {
        return $this->hasMany(BillLine::class)->orderBy('position');
    }
}
