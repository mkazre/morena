<?php

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Document */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category?->name,
            'category_id' => $this->category_id,
            'linked_to' => $this->linkedLabel(),
            'version' => $this->version,
            'uploaded_at' => $this->created_at?->toIso8601String(),
            'download_url' => '/api/v1/documents/'.$this->id.'/download',
        ];
    }
}
