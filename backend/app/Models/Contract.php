<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contract extends Model
{
    use \App\Models\Concerns\Audited;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'expiry_date' => 'date'];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function auditLabel(): string
    {
        return 'Contract #'.$this->id;
    }
}
