<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** System-wide "who changed what". Written by the Audited trait and auth events. */
class AuditLog extends Model
{
    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function write(string $event, ?string $label = null, ?\Illuminate\Database\Eloquent\Model $subject = null, ?array $changes = null): void
    {
        static::create([
            'user_id' => auth()->id(), 'event' => $event, 'label' => $label,
            'subject_type' => $subject ? $subject::class : null, 'subject_id' => $subject?->getKey(),
            'changes' => $changes, 'ip' => request()?->ip(), 'created_at' => now(),
        ]);
    }
}
