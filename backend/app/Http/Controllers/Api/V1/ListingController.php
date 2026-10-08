<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use App\Services\FileStore;
use App\Support\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ListingController extends Controller
{
    private function scoped(Request $request): Builder
    {
        $user = $request->user();
        $byAgent = fn ($q, $id) => $q->where('agent_id', $id);

        return Scope::filters(Scope::apply(Listing::query(), $user, $byAgent), $request, $user, $byAgent);
    }

    public function index(Request $request)
    {
        $q = $this->scoped($request)->with('agent.user')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest('listed_at');

        return ListingResource::collection($q->paginate(min($request->integer('per_page', 50), 200)));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $user = $request->user();
        if ($user->hasRole('agent')) {
            $data['agent_id'] = $user->staff->id;
        }
        $data['branch_id'] ??= $user->staff?->branch_id ?? $user->branch_id;
        $data['listed_at'] ??= now()->toDateString();
        $data = $this->withPhoto($request, $data);

        return new ListingResource(Listing::create($data)->load('agent.user'));
    }

    public function update(Request $request, Listing $listing)
    {
        abort_unless($this->scoped($request)->whereKey($listing->id)->exists(), 404);
        $data = $this->withPhoto($request, $this->validated($request, partial: true));
        if ($request->user()->hasRole('agent')) {
            unset($data['agent_id'], $data['branch_id']);
        }
        $listing->update($data);

        return new ListingResource($listing->load('agent.user'));
    }

    private function withPhoto(Request $request, array $data): array
    {
        unset($data['photo']);
        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('listing-photos', 'public');
        }

        return $data;
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$req, 'string', 'max:255'],
            'suburb' => [$req, 'string', 'max:255'],
            'price' => [$req, 'numeric', 'min:0'],
            'status' => ['sometimes', Rule::in(['active', 'under_offer', 'sold'])],
            'property_type' => ['sometimes', Rule::in(array_keys(Listing::PROPERTY_TYPES))],
            'beds' => ['sometimes', 'integer', 'between:0,50'],
            'baths' => ['sometimes', 'integer', 'between:0,50'],
            'agent_id' => ['nullable', 'exists:staff,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'listed_at' => ['nullable', 'date'],
            'mandate_expiry' => ['nullable', 'date'],
            'photo' => ['nullable', 'image', 'max:'.FileStore::MAX_KB],
        ]);
    }
}
