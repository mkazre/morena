<?php

namespace App\Models\Accounting;

use App\Models\Deal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use \App\Models\Concerns\Audited;

    use AccountingDocument;

    protected $guarded = ['id'];

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position');
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function auditLabel(): string
    {
        return 'Invoice '.$this->number;
    }
}
