<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientUploadLinkItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_required' => 'boolean'];
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(ClientUploadLink::class, 'link_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'uploaded_document_id');
    }
}
