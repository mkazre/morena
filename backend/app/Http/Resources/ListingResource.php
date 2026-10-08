<?php

namespace App\Http\Resources;

use App\Models\Listing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin Listing */
class ListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'suburb' => $this->suburb,
            'price' => (float) $this->price,
            'status' => $this->status,
            'property_type' => $this->property_type,
            'beds' => $this->beds,
            'baths' => $this->baths,
            'agent' => $this->agent?->user?->name,
            'agent_id' => $this->agent_id,
            'photo_url' => $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null,
            'listed_at' => $this->listed_at?->toDateString(),
            'mandate_expiry' => $this->mandate_expiry?->toDateString(),
        ];
    }
}
