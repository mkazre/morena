<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\Deal;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentFolder;
use App\Models\Lead;
use App\Models\Listing;
use App\Models\Staff;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\FileStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DocumentController extends Controller
{
    /** Morph aliases the API accepts for linked_model_type. */
    public const LINKABLE = [
        'deal' => Deal::class, 'lead' => Lead::class, 'listing' => Listing::class,
        'staff' => Staff::class, 'tenancy' => Tenancy::class,
    ];

    public static function scoped(User $user): Builder
    {
        $q = Document::query();
        // Portal users only ever see what is filed against their own sale or lease (folders don't apply).
        if ($user->hasRole('landlord')) {
            return $q->whereHasMorph('linkedModel', [Deal::class], fn ($d) => $d->where('seller_user_id', $user->id));
        }
        if ($user->hasRole('tenant')) {
            return $q->whereHasMorph('linkedModel', [Tenancy::class], fn ($t) => $t->where('tenant_user_id', $user->id));
        }
        if ($user->hasRole('admin')) {
            return $q;
        }

        // Records hub permissions: a folder the role can't open hides its documents, whoever they belong to.
        $access = DocumentFolder::accessFor($user);
        $q->where(fn ($w) => $w->whereNull('folder_id')->orWhereIn('folder_id', $access['visible']));
        if ($user->hasAnyRole(['hr', 'finance'])) {
            return $q;
        }

        $staffId = $user->staff?->id ?? 0;
        $dealScope = fn ($d) => $user->hasRole('branch_manager')
            ? $d->where('branch_id', $user->branch_id)
            : $d->forAgent($staffId);

        // Agents / managers: shared folders (policies, templates, training) plus their own uploads and anything filed
        // against their deals, leads, listings or themselves.
        return $q->where(fn ($w) => $w->whereIn('folder_id', $access['shared'])
            ->orWhere('uploaded_by', $user->id)
            ->orWhereHasMorph('linkedModel', [Deal::class], $dealScope)
            ->orWhereHasMorph('linkedModel', [Lead::class], fn ($l) => $l->where('assigned_agent_id', $staffId))
            ->orWhereHasMorph('linkedModel', [Listing::class], fn ($l) => $l->where('agent_id', $staffId))
            ->orWhere(fn ($s) => $s->where('linked_model_type', Staff::class)->where('linked_model_id', $staffId)));
    }

    public function index(Request $request)
    {
        $q = self::scoped($request->user())->with(['category', 'linkedModel'])
            ->when($request->filled('category'), fn ($q) => $q->where('category_id', $request->integer('category')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where('name', 'like', $term);
            })
            ->latest();

        return DocumentResource::collection($q->paginate(min($request->integer('per_page', 50), 200)));
    }

    /** Category sidebar for docs/Library with counts (scoped like the list). */
    public function categories(Request $request)
    {
        $counts = self::scoped($request->user())->selectRaw('category_id, count(*) as n')->groupBy('category_id')->pluck('n', 'category_id');

        return [
            'total' => (int) $counts->sum(),
            'data' => DocumentCategory::orderBy('name')->get()->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'count' => (int) ($counts[$c->id] ?? 0),
            ]),
        ];
    }

    public function store(Request $request, FileStore $files)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:'.FileStore::ALLOWED_MIMES, 'max:'.FileStore::MAX_KB],
            'name' => ['nullable', 'string', 'max:255'],
            'category_id' => ['required', 'exists:document_categories,id'],
            'linked_model_type' => ['nullable', Rule::in(array_keys(self::LINKABLE))],
            'linked_model_id' => ['nullable', 'required_with:linked_model_type', 'integer'],
        ]);
        $file = $request->file('file');
        $name = $data['name'] ?: $file->getClientOriginalName();
        $type = isset($data['linked_model_type']) ? self::LINKABLE[$data['linked_model_type']] : null;
        if ($type) {
            abort_unless($type::whereKey($data['linked_model_id'])->exists(), 422, 'Linked record not found.');
        }

        // Re-uploading a document with the same name against the same record bumps its version.
        $previous = Document::where('name', $name)->where('linked_model_type', $type)
            ->where('linked_model_id', $data['linked_model_id'] ?? null)->max('version');
        $category = DocumentCategory::find($data['category_id']);

        $doc = Document::create([
            'name' => $name,
            'category_id' => $category->id,
            'linked_model_type' => $type,
            'linked_model_id' => $data['linked_model_id'] ?? null,
            'version' => ($previous ?? 0) + 1,
            'file_path' => $files->put($file, 'documents'),
            'uploaded_by' => $request->user()->id,
            'retention_expires_at' => now()->addYears($category->retention_years),
        ]);

        return new DocumentResource($doc->load(['category', 'linkedModel']));
    }

    public function download(Request $request, Document $document, FileStore $files)
    {
        abort_unless(self::scoped($request->user())->whereKey($document->id)->exists(), 404);
        \App\Models\DocumentAccessLog::record($document, 'downloaded', $request->user(), 'v'.$document->version);

        return $files->download($document->file_path, $document->name);
    }
}
