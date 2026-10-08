<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A supplier invoice (accounts payable). */
class Bill extends Model
{
    use \App\Models\Concerns\Audited;

    use AccountingDocument;

    protected $guarded = ['id'];

    public function lines(): HasMany
    {
        return $this->hasMany(BillLine::class)->orderBy('position');
    }

    public function auditLabel(): string
    {
        return 'Bill '.$this->number;
    }
}
