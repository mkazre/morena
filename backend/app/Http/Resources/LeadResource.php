<?php

namespace App\Http\Resources;

use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Lead */
class LeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'interest' => $this->interest_description,
            'source' => $this->source,
            'source_label' => Lead::SOURCES[$this->source] ?? $this->source,
            'agent' => $this->agent?->user?->name,
            'agent_id' => $this->assigned_agent_id,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
