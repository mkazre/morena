<?php

namespace App\Http\Resources;

use App\Models\Deal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Deal */
class DealResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $agent = $this->primaryAgent();
        $viewer = $request->user()?->staff?->id;

        return [
            'id' => $this->id,
            'property' => $this->property_title,
            'value' => (float) $this->sale_value,
            'stage_id' => $this->pipeline_stage_id,
            'stage' => $this->stage?->name,
            'stage_color' => $this->stage?->color_hex,
            'status' => $this->status,
            'agent' => $agent?->user?->name,
            'agent_id' => $agent?->id,
            'selling_agent_id' => $this->selling_agent_id,
            'listing_agent_id' => $this->listing_agent_id,
            'referral_agent_id' => $this->referral_agent_id,
            'branch_id' => $this->branch_id,
            'commission' => $this->commission?->grossAmount(),
            // The viewing agent's own payout on this deal (agent portal "My commission").
            'my_commission' => $viewer && $this->commission ? $this->commission->shareFor($viewer) : null,
            'expected_close_date' => $this->expected_close_date?->toDateString(),
            'days_in_stage' => $this->relationLoaded('stageHistory') ? $this->daysInStage() : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
