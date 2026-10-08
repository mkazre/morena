<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DealResource;
use App\Http\Resources\LeadResource;
use App\Models\Lead;
use App\Services\DealService;
use App\Support\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    private function scoped(Request $request): Builder
    {
        $user = $request->user();
        $byAgent = fn ($q, $id) => $q->where('assigned_agent_id', $id);
        $byBranch = fn ($q, $branchId) => $q->whereHas('agent', fn ($a) => $a->where('branch_id', $branchId));

        return Scope::filters(Scope::apply(Lead::query(), $user, $byAgent, $byBranch), $request, $user, $byAgent, $byBranch);
    }

    public function index(Request $request)
    {
        $q = $this->scoped($request)->with('agent.user')
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->string('source')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('phone', 'like', $term)
                    ->orWhere('email', 'like', $term)->orWhere('interest_description', 'like', $term));
            })
            ->latest();

        return LeadResource::collection($q->paginate(min($request->integer('per_page', 50), 200)));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        if ($request->user()->hasRole('agent')) {
            $data['assigned_agent_id'] = $request->user()->staff->id; // agents always own leads they add
        }

        return new LeadResource(Lead::create($data)->load('agent.user'));
    }

    public function update(Request $request, Lead $lead)
    {
        abort_unless($this->scoped($request)->whereKey($lead->id)->exists(), 404);
        $data = $this->validated($request, partial: true);
        if ($request->user()->hasRole('agent')) {
            unset($data['assigned_agent_id']);
        }
        $lead->update($data);

        return new LeadResource($lead->load('agent.user'));
    }

    /** POST /leads/{id}/convert — creates a deal at the first pipeline stage from the lead. */
    public function convert(Request $request, Lead $lead, DealService $deals)
    {
        abort_unless($this->scoped($request)->whereKey($lead->id)->exists(), 404);
        abort_if($lead->status === 'converted', 422, 'Lead already converted.');

        $data = $request->validate([
            'property_title' => ['required', 'string', 'max:255'],
            'sale_value' => ['required', 'numeric', 'min:0'],
            'listing_id' => ['nullable', 'exists:listings,id'],
            'expected_close_date' => ['nullable', 'date'],
        ]);
        $agent = $lead->agent;
        $deal = $deals->create([
            ...$data,
            'lead_id' => $lead->id,
            'buyer_name' => $lead->name,
            'selling_agent_id' => $agent?->id,
            'listing_agent_id' => $agent?->id,
            'branch_id' => $agent?->branch_id,
        ], $request->user());
        $lead->update(['status' => 'converted']);

        return new DealResource($deal->load(['stage', 'sellingAgent.user', 'listingAgent.user', 'commission']));
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$req, 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email'],
            'interest_description' => ['nullable', 'string', 'max:255'],
            'source' => [$req, Rule::in(array_keys(Lead::SOURCES))],
            'assigned_agent_id' => ['nullable', 'exists:staff,id'],
            'status' => ['sometimes', Rule::in(['new', 'contacted', 'qualified', 'converted', 'lost'])],
        ]);
    }
}
