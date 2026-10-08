<?php

namespace App\Models\Accounting;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Template that raises an invoice or bill on a schedule (e.g. monthly office rent). */
class RecurringDocument extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lines' => 'array', 'next_run_on' => DateOnly::class, 'ends_on' => DateOnly::class, 'auto_approve' => 'boolean', 'is_active' => 'boolean'];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
