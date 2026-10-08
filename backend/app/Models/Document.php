<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Document extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['retention_expires_at' => 'date', 'tags' => 'array'];
    }

    protected static function booted(): void
    {
        // Unfiled uploads land in the category's home folder (e.g. FICA → Compliance / FICA Records).
        static::creating(function (Document $d) {
            $d->folder_id ??= \Database\Seeders\RecordsFolderSeeder::homeFor($d->category_id)?->id;
        });
        // Every document starts its version history.
        static::created(function (Document $d) {
            $d->versions()->create([
                'version' => $d->version ?? 1, 'file_path' => $d->file_path, 'original_name' => $d->name,
                'size_bytes' => $d->size_bytes, 'uploaded_by' => $d->uploaded_by, 'created_at' => $d->created_at ?? now(),
            ]);
        });
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(DocumentFolder::class, 'folder_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version');
    }

    public function accessLog(): HasMany
    {
        return $this->hasMany(DocumentAccessLog::class)->latest('created_at');
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
