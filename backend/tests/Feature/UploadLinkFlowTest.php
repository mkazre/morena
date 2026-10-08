<?php

namespace Tests\Feature;

use App\Models\ClientUploadLink;
use App\Models\Deal;
use App\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Handover §7 end to end. */
class UploadLinkFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_upload_review_and_file(): void
    {
        Storage::fake();
        $this->seedReference();
        $agent = $this->makeAgent();
        $deal = Deal::create(['property_title' => '14 Rivonia Views', 'sale_value' => 1, 'pipeline_stage_id' => PipelineStage::ordered()->value('id'), 'selling_agent_id' => $agent->id]);

        // 1. Agent generates a link
        $this->actingAs($agent->user);
        $token = $this->postJson('/api/v1/upload-links', [
            'client_name' => 'Precious Nkosi', 'deal_id' => $deal->id,
            'items' => [['label' => 'Copy of ID document'], ['label' => 'Proof of address']],
        ])->assertCreated()->json('data.token');
        auth()->guard('web')->logout();

        // 2–3. Client (no auth) sees the checklist and uploads
        $items = $this->getJson("/api/v1/upload-links/{$token}")->assertOk()
            ->assertJsonPath('property', '14 Rivonia Views')->json('items');
        $this->post("/api/v1/upload-links/{$token}/submit", ['files' => [$items[0]['id'] => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf')]], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('status', 'awaiting_client');
        $this->post("/api/v1/upload-links/{$token}/submit", ['files' => [$items[1]['id'] => UploadedFile::fake()->image('poa.jpg')]], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('status', 'uploaded');

        // 4. Agent files: documents re-linked to the deal
        $this->actingAs($agent->user);
        $link = ClientUploadLink::where('token', $token)->first();
        $this->postJson("/api/v1/upload-links/{$link->id}/file")->assertOk()->assertJsonPath('data.status', 'filed');
        $this->assertSame(2, $deal->documents()->count());

        // Filed links are closed to the public.
        $this->getJson("/api/v1/upload-links/{$token}")->assertStatus(410);
    }

    public function test_expired_links_are_rejected_and_can_be_reissued(): void
    {
        $this->seedReference();
        $agent = $this->makeAgent();
        $link = ClientUploadLink::create(['token' => 'oldtoken1', 'agent_id' => $agent->id, 'client_name' => 'X', 'expires_at' => now()->subDay()]);

        $this->getJson('/api/v1/upload-links/oldtoken1')->assertStatus(410);
        $this->assertSame('expired', $link->fresh()->status);

        $this->actingAs($agent->user);
        $new = $this->postJson("/api/v1/upload-links/{$link->id}/resend")->assertOk()->json('data.token');
        $this->assertNotSame('oldtoken1', $new);
        $this->getJson("/api/v1/upload-links/{$new}")->assertOk();
    }

    public function test_other_agents_cannot_see_or_file_my_links(): void
    {
        $this->seedReference();
        $mine = $this->makeAgent(name: 'Mine');
        $other = $this->makeAgent(name: 'Other');
        $link = ClientUploadLink::create(['token' => 'mytoken12', 'agent_id' => $mine->id, 'client_name' => 'X', 'expires_at' => now()->addDay(), 'status' => 'uploaded']);

        $this->actingAs($other->user);
        $this->getJson('/api/v1/upload-links')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/upload-links/{$link->id}/file")->assertNotFound();
    }
}
