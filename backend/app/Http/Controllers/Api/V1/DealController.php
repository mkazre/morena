<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DealResource;
use App\Models\CommissionSetting;
use App\Models\Deal;
use App\Models\PipelineStage;
use App\Services\DealService;
use App\Support\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DealController extends Controller
{
    public function __construct(private DealService $deals) {}

    public static function scoped(Request $request): Builder
    {
        $user = $request->user();
        $q = Scope::apply(Deal::query(), $user, fn ($q, $id) => $q->forAgent($id));

        return Scope::filters($q, $request, $user, fn ($q, $id) => $q->forAgent($id));
    }

    public function index(Request $request)
    {
        $q = self::scoped($request)
            ->with(['stage', 'sellingAgent.user', 'listingAgent.user', 'commission', 'stageHistory'])
            ->when($request->filled('stage_id'), fn ($q) => $q->where('pipeline_stage_id', $request->integer('stage_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('property_title', 'like', $term)
                    ->orWhereHas('sellingAgent.user', fn ($u) => $u->where('name', 'like', $term))
                    ->orWhereHas('listingAgent.user', fn ($u) => $u->where('name', 'like', $term)));
            })
            ->latest('updated_at');

        return DealResource::collection($q->paginate(min($request->integer('per_page', 50), 200)));
    }

    public function show(Request $request, Deal $deal)
    {
        $this->authorizeDeal($request, $deal);
        $deal->load(['stage', 'listing', 'listingAgent.user', 'sellingAgent.user', 'commission.tier', 'activity', 'documents', 'stageHistory']);

        $stages = PipelineStage::ordered()->with('documentTemplates')->get();
        $currentIdx = $stages->search(fn ($s) => $s->id === $deal->pipeline_stage_id);
        $c = $deal->commission;
        $settings = CommissionSetting::current();

        // Required docs = templates attached to every stage reached so far; "received" when a
        // document filed against this deal carries the template's name.
        $docNames = $deal->documents->pluck('name')->map(fn ($n) => mb_strtolower($n));
        $required = $stages->take($currentIdx + 1)->flatMap->documentTemplates->unique('id')->values()
            ->map(fn ($t) => [
                'name' => $t->name,
                'received' => $docNames->contains(fn ($n) => str_contains($n, mb_strtolower($t->name))),
            ]);

        return [
            'deal' => new DealResource($deal),
            'listing' => $deal->listing ? [
                'id' => $deal->listing->id,
                'suburb' => $deal->listing->suburb,
                'beds' => $deal->listing->beds,
                'baths' => $deal->listing->baths,
                'listed_at' => $deal->listing->listed_at?->toDateString(),
            ] : null,
            'buyer_name' => $deal->buyer_name,
            'seller_name' => $deal->seller_name,
            'buyer_phone' => $deal->lead?->phone, 'buyer_email' => $deal->lead?->email,
            'seller_phone' => $deal->seller?->phone, 'seller_email' => $deal->seller?->email,
            'listing_agent' => $deal->listingAgent?->user?->name,
            'selling_agent' => $deal->sellingAgent?->user?->name,
            'mandate_type' => $deal->mandate_type,
            'bond_required' => $deal->bond_required,
            'bond_ltv_percent' => $deal->bond_ltv_percent,
            'lost_reason' => $deal->lost_reason,
            'stages' => $stages->map(fn ($s, $i) => [
                'id' => $s->id, 'name' => $s->name,
                'done' => $i < $currentIdx, 'active' => $i === $currentIdx,
            ]),
            'is_final_stage' => $currentIdx === $stages->count() - 1,
            'activity' => $deal->activity->take(20)->map(fn ($a) => ['text' => $a->text, 'at' => $a->created_at->toIso8601String()]),
            'commission' => $c ? [
                'rate_percent' => $settings->base_rate_percent,
                'vat_rate_percent' => $c->vat_amount > 0 ? $settings->vat_rate : 0,
                'commission_amount' => $c->commission_amount,
                'vat_amount' => $c->vat_amount,
                'tier' => $c->tier?->name,
                'agent_split_percent' => $c->tier?->agent_split_percent,
                'agent_share' => $c->agent_share + $c->co_agent_share,
                'referral_fee_amount' => $c->referral_fee_amount,
                'agency_share' => $c->agency_share,
                'status' => $c->status,
            ] : null,
            'required_documents' => $required,
        ];
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $user = $request->user();
        if ($user->hasRole('agent')) {
            $data['selling_agent_id'] ??= $user->staff->id;
            $data['listing_agent_id'] ??= $user->staff->id;
        }
        $data['branch_id'] ??= $user->staff?->branch_id ?? $user->branch_id;

        $deal = $this->deals->create($data, $user);

        return new DealResource($deal->load(['stage', 'sellingAgent.user', 'listingAgent.user', 'commission']));
    }

    public function update(Request $request, Deal $deal)
    {
        $this->authorizeDeal($request, $deal);
        $data = $this->validated($request, partial: true);
        if ($request->user()->hasRole('agent')) {
            // Agents can work their deals but not reassign them.
            unset($data['listing_agent_id'], $data['selling_agent_id'], $data['referral_agent_id'], $data['branch_id']);
        }
        if (($data['status'] ?? null) === 'lost') {
            $request->validate(['lost_reason' => ['required']]);
        }
        $deal = $this->deals->update($deal, $data, $request->user());

        return new DealResource($deal->load(['stage', 'sellingAgent.user', 'listingAgent.user', 'commission']));
    }

    public function advance(Request $request, Deal $deal)
    {
        $this->authorizeDeal($request, $deal);
        $deal = $this->deals->advance($deal, $request->user());

        return new DealResource($deal->load(['stage', 'sellingAgent.user', 'listingAgent.user', 'commission']));
    }

    private function authorizeDeal(Request $request, Deal $deal): void
    {
        abort_unless(self::scoped($request)->whereKey($deal->id)->exists(), 404);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'property_title' => [$req, 'string', 'max:255'],
            'sale_value' => [$req, 'numeric', 'min:0'],
            'pipeline_stage_id' => ['sometimes', 'exists:pipeline_stages,id'],
            'listing_id' => ['nullable', 'exists:listings,id'],
            'lead_id' => ['nullable', 'exists:leads,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'listing_agent_id' => ['nullable', 'exists:staff,id'],
            'selling_agent_id' => ['nullable', 'exists:staff,id'],
            'referral_agent_id' => ['nullable', 'exists:staff,id'],
            'co_listing_split_percent' => ['nullable', 'numeric', 'between:0,100'],
            'buyer_name' => ['nullable', 'string', 'max:255'],
            'seller_name' => ['nullable', 'string', 'max:255'],
            'mandate_type' => ['sometimes', Rule::in(['sole', 'open'])],
            'bond_required' => ['sometimes', 'boolean'],
            'bond_ltv_percent' => ['nullable', 'integer', 'between:0,100'],
            'expected_close_date' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in(['open', 'lost'])],
            'lost_reason' => ['nullable', Rule::in(['bond_declined', 'price_disagreement', 'buyer_withdrew', 'chose_another_property', 'seller_withdrew_mandate'])],
        ]);
    }
}
