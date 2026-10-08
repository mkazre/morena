<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Listing;
use App\Models\Task;
use App\Models\User;
use App\Services\Notifier;
use App\Support\Scope;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Tasks & follow-ups, optionally linked to a lead, deal or listing. */
class TaskController extends Controller
{
    public const RELATED = ['lead' => Lead::class, 'deal' => Deal::class, 'listing' => Listing::class];

    public function index(Request $request)
    {
        $user = $request->user();
        $q = Task::with(['assignee', 'related'])
            ->when($request->input('scope', 'mine') === 'mine' || ! $this->canSeeOthers($user), fn ($q) => $q->where('assigned_to', $user->id))
            ->when($user->hasRole('branch_manager') && $request->input('scope') === 'team', fn ($q) => $q->whereHas('assignee', fn ($a) => $a->where('branch_id', $user->branch_id)))
            ->when($request->filled('related_type'), fn ($q) => $q->where('related_type', self::RELATED[$request->input('related_type')] ?? '')->where('related_id', $request->integer('related_id')))
            ->when($request->input('status') === 'open', fn ($q) => $q->whereNull('completed_at'))
            ->when($request->input('status') === 'done', fn ($q) => $q->whereNotNull('completed_at'))
            ->orderByRaw('completed_at is not null')->orderByRaw('due_at is null')->orderBy('due_at')->limit(300);

        return ['data' => $q->get()->map(fn ($t) => $this->present($t))];
    }

    public function store(Request $request, Notifier $notifier)
    {
        $data = $this->validated($request);
        $user = $request->user();
        $data['assigned_to'] = $this->canSeeOthers($user) ? ($data['assigned_to'] ?? $user->id) : $user->id;
        $task = Task::create($data + ['created_by' => $user->id]);
        if ($task->assigned_to !== $user->id) {
            $notifier->send($task->assigned_to, 'task', 'New task from '.$user->name, $task->title, '/portal/Tasks.html');
        }

        return $this->present($task->load(['assignee', 'related']));
    }

    public function update(Request $request, Task $task)
    {
        $this->authorizeTask($request->user(), $task);
        $data = $this->validated($request, true);
        if (! $this->canSeeOthers($request->user())) {
            unset($data['assigned_to']);
        }
        $task->update($data);

        return $this->present($task->fresh(['assignee', 'related']));
    }

    public function toggle(Request $request, Task $task)
    {
        $this->authorizeTask($request->user(), $task);
        $task->update(['completed_at' => $task->completed_at ? null : now()]);
        if ($task->completed_at && $task->related instanceof Lead) {
            $task->related->update(['last_contacted_at' => now()]); // finishing a lead follow-up counts as contact
        }

        return $this->present($task->fresh(['assignee', 'related']));
    }

    public function destroy(Request $request, Task $task)
    {
        $this->authorizeTask($request->user(), $task);
        $task->delete();

        return response()->noContent();
    }

    private function canSeeOthers(User $u): bool
    {
        return $u->hasAnyRole(['admin', 'branch_manager', 'hr']);
    }

    private function authorizeTask(User $u, Task $t): void
    {
        abort_unless($t->assigned_to === $u->id || $t->created_by === $u->id || Scope::isOrgWide($u)
            || ($u->hasRole('branch_manager') && $t->assignee?->branch_id === $u->branch_id), 404);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $data = $request->validate([
            'title' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'due_at' => ['nullable', 'date'],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high'])],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'related_type' => ['nullable', Rule::in(array_keys(self::RELATED))],
            'related_id' => ['nullable', 'required_with:related_type', 'integer'],
        ]);
        if (array_key_exists('related_type', $data)) {
            $data['related_type'] = $data['related_type'] ? self::RELATED[$data['related_type']] : null;
        }

        return $data;
    }

    public static function relatedLink(?object $m): ?array
    {
        return match (true) {
            $m instanceof Lead => ['type' => 'lead', 'label' => 'Lead: '.$m->name, 'url' => '/sales/Leads.html?search='.urlencode($m->name)],
            $m instanceof Deal => ['type' => 'deal', 'label' => 'Deal: '.$m->property_title, 'url' => '/sales/DealDetail.html?id='.$m->id],
            $m instanceof Listing => ['type' => 'listing', 'label' => 'Listing: '.$m->title, 'url' => '/sales/Brochure.html?id='.$m->id],
            default => null,
        };
    }

    private function present(Task $t): array
    {
        return [
            'id' => $t->id, 'title' => $t->title, 'notes' => $t->notes, 'priority' => $t->priority,
            'due_at' => $t->due_at?->toIso8601String(), 'done' => (bool) $t->completed_at, 'completed_at' => $t->completed_at?->toIso8601String(),
            'overdue' => ! $t->completed_at && $t->due_at && $t->due_at->isPast(),
            'assigned_to' => $t->assigned_to, 'assignee' => $t->assignee?->name, 'related' => self::relatedLink($t->related),
        ];
    }
}
