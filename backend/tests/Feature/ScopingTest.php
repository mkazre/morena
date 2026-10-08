<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Deal;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Lead;
use App\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Portal endpoints are auto-scoped server-side (handover §4). */
class ScopingTest extends TestCase
{
    use RefreshDatabase;

    private Deal $mine;

    private Deal $theirs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        $sandton = Branch::create(['name' => 'Sandton']);
        $pretoria = Branch::create(['name' => 'Pretoria']);
        $this->me = $this->makeAgent($sandton, 'Me');
        $this->other = $this->makeAgent($pretoria, 'Other');
        $stage = PipelineStage::ordered()->value('id');
        $this->mine = Deal::create(['property_title' => 'Mine', 'sale_value' => 1, 'pipeline_stage_id' => $stage, 'selling_agent_id' => $this->me->id, 'branch_id' => $sandton->id]);
        $this->theirs = Deal::create(['property_title' => 'Theirs', 'sale_value' => 1, 'pipeline_stage_id' => $stage, 'selling_agent_id' => $this->other->id, 'branch_id' => $pretoria->id]);
    }

    public function test_agent_sees_only_own_deals_and_cannot_widen_with_filters(): void
    {
        $this->actingAs($this->me->user);

        $this->getJson('/api/v1/deals?agent_id='.$this->other->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.property', 'Mine');
        $this->getJson('/api/v1/deals/'.$this->theirs->id)->assertNotFound();
        $this->putJson('/api/v1/deals/'.$this->theirs->id, ['sale_value' => 5])->assertNotFound();
    }

    public function test_agent_created_lead_is_always_assigned_to_them(): void
    {
        $this->actingAs($this->me->user);
        $this->postJson('/api/v1/leads', ['name' => 'X', 'source' => 'website', 'assigned_agent_id' => $this->other->id])->assertCreated();
        $this->assertSame($this->me->id, Lead::first()->assigned_agent_id);
    }

    public function test_branch_manager_sees_only_their_branch(): void
    {
        $this->actingAs($this->makeUser('branch_manager', Branch::where('name', 'Pretoria')->first()));
        $this->getJson('/api/v1/deals')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.property', 'Theirs');
    }

    public function test_admin_sees_everything_and_can_filter(): void
    {
        $this->actingAs($this->makeUser('admin'));
        $this->getJson('/api/v1/deals')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/deals?agent_id='.$this->other->id)->assertJsonCount(1, 'data');
    }

    public function test_landlord_only_gets_documents_for_their_own_sale(): void
    {
        $landlord = $this->makeUser('landlord');
        $this->mine->update(['seller_user_id' => $landlord->id]);
        $cat = DocumentCategory::first()->id;
        $ok = Document::create(['name' => 'Mandate.pdf', 'category_id' => $cat, 'linked_model_type' => Deal::class, 'linked_model_id' => $this->mine->id, 'file_path' => 'x']);
        $no = Document::create(['name' => 'Other.pdf', 'category_id' => $cat, 'linked_model_type' => Deal::class, 'linked_model_id' => $this->theirs->id, 'file_path' => 'y']);

        $this->actingAs($landlord);
        $this->getJson('/api/v1/documents')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Mandate.pdf');
        $this->get('/api/v1/documents/'.$no->id.'/download')->assertNotFound();
    }
}
