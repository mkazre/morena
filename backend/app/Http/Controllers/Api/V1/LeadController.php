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
            ->when($request->input('sort') === 'score', fn ($q) => $q->orderByDesc('score'))
            ->latest();

        return LeadResource::collection($q->paginate(min($request->integer('per_page', 50), 200)));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        if ($request->user()->hasRole('agent')) {
            $data['assigned_agent_id'] = $request->user()->staff->id; // agents always own leads they add
        }
        // Duplicate guard: same phone (last 9 digits) or email anywhere in the CRM, unless the user confirms.
        if (! $request->boolean('confirm_duplicate') && ($dupes = self::duplicates($data['phone'] ?? null, $data['email'] ?? null))->isNotEmpty()) {
            return response()->json([
                'message' => 'This looks like an existing lead.',
                'duplicates' => $dupes->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'phone' => $l->phone, 'email' => $l->email,
                    'agent' => $l->agent?->user?->name, 'status' => $l->status, 'created_at' => $l->created_at->toDateString()]),
            ], 409);
        }

        return new LeadResource(Lead::create($data)->load('agent.user'));
    }

    public static function duplicates(?string $phone, ?string $email)
    {
        $key = \App\Services\LeadScoring::phoneKey($phone);
        if (! $key && ! $email) {
            return collect();
        }

        return Lead::with('agent.user')->where(fn ($q) => $q
            ->when($key, fn ($w) => $w->orWhere('phone', 'like', '%'.substr($key, 0, 2).'%'))
            ->when($email, fn ($w) => $w->orWhere('email', $email)))
            ->get()->filter(fn ($l) => ($key && \App\Services\LeadScoring::phoneKey($l->phone) === $key) || ($email && strcasecmp((string) $l->email, $email) === 0))
            ->take(5)->values();
    }

    /**
     * POST /leads/import (CSV): columns name, phone, email, interest, source, agent_email, consent.
     * Duplicates (same phone/email) are skipped and reported. Agents import into their own name.
     */
    public function import(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);
        $rows = array_map('str_getcsv', preg_split('/\r\n|\n|\r/', trim(file_get_contents($request->file('file')->getRealPath()))));
        $head = array_map(fn ($h) => strtolower(trim(preg_replace('/[^a-z_ ]/i', '', $h))), array_shift($rows) ?? []);
        $col = fn ($names) => collect((array) $names)->map(fn ($n) => array_search($n, $head, true))->first(fn ($i) => $i !== false);
        $idx = ['name' => $col(['name', 'full name', 'client']), 'phone' => $col(['phone', 'cell', 'mobile', 'phone number']), 'email' => $col(['email', 'email address']),
            'interest' => $col(['interest', 'interested in', 'requirements', 'notes']), 'source' => $col('source'), 'agent' => $col(['agent_email', 'agent email', 'agent']),
            'consent' => $col(['consent', 'marketing consent'])];
        abort_if($idx['name'] === null, 422, 'The CSV needs a "name" column.');
        $user = $request->user();
        $agents = \App\Models\Staff::with('user')->get()->keyBy(fn ($s) => strtolower($s->user->email));
        $created = 0;
        $skipped = [];
        foreach ($rows as $n => $r) {
            $name = trim($r[$idx['name']] ?? '');
            if ($name === '') {
                continue;
            }
            $phone = $idx['phone'] !== null ? trim($r[$idx['phone']] ?? '') : null;
            $email = $idx['email'] !== null ? trim($r[$idx['email']] ?? '') : null;
            if (self::duplicates($phone, $email ?: null)->isNotEmpty()) {
                $skipped[] = ['row' => $n + 2, 'name' => $name, 'reason' => 'duplicate'];

                continue;
            }
            $source = strtolower(str_replace(['-', ' '], '_', trim($r[$idx['source']] ?? '')));
            $agentEmail = strtolower(trim($r[$idx['agent']] ?? ''));
            Lead::create([
                'name' => $name, 'phone' => $phone ?: null, 'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
                'interest_description' => $idx['interest'] !== null ? mb_substr(trim($r[$idx['interest']] ?? ''), 0, 255) : null,
                'source' => array_key_exists($source, Lead::SOURCES) ? $source : 'website',
                'assigned_agent_id' => $user->hasRole('agent') ? $user->staff->id : ($agents->get($agentEmail)?->id),
                'marketing_consent' => in_array(strtolower(trim($r[$idx['consent']] ?? '')), ['yes', 'y', '1', 'true'], true),
                'consent_source' => 'CSV import',
            ]);
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
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
            'marketing_consent' => ['sometimes', 'boolean'],
            'consent_source' => ['nullable', 'string', 'max:60'],
        ]);
    }
}
