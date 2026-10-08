<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Document extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['retention_expires_at' => 'date'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'category_id');
    }

    public function linkedModel(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** "Deal: 14 Rivonia Views", "Client: Precious Nkosi" etc. for the "Linked to" column. */
    public function linkedLabel(): string
    {
        $m = $this->linkedModel;

        return match (true) {
            $m instanceof Deal => 'Deal: '.$m->property_title,
            $m instanceof Lead => 'Client: '.$m->name,
            $m instanceof Listing => 'Listing: '.$m->title,
            $m instanceof Staff => 'Agent: '.$m->user->name,
            $m instanceof Tenancy => 'Tenant: '.$m->tenant->name,
            $m instanceof ClientUploadLink => 'Client: '.$m->client_name,
            $m instanceof User => $m->name,
            default => '—',
        };
    }
}
