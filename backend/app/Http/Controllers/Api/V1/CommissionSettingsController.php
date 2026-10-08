<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CommissionSetting;
use App\Models\Deal;
use App\Services\CommissionService;
use Illuminate\Http\Request;

class CommissionSettingsController extends Controller
{
    public function show()
    {
        return CommissionSetting::current();
    }

    public function update(Request $request, CommissionService $commissions)
    {
        $data = $request->validate([
            'base_rate_percent' => ['sometimes', 'numeric', 'between:0,20'],
            'vat_registered' => ['sometimes', 'boolean'],
            'vat_rate' => ['sometimes', 'numeric', 'between:0,30'],
            'co_listing_split_default_percent' => ['sometimes', 'numeric', 'between:0,100'],
            'referral_fee_percent' => ['sometimes', 'numeric', 'between:0,100'],
        ]);
        $settings = CommissionSetting::current();
        $settings->update($data);

        // New rules apply to every commission not yet paid out.
        Deal::where('status', '!=', 'lost')->whereHas('commission', fn ($q) => $q->where('status', 'pending'))
            ->with(['commission', 'sellingAgent', 'listingAgent'])
            ->each(fn ($deal) => $commissions->calculateForDeal($deal));

        return $settings->fresh();
    }
}
