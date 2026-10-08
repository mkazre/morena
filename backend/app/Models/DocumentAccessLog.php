<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** POPIA: who opened, uploaded or changed a record, and when. */
class DocumentAccessLog extends Model
{
    const UPDATED_AT = null;

    protected $table = 'document_access_log';

    protected $guarded = ['id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public static function record(Document $doc, string $action, ?User $user, ?string $detail = null): void
    {
        static::create([
            'document_id' => $doc->id, 'user_id' => $user?->id, 'action' => $action, 'detail' => $detail,
            'ip' => request()?->ip(), 'created_at' => now(),
        ]);
    }
}
