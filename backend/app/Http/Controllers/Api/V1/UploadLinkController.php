<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UploadLinkResource;
use App\Models\ClientUploadLink;
use App\Models\Deal;
use App\Services\Messaging\NotificationChannel;
use App\Support\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UploadLinkController extends Controller
{
    public const DEFAULT_ITEMS = [
        ['Copy of ID document', 'Certified or clear photo'],
        ['Proof of address', 'Not older than 3 months'],
        ['Proof of funds / bond pre-approval', 'Bank statement or bond letter'],
    ];

    public const LIFETIME_DAYS = 14;

    public function __construct(private NotificationChannel $messages) {}

    /** Admin sees every link (§7.5); agents only their own; managers their branch. */
    private function scoped(Request $request): Builder
    {
        return Scope::apply(ClientUploadLink::query(), $request->user(),
            fn ($q, $id) => $q->where('agent_id', $id),
            fn ($q, $branchId) => $q->whereHas('agent', fn ($a) => $a->where('branch_id', $branchId)));
    }

    public function index(Request $request)
    {
        $links = $this->scoped($request)->with('agent.user')->withCount('items')->latest()->get()
            ->each->refreshExpiry();

        return UploadLinkResource::collection($links);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'client_name' => ['required', 'string', 'max:255'],
            'client_phone' => ['nullable', 'string', 'max:30'],
            'client_email' => ['nullable', 'email'],
            'deal_id' => ['nullable', 'exists:deals,id'],
            'agent_id' => ['nullable', 'exists:staff,id'],
            'items' => ['sometimes', 'array', 'max:20'],
            'items.*.label' => ['required', 'string', 'max:255'],
            'items.*.hint' => ['nullable', 'string', 'max:255'],
            'items.*.required' => ['sometimes', 'boolean'],
        ]);
        if (! empty($data['deal_id'])) {
            abort_unless(DealController::scoped($request)->whereKey($data['deal_id'])->exists(), 422, 'Deal not found.');
        }
        $agentId = Scope::isOrgWide($user) && ! empty($data['agent_id']) ? $data['agent_id'] : $user->staff?->id;
        abort_unless($agentId, 422, 'Choose the agent this link belongs to.');

        $items = collect($data['items'] ?? [])->whenEmpty(fn () => collect(self::DEFAULT_ITEMS)
            ->map(fn ($i) => ['label' => $i[0], 'hint' => $i[1]]));

        $link = DB::transaction(function () use ($data, $agentId, $items) {
            $link = ClientUploadLink::create([
                'token' => ClientUploadLink::newToken(),
                'agent_id' => $agentId,
                'client_name' => $data['client_name'],
                'client_phone' => $data['client_phone'] ?? null,
                'client_email' => $data['client_email'] ?? null,
                'deal_id' => $data['deal_id'] ?? null,
                'expires_at' => now()->addDays(self::LIFETIME_DAYS),
            ]);
            foreach ($items as $item) {
                $link->items()->create([
                    'document_label' => $item['label'],
                    'hint' => $item['hint'] ?? null,
                    'is_required' => $item['required'] ?? true,
                ]);
            }

            return $link;
        });
        $this->send($link);

        return new UploadLinkResource($link->load('agent.user')->loadCount('items'));
    }

    public function items(Request $request, int $link)
    {
        $link = $this->scoped($request)->with('items.document')->findOrFail($link);

        return ['data' => $link->items->map(fn ($i) => [
            'id' => $i->id,
            'label' => $i->document_label,
            'status' => $i->status,
            'download_url' => $i->uploaded_document_id ? '/api/v1/documents/'.$i->uploaded_document_id.'/download' : null,
        ])];
    }

    /**
     * POST /upload-links/{id}/file — agent reviewed the submission: re-link each document to the deal
     * (when the link has one) and mark everything filed (§7.4).
     */
    public function file(Request $request, int $link)
    {
        $link = $this->scoped($request)->with('items.document')->findOrFail($link);
        abort_unless($link->status === 'uploaded', 422, 'Nothing to file — the client has not submitted yet.');

        DB::transaction(function () use ($link) {
            foreach ($link->items as $item) {
                if (! $item->document) {
                    continue;
                }
                if ($link->deal_id) {
                    $item->document->update(['linked_model_type' => Deal::class, 'linked_model_id' => $link->deal_id]);
                }
                $item->update(['status' => 'filed']);
            }
            $link->update(['status' => 'filed']);
        });

        return new UploadLinkResource($link->load('agent.user')->loadCount('items'));
    }

    /** Resend an open link, or issue a fresh token for an expired one ("Generate new link"). */
    public function resend(Request $request, int $link)
    {
        $link = $this->scoped($request)->findOrFail($link)->refreshExpiry();
        abort_if($link->status === 'filed', 422, 'This link is already filed.');

        if ($link->status === 'expired') {
            $link->update([
                'token' => ClientUploadLink::newToken(),
                'status' => 'awaiting_client',
                'expires_at' => now()->addDays(self::LIFETIME_DAYS),
            ]);
        }
        $this->send($link);

        return new UploadLinkResource($link->load('agent.user')->loadCount('items'));
    }

    private function send(ClientUploadLink $link): void
    {
        $to = $link->client_phone ?: $link->client_email;
        if ($to) {
            $this->messages->send($to, "Hi {$link->client_name}, please upload your documents for Morena Properties here: ".url('/u/'.$link->token));
        }
        $link->update(['last_sent_at' => now()]);
    }
}
