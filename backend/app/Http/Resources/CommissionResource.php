<?php

namespace App\Http\Resources;

use App\Models\Commission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Commission */
class CommissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user()?->staff?->id;
        $coListed = $this->co_agent_id !== null;

        return [
            'id' => $this->id,
            'deal_id' => $this->deal_id,
            'property' => $this->deal->property_title,
            'co_listed' => $coListed,
            // Commission is payable on registration of transfer (deal closed).
            'deal_closed' => $this->deal->status === 'closed',
            'agent' => $this->agent?->user?->name,
            'sale_value' => $this->sale_value,
            'commission_amount' => $this->commission_amount,
            'vat_amount' => $this->vat_amount,
            'gross_amount' => $this->grossAmount(),
            'agent_share' => $this->agent_share,
            'co_agent' => $this->coAgent?->user?->name,
            'co_agent_share' => $this->co_agent_share,
            'referral_fee_amount' => $this->referral_fee_amount,
            'agency_share' => $this->agency_share,
            'my_share' => $viewer ? $this->shareFor($viewer) : null,
            'tier' => $this->tier?->name,
            'status' => $this->status,
            'paid_at' => $this->paid_at?->toIso8601String(),
        ];
    }
}
