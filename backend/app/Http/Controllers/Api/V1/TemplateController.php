<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use App\Services\FileStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TemplateController extends Controller
{
    public function index()
    {
        // Templates only appear once a file has been uploaded (stubs created from pipeline chips have none).
        $templates = DocumentTemplate::with('currentVersion')->whereNotNull('current_version_id')->orderBy('name')->get();

        return ['data' => $templates->map(fn ($t) => $this->present($t))];
    }

    public function store(Request $request, FileStore $files)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'used_for' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'exists:document_categories,id'],
            'file' => ['required', 'file', 'mimes:'.FileStore::ALLOWED_MIMES, 'max:'.FileStore::MAX_KB],
        ]);
        $template = DocumentTemplate::firstOrNew(['name' => $data['name']]);
        $template->fill(collect($data)->only(['used_for', 'category_id'])->all())->save();

        return $this->addVersion($request, $template, $files);
    }

    /** POST /document-templates/{id}/new-version — supersedes the current file for all future deals. */
    public function newVersion(Request $request, DocumentTemplate $template, FileStore $files)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:'.FileStore::ALLOWED_MIMES, 'max:'.FileStore::MAX_KB]]);

        return $this->addVersion($request, $template, $files);
    }

    public function download(DocumentTemplate $template, FileStore $files)
    {
        abort_unless($template->currentVersion, 404, 'No file uploaded for this template yet.');

        return $files->download($template->currentVersion->file_path, $template->name.' v'.$template->currentVersion->version_number);
    }

    private function addVersion(Request $request, DocumentTemplate $template, FileStore $files): array
    {
        $template = DB::transaction(function () use ($request, $template, $files) {
            $version = $template->versions()->create([
                'version_number' => ($template->versions()->max('version_number') ?? 0) + 1,
                'file_path' => $files->put($request->file('file'), 'templates'),
                'uploaded_by' => $request->user()->id,
            ]);
            $template->update(['current_version_id' => $version->id]);

            return $template->fresh('currentVersion');
        });

        return $this->present($template);
    }

    private function present(DocumentTemplate $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'used_for' => $t->used_for,
            'version' => $t->currentVersion?->version_number,
            'updated_at' => $t->currentVersion?->created_at?->toIso8601String(),
            'download_url' => '/api/v1/document-templates/'.$t->id.'/download',
        ];
    }
}
