<?php

namespace Tests\Feature;

use App\Models\CommissionSetting;
use App\Models\Deal;
use App\Models\PipelineStage;
use App\Services\CommissionService;
use App\Services\DealService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Handover §6 commission rules. */
class CommissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference(); // 6% base, VAT 15%, tiers 40/55/70, co-list 50%, referral 10%
    }

    private function closedDeals(int $agentId, int $n, int $value = 1_000_000): void
    {
        for ($i = 0; $i < $n; $i++) {
            Deal::create(['property_title' => "Old $i", 'sale_value' => $value, 'pipeline_stage_id' => PipelineStage::finalStage()->id,
                'selling_agent_id' => $agentId, 'listing_agent_id' => $agentId, 'status' => 'closed', 'closed_at' => now()->subMonths(2)]);
        }
    }

    public function test_junior_agent_gets_base_split_with_vat_on_top(): void
    {
        $agent = $this->makeAgent();
        $deal = app(DealService::class)->create(['property_title' => '1 Test St', 'sale_value' => 2_000_000,
            'selling_agent_id' => $agent->id, 'listing_agent_id' => $agent->id], null);

        $c = $deal->fresh()->commission;
        $this->assertSame(120000.0, $c->commission_amount);   // 6%
        $this->assertSame(18000.0, $c->vat_amount);           // 15% on commission
        $this->assertSame(48000.0, $c->agent_share);          // junior 40%
        $this->assertSame(72000.0, $c->agency_share);
        $this->assertSame('pending', $c->status);
    }

    public function test_tier_resolves_from_trailing_twelve_months(): void
    {
        $agent = $this->makeAgent();
        $this->closedDeals($agent->id, 5);
        $this->assertSame('Experienced agent', app(CommissionService::class)->resolveTier($agent)->name);

        $this->closedDeals($agent->id, 5);
        $this->assertSame('Senior / top agent', app(CommissionService::class)->resolveTier($agent)->name);
    }

    public function test_volume_threshold_also_qualifies(): void
    {
        $agent = $this->makeAgent();
        $this->closedDeals($agent->id, 2, 6_000_000); // R12m volume
        $this->assertSame('Senior / top agent', app(CommissionService::class)->resolveTier($agent)->name);
    }

    public function test_co_listing_and_referral_split(): void
    {
        $seller = $this->makeAgent(name: 'Seller');
        $lister = $this->makeAgent(name: 'Lister');
        $referrer = $this->makeAgent(name: 'Referrer');
        $deal = app(DealService::class)->create(['property_title' => '2 Test St', 'sale_value' => 1_000_000,
            'selling_agent_id' => $seller->id, 'listing_agent_id' => $lister->id, 'referral_agent_id' => $referrer->id], null);

        $c = $deal->fresh()->commission;
        // 60,000 commission → seller is junior → 24,000 agent total; 10% referral = 2,400; 21,600 split 50/50.
        $this->assertSame(2400.0, $c->referral_fee_amount);
        $this->assertSame(10800.0, $c->co_agent_share);
        $this->assertSame(10800.0, $c->agent_share);
        $this->assertSame(36000.0, $c->agency_share);
        $this->assertSame($lister->id, $c->co_agent_id);
        $this->assertEqualsWithDelta(60000.0, $c->agent_share + $c->co_agent_share + $c->referral_fee_amount + $c->agency_share, 0.001);
    }

    public function test_no_vat_when_not_registered_and_paid_commission_is_frozen(): void
    {
        CommissionSetting::current()->update(['vat_registered' => false]);
        $agent = $this->makeAgent();
        $deal = app(DealService::class)->create(['property_title' => '3 Test St', 'sale_value' => 1_000_000, 'selling_agent_id' => $agent->id], null);
        $c = $deal->fresh()->commission;
        $this->assertSame(0.0, $c->vat_amount);

        $c->update(['status' => 'paid', 'paid_at' => now()]);
        $deal->update(['sale_value' => 9_000_000]);
        app(CommissionService::class)->calculateForDeal($deal->fresh());
        $this->assertSame(60000.0, $c->fresh()->commission_amount);
    }

    public function test_advancing_to_final_stage_closes_deal_and_logs_history(): void
    {
        $this->actingAs($this->makeUser('admin'));
        $agent = $this->makeAgent();
        $deal = app(DealService::class)->create(['property_title' => '4 Test St', 'sale_value' => 1_000_000, 'selling_agent_id' => $agent->id], null);

        $stages = PipelineStage::count();
        for ($i = 1; $i < $stages; $i++) {
            $this->postJson("/api/v1/deals/{$deal->id}/advance-stage")->assertOk();
        }
        $deal->refresh();
        $this->assertSame('closed', $deal->status);
        $this->assertNotNull($deal->closed_at);
        $this->assertSame($stages, $deal->stageHistory()->count());
        $this->assertSame(1, $deal->stageHistory()->whereNull('exited_at')->count());

        $this->postJson("/api/v1/deals/{$deal->id}/advance-stage")->assertUnprocessable();
    }
}
