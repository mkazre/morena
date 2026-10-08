<?php

namespace App\Http\Resources;

use App\Models\ClientUploadLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ClientUploadLink */
class UploadLinkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'token' => $this->token,
            'url' => url('/u/'.$this->token),
            'client' => $this->client_name,
            'agent' => $this->agent?->user?->name,
            'deal_id' => $this->deal_id,
            'doc_count' => $this->items_count ?? $this->items()->count(),
            'status' => $this->status,
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
