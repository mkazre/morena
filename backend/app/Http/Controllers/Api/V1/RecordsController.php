<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\DocumentFolder;
use App\Models\DocumentVersion;
use App\Services\FileStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Records hub (SharePoint-style): folders with permissions, versions, metadata and an access log. */
class RecordsController extends Controller
{
    public function __construct(private FileStore $files) {}

    /** Folder tree the user may open, with document counts (scoped exactly like the document list). */
    public function folders(Request $request)
    {
        $user = $request->user();
        $access = DocumentFolder::accessFor($user);
        $all = DocumentFolder::orderBy('position')->orderBy('name')->get();
        $counts = DocumentController::scoped($user)->selectRaw('folder_id, count(*) as n')->groupBy('folder_id')->pluck('n', 'folder_id');
        $byId = $all->keyBy('id');
        $node = function (DocumentFolder $f) use (&$node, $all, $access, $counts, $byId, $user) {
            $kids = $all->where('parent_id', $f->id)->filter(fn ($c) => in_array($c->id, $access['visible'], true))->values()->map($node);

            return [
                'id' => $f->id, 'name' => $f->name, 'count' => (int) ($counts[$f->id] ?? 0),
                'total' => (int) ($counts[$f->id] ?? 0) + $kids->sum('total'),
                'roles' => $f->effectiveRoles($byId), 'inherits' => ! $f->roles, 'shared_with_agents' => $f->sharedWithAgents($byId),
                'is_system' => $f->is_system, 'default_category_id' => $f->default_category_id,
                'can_manage' => $user->hasRole('admin'), 'children' => $kids,
            ];
        };

        return [
            'data' => $all->whereNull('parent_id')->filter(fn ($f) => in_array($f->id, $access['visible'], true))->values()->map($node),
            'unfiled' => (int) ($counts[''] ?? $counts[null] ?? 0),
        ];
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $folderIds = null;
        if ($request->filled('folder_id')) {
            // A folder includes its subfolders.
            $all = DocumentFolder::all(['id', 'parent_id']);
            $folderIds = [$request->integer('folder_id')];
            for ($i = 0; $i < count($folderIds); $i++) {
                foreach ($all->where('parent_id', $folderIds[$i]) as $c) {
                    $folderIds[] = $c->id;
                }
            }
        }
        $term = trim((string) $request->input('search'));
        $q = DocumentController::scoped($user)->with(['folder', 'category', 'uploader', 'linkedModel'])
            ->when($folderIds, fn ($q) => $q->whereIn('folder_id', $folderIds))
            ->when($request->boolean('unfiled'), fn ($q) => $q->whereNull('folder_id'))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%$term%")
                ->orWhere('description', 'like', "%$term%")->orWhere('tags', 'like', "%$term%")))
            ->when($request->filled('year'), fn ($q) => $q->whereYear('created_at', $request->integer('year')))
            ->orderByDesc('updated_at')->orderByDesc('id');
        $page = $q->paginate(min($request->integer('per_page', 50), 200));
        $folders = DocumentFolder::all()->keyBy('id');

        return [
            'data' => collect($page->items())->map(fn ($d) => $this->summary($d, $folders)),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ];
    }

    public function show(Request $request, Document $document)
    {
        $this->authorizeDoc($request, $document);
        $document->load(['versions.uploader', 'accessLog.user', 'folder', 'category', 'uploader', 'linkedModel']);
        $folders = DocumentFolder::all()->keyBy('id');

        return $this->summary($document, $folders) + [
            'versions' => $document->versions->map(fn ($v) => [
                'id' => $v->id, 'version' => $v->version, 'original_name' => $v->original_name, 'note' => $v->note,
                'size' => $v->size_bytes, 'by' => $v->uploader?->name, 'at' => $v->created_at?->toIso8601String(),
                'current' => $v->version === $document->version, 'download_url' => '/api/v1/records/versions/'.$v->id.'/download',
            ]),
            'activity' => $request->user()->hasAnyRole(['admin', 'hr', 'finance', 'branch_manager'])
                ? $document->accessLog->take(30)->map(fn ($l) => ['action' => $l->action, 'detail' => $l->detail, 'by' => $l->user?->name, 'at' => $l->created_at->toIso8601String()])
                : [],
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:'.FileStore::ALLOWED_MIMES.',xls,xlsx,ppt,pptx,mp4,txt,csv', 'max:'.FileStore::MAX_KB * 2],
            'folder_id' => ['required', 'exists:document_folders,id'],
            'name' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:500'],
            'category_id' => ['nullable', 'exists:document_categories,id'],
            'tags' => ['nullable', 'array'], 'tags.property' => ['nullable', 'string', 'max:120'], 'tags.client' => ['nullable', 'string', 'max:120'],
            'tags.agent_id' => ['nullable', 'exists:staff,id'], 'tags.year' => ['nullable', 'integer', 'between:2000,2100'],
            'tags.keywords' => ['nullable', 'string', 'max:255'],
        ]);
        $folder = DocumentFolder::findOrFail($data['folder_id']);
        $this->authorizeFolder($request, $folder);
        $file = $request->file('file');
        $categoryId = $data['category_id'] ?? $folder->default_category_id ?? DB::table('document_categories')->value('id');
        $years = DB::table('document_categories')->where('id', $categoryId)->value('retention_years') ?? 5;

        $doc = Document::create([
            'name' => ($data['name'] ?? null) ?: $file->getClientOriginalName(), 'description' => $data['description'] ?? null,
            'tags' => array_filter($data['tags'] ?? [], fn ($v) => $v !== null && $v !== ''), 'folder_id' => $folder->id,
            'category_id' => $categoryId, 'version' => 1, 'file_path' => $this->files->put($file, 'records/'.$folder->id),
            'size_bytes' => $file->getSize(), 'mime_type' => $file->getMimeType(),
            'uploaded_by' => $request->user()->id, 'retention_expires_at' => now()->addYears($years),
        ]);
        DocumentAccessLog::record($doc, 'uploaded', $request->user(), $folder->path());

        return $this->show($request, $doc);
    }

    /** Upload a new version; the old file stays in the history (SharePoint-style versioning). */
    public function newVersion(Request $request, Document $document)
    {
        $this->authorizeDoc($request, $document);
        $data = $request->validate(['file' => ['required', 'file', 'max:'.FileStore::MAX_KB * 2], 'note' => ['nullable', 'string', 'max:255']]);
        $file = $request->file('file');
        DB::transaction(function () use ($document, $file, $data, $request) {
            $next = ($document->versions()->max('version') ?? 0) + 1;
            $path = $this->files->put($file, 'records/'.($document->folder_id ?? 'unfiled'));
            $document->versions()->create(['version' => $next, 'file_path' => $path, 'original_name' => $file->getClientOriginalName(),
                'size_bytes' => $file->getSize(), 'note' => $data['note'] ?? null, 'uploaded_by' => $request->user()->id, 'created_at' => now()]);
            $document->update(['version' => $next, 'file_path' => $path, 'size_bytes' => $file->getSize(), 'mime_type' => $file->getMimeType()]);
            DocumentAccessLog::record($document, 'new_version', $request->user(), 'v'.$next.($data['note'] ? ' — '.$data['note'] : ''));
        });

        return $this->show($request, $document->fresh());
    }

    /** Make an older version current again (recorded as a new version, so nothing is lost). */
    public function restore(Request $request, DocumentVersion $version)
    {
        $document = $version->document;
        $this->authorizeDoc($request, $document);
        DB::transaction(function () use ($document, $version, $request) {
            $next = $document->versions()->max('version') + 1;
            $document->versions()->create(['version' => $next, 'file_path' => $version->file_path, 'original_name' => $version->original_name,
                'size_bytes' => $version->size_bytes, 'note' => 'Restored from v'.$version->version, 'uploaded_by' => $request->user()->id, 'created_at' => now()]);
            $document->update(['version' => $next, 'file_path' => $version->file_path, 'size_bytes' => $version->size_bytes]);
            DocumentAccessLog::record($document, 'restored', $request->user(), 'v'.$version->version.' → v'.$next);
        });

        return $this->show($request, $document->fresh());
    }

    public function update(Request $request, Document $document)
    {
        $this->authorizeDoc($request, $document);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:500'],
            'folder_id' => ['sometimes', 'exists:document_folders,id'], 'tags' => ['sometimes', 'nullable', 'array'],
        ]);
        $user = $request->user();
        if (isset($data['folder_id']) && (int) $data['folder_id'] !== $document->folder_id) {
            $to = DocumentFolder::findOrFail($data['folder_id']);
            $this->authorizeFolder($request, $to);
            DocumentAccessLog::record($document, 'moved', $user, ($document->folder?->path() ?? 'Unfiled').' → '.$to->path());
        }
        if (isset($data['name']) && $data['name'] !== $document->name) {
            DocumentAccessLog::record($document, 'renamed', $user, $document->name.' → '.$data['name']);
        }
        if (array_key_exists('tags', $data)) {
            $data['tags'] = array_filter($data['tags'] ?? [], fn ($v) => $v !== null && $v !== '');
            DocumentAccessLog::record($document, 'tagged', $user, json_encode($data['tags']));
        }
        $document->update($data);

        return $this->show($request, $document->fresh());
    }

    public function downloadVersion(Request $request, DocumentVersion $version)
    {
        $this->authorizeDoc($request, $version->document);
        DocumentAccessLog::record($version->document, 'downloaded', $request->user(), 'v'.$version->version);

        return $this->files->download($version->file_path, $version->original_name ?: $version->document->name);
    }

    // ---- folder management (admin) -------------------------------------------------------------

    public function storeFolder(Request $request)
    {
        return DocumentFolder::create($this->validatedFolder($request));
    }

    public function updateFolder(Request $request, DocumentFolder $folder)
    {
        $data = $this->validatedFolder($request, $folder);
        abort_if($folder->is_system && isset($data['parent_id']) && $data['parent_id'] != $folder->parent_id, 422, 'Built-in folders can be renamed but not moved.');
        $folder->update($data);

        return $folder;
    }

    public function destroyFolder(DocumentFolder $folder)
    {
        abort_if($folder->is_system, 422, 'Built-in folders cannot be deleted.');
        abort_if($folder->documents()->exists() || $folder->children()->exists(), 422, 'Move or delete the folder\'s contents first.');
        $folder->delete();

        return response()->noContent();
    }

    private function validatedFolder(Request $request, ?DocumentFolder $folder = null): array
    {
        $data = $request->validate([
            'name' => [$folder ? 'sometimes' : 'required', 'string', 'max:120'],
            'parent_id' => ['nullable', 'exists:document_folders,id', Rule::notIn([$folder?->id])],
            'roles' => ['nullable', 'array'], 'roles.*' => [Rule::in(['admin', 'branch_manager', 'hr', 'finance', 'agent'])],
            'shared_with_agents' => ['sometimes', 'boolean'],
            'default_category_id' => ['nullable', 'exists:document_categories,id'],
        ]);
        if (array_key_exists('roles', $data)) {
            $data['roles'] = $data['roles'] ? array_values(array_unique(array_merge(['admin'], $data['roles']))) : null; // admin always keeps access
        }

        return $data;
    }

    // ---- helpers -----------------------------------------------------------------------------

    private function authorizeDoc(Request $request, Document $doc): void
    {
        abort_unless(DocumentController::scoped($request->user())->whereKey($doc->id)->exists(), 404);
    }

    private function authorizeFolder(Request $request, DocumentFolder $folder): void
    {
        abort_unless(in_array($folder->id, DocumentFolder::accessFor($request->user())['visible'], true), 403, 'You do not have access to that folder.');
    }

    private function summary(Document $d, $folders): array
    {
        $tags = $d->tags ?? [];

        return [
            'id' => $d->id, 'name' => $d->name, 'description' => $d->description, 'version' => $d->version,
            'folder_id' => $d->folder_id, 'folder' => $d->folder_id && $folders->get($d->folder_id) ? $folders->get($d->folder_id)->path($folders) : 'Unfiled',
            'category' => $d->category?->name, 'linked_to' => $d->linkedLabel(), 'tags' => (object) $tags,
            'size' => $d->size_bytes, 'type' => strtoupper(pathinfo($d->file_path, PATHINFO_EXTENSION)) ?: 'FILE',
            'uploaded_by' => $d->uploader?->name, 'updated_at' => $d->updated_at?->toIso8601String(),
            'retention_expires_at' => $d->retention_expires_at?->toDateString(),
            'download_url' => '/api/v1/documents/'.$d->id.'/download',
            // Naming convention: Type_Property_Client_Year.ext (shown as a suggestion, never forced).
            'suggested_name' => self::suggestName($d),
        ];
    }

    public static function suggestName(Document $d): string
    {
        $tags = $d->tags ?? [];
        $ext = pathinfo($d->file_path, PATHINFO_EXTENSION);
        $parts = array_filter([
            Str::of($d->folder?->name ?? $d->category?->name ?? 'Document')->replace(['&', ' '], ['and', ''])->singular(),
            isset($tags['property']) ? Str::studly(Str::slug($tags['property'])) : null,
            isset($tags['client']) ? Str::studly(Str::slug($tags['client'])) : null,
            $tags['year'] ?? $d->created_at?->year,
        ]);

        return implode('_', $parts).($ext ? '.'.$ext : '');
    }
}
