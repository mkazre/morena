<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\CommissionTier;
use App\Models\Deal;
use App\Models\Staff;

/**
 * Handover §6 — commission is always calculated and persisted here; the UI only displays stored values.
 */
class CommissionService
{
    public function calculateForDeal(Deal $deal): ?Commission
    {
        $existing = $deal->commission;
        if ($existing?->status === 'paid' || $deal->status === 'lost') {
            return $existing; // never rewrite a paid commission; lost deals earn nothing
        }

        $agent = $deal->primaryAgent();
        if (! $agent) {
            return null;
        }

        $settings = CommissionSetting::current();
        $sale = (float) $deal->sale_value;

        // 1. Base commission on the sale price.
        $commission = round($sale * $settings->base_rate_percent / 100, 2);

        // 2. VAT on top, if the agency is a VAT vendor.
        $vat = $settings->vat_registered ? round($commission * $settings->vat_rate / 100, 2) : 0.0;

        // 3. Agent tier from trailing-12-month performance.
        $tier = $this->resolveTier($agent);
        $splitPct = $tier?->agent_split_percent ?? 50.0;

        // 4. Agent / agency split.
        $agentTotal = round($commission * $splitPct / 100, 2);
        $agency = round($commission - $agentTotal, 2);

        // 6. Referral fee comes off the agent's share before payout.
        $referralFee = 0.0;
        if ($deal->referral_agent_id) {
            $referralFee = round($agentTotal * $settings->referral_fee_percent / 100, 2);
        }
        $payable = $agentTotal - $referralFee;

        // 5. Co-listing: listing agent takes co_listing_split % of the payable share, selling agent the rest.
        $coAgentId = null;
        $coShare = 0.0;
        if ($deal->listing_agent_id && $deal->selling_agent_id && $deal->listing_agent_id !== $deal->selling_agent_id) {
            $coPct = $deal->co_listing_split_percent ?? $settings->co_listing_split_default_percent;
            $coAgentId = $deal->listing_agent_id;
            $coShare = round($payable * $coPct / 100, 2);
        }

        return Commission::updateOrCreate(['deal_id' => $deal->id], [
            'agent_id' => $agent->id,
            'sale_value' => $sale,
            'commission_amount' => $commission,
            'vat_amount' => $vat,
            'agent_share' => round($payable - $coShare, 2),
            'co_agent_id' => $coAgentId,
            'co_agent_share' => $coShare,
            'referral_agent_id' => $deal->referral_agent_id,
            'referral_fee_amount' => $referralFee,
            'agency_share' => $agency,
            'commission_tier_id' => $tier?->id,
            'status' => $existing->status ?? 'pending',
        ]);
    }

    /**
     * Highest tier (by position) whose deal-count OR volume threshold the agent meets.
     * A tier with neither threshold set is the entry tier everyone qualifies for.
     */
    public function resolveTier(Staff $agent): ?CommissionTier
    {
        $closed = Deal::forAgent($agent->id)
            ->where('status', 'closed')
            ->where('closed_at', '>=', now()->subMonths(12));
        $count = (clone $closed)->count();
        $volume = (float) (clone $closed)->sum('sale_value');

        return CommissionTier::orderByDesc('position')->get()->first(function (CommissionTier $t) use ($count, $volume) {
            if ($t->min_deals === null && $t->min_volume === null) {
                return true;
            }

            return ($t->min_deals !== null && $count >= $t->min_deals)
                || ($t->min_volume !== null && $volume >= $t->min_volume);
        });
    }
}
