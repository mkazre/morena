<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Accounting\Contact;
use App\Models\Accounting\Invoice;
use App\Models\AgentTarget;
use App\Models\Appointment;
use App\Models\ClientUploadLink;
use App\Models\Deal;
use App\Models\DealComment;
use App\Models\Lead;
use App\Models\LeaveRequest;
use App\Models\Listing;
use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use App\Services\Notifier;
use App\Support\Scope;
use Carbon\Carbon;
use Illuminate\Http\Request;

/** "My Day", global search, deal comments with @mentions and agent targets. */
class WorkspaceController extends Controller
{
    /** Everything that needs the signed-in user's attention today. */
    public function myDay(Request $request)
    {
        $user = $request->user();
        $staffId = $user->staff?->id;
        $today = now()->startOfDay();
        $tasks = Task::with('related')->where('assigned_to', $user->id)->whereNull('completed_at')
            ->where(fn ($q) => $q->whereNull('due_at')->orWhere('due_at', '<=', $today->copy()->endOfDay()))
            ->orderByRaw('due_at is null')->orderBy('due_at')->limit(10)->get();
        $appointments = Appointment::with(['listing', 'lead'])->where('user_id', $user->id)->where('status', 'scheduled')
            ->whereBetween('starts_at', [$today, $today->copy()->endOfDay()->addDay()])->orderBy('starts_at')->get();
        $followUps = $staffId ? Lead::where('assigned_agent_id', $staffId)->whereIn('status', ['new', 'contacted', 'qualified'])
            ->where(fn ($q) => $q->where('last_contacted_at', '<', now()->subDays(7))->orWhere(fn ($w) => $w->whereNull('last_contacted_at')->where('created_at', '<', now()->subDays(2))))
            ->orderByDesc('score')->limit(6)->get() : collect();
        $toFile = $staffId ? ClientUploadLink::where('agent_id', $staffId)->where('status', 'uploaded')->count() : 0;
        $approvals = $user->hasAnyRole(['admin', 'hr', 'branch_manager'])
            ? LeaveRequest::where('status', 'pending')->when($user->hasRole('branch_manager'), fn ($q) => $q->whereHas('staff', fn ($s) => $s->where('branch_id', $user->branch_id)))->count() : 0;

        return [
            'greeting' => (now()->hour < 12 ? 'Good morning' : (now()->hour < 17 ? 'Good afternoon' : 'Good evening')).', '.strtok($user->name, ' '),
            'tasks' => $tasks->map(fn ($t) => ['id' => $t->id, 'title' => $t->title, 'due_at' => $t->due_at?->toIso8601String(),
                'overdue' => $t->due_at && $t->due_at->lt($today), 'priority' => $t->priority, 'related' => TaskController::relatedLink($t->related)]),
            'appointments' => $appointments->map(fn ($a) => ['id' => $a->id, 'type' => $a->type, 'title' => $a->title, 'starts_at' => $a->starts_at->toIso8601String(),
                'location' => $a->location, 'attendee' => $a->attendee_name, 'phone' => $a->attendee_phone]),
            'follow_ups' => $followUps->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'phone' => $l->phone, 'interest' => $l->interest_description,
                'score' => $l->score, 'last_contacted_at' => $l->last_contacted_at?->toIso8601String(), 'created_at' => $l->created_at->toIso8601String()]),
            'uploads_to_file' => $toFile, 'approvals' => $approvals,
        ];
    }

    /** ⌘K search across everything the user is allowed to see. */
    public function search(Request $request)
    {
        $user = $request->user();
        $term = trim((string) $request->input('q'));
        if (mb_strlen($term) < 2) {
            return ['data' => []];
        }
        $like = '%'.$term.'%';
        $out = [];
        $staffRole = $user->hasAnyRole(['admin', 'branch_manager', 'hr', 'finance', 'agent']);
        if ($user->hasAnyRole(['admin', 'branch_manager', 'agent'])) {
            foreach (DealController::scoped($request)->with('stage')->where('property_title', 'like', $like)->limit(5)->get() as $d) {
                $out[] = ['group' => 'Deals', 'title' => $d->property_title, 'sub' => $d->stage?->name.' · R'.number_format((float) $d->sale_value), 'url' => '/sales/DealDetail.html?id='.$d->id];
            }
            $leads = Scope::apply(Lead::query(), $user, fn ($q, $id) => $q->where('assigned_agent_id', $id), fn ($q, $b) => $q->whereHas('agent', fn ($a) => $a->where('branch_id', $b)));
            foreach ($leads->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('email', 'like', $like))->limit(5)->get() as $l) {
                $out[] = ['group' => 'Leads', 'title' => $l->name, 'sub' => trim($l->phone.' · '.$l->interest_description, ' ·'),
                    'url' => ($user->hasRole('agent') ? '/portal/MyLeads.html' : '/sales/Leads.html').'?search='.urlencode($l->name)];
            }
            $listings = Scope::apply(Listing::query(), $user, fn ($q, $id) => $q->where('agent_id', $id));
            foreach ($listings->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('suburb', 'like', $like))->limit(5)->get() as $l) {
                $out[] = ['group' => 'Listings', 'title' => $l->title, 'sub' => $l->suburb.' · R'.number_format((float) $l->price), 'url' => '/sales/Brochure.html?id='.$l->id];
            }
        }
        if ($user->hasAnyRole(['admin', 'hr', 'branch_manager'])) {
            foreach (Staff::with('user')->whereHas('user', fn ($u) => $u->where('name', 'like', $like))
                ->when($user->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', $user->branch_id))->limit(5)->get() as $s) {
                $out[] = ['group' => 'People', 'title' => $s->user->name, 'sub' => $s->role_title, 'url' => '/hr/StaffProfile.html?id='.$s->id];
            }
        }
        if ($user->hasAnyRole(['admin', 'finance'])) {
            foreach (Invoice::with('contact')->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('reference', 'like', $like)
                ->orWhereHas('contact', fn ($c) => $c->where('name', 'like', $like)))->latest('date')->limit(5)->get() as $i) {
                $out[] = ['group' => 'Invoices', 'title' => $i->number.' · '.$i->contact->name, 'sub' => 'R'.number_format($i->total, 2).' · '.$i->status, 'url' => '/finance/Document.html?kind=invoices&id='.$i->id];
            }
            foreach (Contact::where('name', 'like', $like)->limit(4)->get() as $c) {
                $out[] = ['group' => 'Contacts', 'title' => $c->name, 'sub' => ucfirst($c->type), 'url' => '/finance/Contacts.html'];
            }
        }
        if ($staffRole) {
            foreach (DocumentController::scoped($user)->where('name', 'like', $like)->limit(6)->get() as $d) {
                $out[] = ['group' => 'Documents', 'title' => $d->name, 'sub' => 'v'.$d->version, 'url' => '/docs/Records.html?doc='.$d->id.($d->folder_id ? '&folder='.$d->folder_id : '')];
            }
        }

        return ['data' => $out];
    }

    public function comments(Request $request, Deal $deal)
    {
        abort_unless(DealController::scoped($request)->whereKey($deal->id)->exists(), 404);

        return ['data' => DealComment::with('user')->where('deal_id', $deal->id)->latest()->limit(100)->get()->map(fn ($c) => [
            'id' => $c->id, 'body' => $c->body, 'by' => $c->user?->name, 'initials' => $c->user?->initials(), 'at' => $c->created_at->toIso8601String(),
            'mine' => $c->user_id === $request->user()->id,
        ])];
    }

    /** "@Naledi Mokoena can you chase the FICA?" — mentioned users and the deal's agents are notified. */
    public function addComment(Request $request, Deal $deal, Notifier $notifier)
    {
        abort_unless(DealController::scoped($request)->whereKey($deal->id)->exists(), 404);
        $body = $request->validate(['body' => ['required', 'string', 'max:3000']])['body'];
        $mentioned = User::where('status', 'active')->get()->filter(fn ($u) => mb_stripos($body, '@'.$u->name) !== false);
        $comment = DealComment::create(['deal_id' => $deal->id, 'user_id' => $request->user()->id, 'body' => $body, 'mentions' => $mentioned->pluck('id')->values()]);
        $url = '/sales/DealDetail.html?id='.$deal->id;
        $by = $request->user()->name;
        $notifier->send($mentioned, 'mention', $by.' mentioned you on '.$deal->property_title, mb_substr($body, 0, 140), $url);
        $agents = User::whereIn('id', Staff::whereIn('id', array_filter([$deal->selling_agent_id, $deal->listing_agent_id]))->pluck('user_id'))->get()
            ->reject(fn ($u) => $mentioned->contains('id', $u->id));
        $notifier->send($agents, 'comment', $by.' commented on '.$deal->property_title, mb_substr($body, 0, 140), $url);

        return ['id' => $comment->id];
    }

    /** Monthly targets vs actuals. Agents see their own; managers their branch; admins everyone. */
    public function targets(Request $request)
    {
        $user = $request->user();
        $period = $request->input('period', now()->format('Y-m'));
        [$from, $to] = [Carbon::parse($period.'-01')->startOfMonth(), Carbon::parse($period.'-01')->endOfMonth()];
        $agents = Staff::with('user')->where('department', 'sales')->where('status', '!=', 'terminated')
            ->when($user->hasRole('agent'), fn ($q) => $q->where('id', $user->staff?->id ?? 0))
            ->when($user->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', $user->branch_id))->get();
        $targets = AgentTarget::where('period', $period)->whereIn('staff_id', $agents->pluck('id'))->get()->keyBy('staff_id');

        return ['period' => $period, 'can_edit' => $user->hasAnyRole(['admin', 'branch_manager']), 'data' => $agents->sortBy('user.name')->values()->map(function ($s) use ($targets, $from, $to) {
            $closed = Deal::forAgent($s->id)->where('status', 'closed')->whereBetween('closed_at', [$from, $to])->get();
            $t = $targets->get($s->id);

            return [
                'staff_id' => $s->id, 'name' => $s->user->name,
                'deals' => $closed->count(), 'deals_target' => $t?->deals_target ?? 0,
                'value' => (float) $closed->sum('sale_value'), 'value_target' => $t?->value_target ?? 0,
                'listings' => Listing::where('agent_id', $s->id)->whereBetween('created_at', [$from, $to])->count(), 'listings_target' => $t?->listings_target ?? 0,
            ];
        })];
    }

    public function saveTarget(Request $request)
    {
        $data = $request->validate([
            'staff_id' => ['required', 'exists:staff,id'], 'period' => ['required', 'date_format:Y-m'],
            'deals_target' => ['required', 'integer', 'min:0'], 'value_target' => ['required', 'numeric', 'min:0'], 'listings_target' => ['required', 'integer', 'min:0'],
        ]);
        $user = $request->user();
        abort_if($user->hasRole('branch_manager') && Staff::find($data['staff_id'])->branch_id !== $user->branch_id, 403);

        return AgentTarget::updateOrCreate(['staff_id' => $data['staff_id'], 'period' => $data['period']], $data);
    }
}
