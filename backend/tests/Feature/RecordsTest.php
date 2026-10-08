<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\DocumentCategory;
use Database\Seeders\RecordsFolderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecordsTest extends TestCase
{
    use RefreshDatabase;

    private function docIn(string $path, string $name, ?int $uploadedBy = null): Document
    {
        return Document::create(['name' => $name, 'category_id' => DocumentCategory::first()->id, 'file_path' => 'x/'.$name,
            'folder_id' => RecordsFolderSeeder::byPath($path)->id, 'uploaded_by' => $uploadedBy]);
    }

    private function names(): array
    {
        return collect($this->getJson('/api/v1/records/documents?per_page=200')->assertOk()->json('data'))->pluck('name')->sort()->values()->all();
    }

    public function test_folder_permissions_follow_the_brief(): void
    {
        $this->seedReference();
        $agent = $this->makeAgent(name: 'Agent A');
        $other = $this->makeAgent(name: 'Agent B');
        $this->docIn('Finance/Tax Returns', 'VAT201.pdf');
        $this->docIn('HR/Performance Reviews', 'Review.pdf');
        $this->docIn('Compliance/POPIA Policies', 'POPIA policy.pdf');
        $this->docIn('Training & Resources/Agent Guides', 'Sales script.pdf');
        $this->docIn('Sales/Mandates', 'My mandate.pdf', $agent->user_id);
        $this->docIn('Sales/Mandates', 'Their mandate.pdf', $other->user_id);

        // Agent: shared policies/training + own sales records; never Finance, HR or another agent's files.
        $this->actingAs($agent->user);
        $this->assertSame(['My mandate.pdf', 'POPIA policy.pdf', 'Sales script.pdf'], $this->names());
        $folders = collect($this->getJson('/api/v1/records/folders')->json('data'))->pluck('name')->all();
        $this->assertNotContains('Finance', $folders);
        $this->assertNotContains('HR', $folders);

        // Finance role: Finance + Sales, not HR.
        $this->actingAs($this->makeUser('finance'));
        $this->assertContains('VAT201.pdf', $this->names());
        $this->assertNotContains('Review.pdf', $this->names());

        // HR: HR folder but not Finance ("Finance only for directors").
        $this->actingAs($this->makeUser('hr'));
        $this->assertContains('Review.pdf', $this->names());
        $this->assertNotContains('VAT201.pdf', $this->names());

        // Admin sees everything.
        $this->actingAs($this->makeUser('admin'));
        $this->assertCount(6, $this->names());
    }

    public function test_upload_versioning_restore_and_access_log(): void
    {
        Storage::fake();
        $this->seedReference();
        $admin = $this->makeUser('admin');
        $this->actingAs($admin);
        $folder = RecordsFolderSeeder::byPath('Rentals/Lease Agreements');

        $doc = $this->post('/api/v1/records/documents', [
            'folder_id' => $folder->id, 'file' => UploadedFile::fake()->create('lease.pdf', 50, 'application/pdf'),
            'tags' => ['property' => 'Unit 12 Joan Road', 'client' => 'R Dube', 'year' => 2026],
        ], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame('LeaseAgreement_Unit12JoanRoad_RDube_2026.pdf', $doc['suggested_name']);
        $this->assertSame(1, $doc['version']);

        $v2 = $this->post("/api/v1/records/documents/{$doc['id']}/versions", ['file' => UploadedFile::fake()->create('lease-signed.pdf', 60), 'note' => 'Signed copy'],
            ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame(2, $v2['version']);
        $this->assertCount(2, $v2['versions']);

        $v1 = collect($v2['versions'])->firstWhere('version', 1);
        $v3 = $this->postJson("/api/v1/records/versions/{$v1['id']}/restore")->assertOk()->json();
        $this->assertSame(3, $v3['version']);
        $this->assertSame('Restored from v1', $v3['versions'][0]['note']);

        $this->get("/api/v1/records/versions/{$v1['id']}/download")->assertOk();
        $this->assertSame(['uploaded', 'new_version', 'restored', 'downloaded'],
            DocumentAccessLog::where('document_id', $doc['id'])->orderBy('id')->pluck('action')->all());
    }

    public function test_agents_cannot_upload_into_restricted_folders(): void
    {
        Storage::fake();
        $this->seedReference();
        $this->actingAs($this->makeAgent()->user);
        $this->post('/api/v1/records/documents', [
            'folder_id' => RecordsFolderSeeder::byPath('Finance/Tax Returns')->id, 'file' => UploadedFile::fake()->create('x.pdf', 5),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }
}
