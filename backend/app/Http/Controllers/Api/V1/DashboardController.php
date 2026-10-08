<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DealResource;
use App\Models\Branch;
use App\Models\ClientUploadLink;
use App\Models\Commission;
use App\Models\Contract;
use App\Models\Course;
use App\Models\Deal;
use App\Models\Document;
use App\Models\DocumentTemplateVersion;
use App\Models\Lead;
use App\Models\LeaveRequest;
use App\Models\Listing;
use App\Models\PipelineStage;
use App\Models\Staff;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /** GET /dashboard/admin?period=month|last_month|quarter|ytd — admin/Dashboard. */
    public function admin(Request $request)
    {
        // [from, to, previous-from, previous-to, label, comparison]
        [$from, $to, $pFrom, $pTo, $label, $vs] = match ($request->query('period')) {
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->startOfMonth(), now()->subMonthsNoOverflow(2)->startOfMonth(), now()->subMonthNoOverflow()->startOfMonth(), 'last month', 'month before'],
            'quarter' => [now()->startOfQuarter(), now(), now()->subQuarterNoOverflow()->startOfQuarter(), now()->subQuarterNoOverflow(), 'QTD', 'last quarter'],
            'ytd' => [now()->startOfYear(), now(), now()->subYear()->startOfYear(), now()->subYear(), 'YTD', 'last year'],
            default => [now()->startOfMonth(), now(), now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow(), 'MTD', 'last month'],
        };

        $closedMtd = Deal::where('status', 'closed')->whereBetween('closed_at', [$from, $to])->count();
        $closedLast = Deal::where('status', 'closed')->whereBetween('closed_at', [$pFrom, $pTo])->count();
        $open = Deal::where('status', 'open');
        $pending = Commission::where('status', 'pending')->get();
        $activeListings = Listing::where('status', 'active')->count();
        $newListings = Listing::where('status', 'active')->where('created_at', '>=', now()->subWeek())->count();

        $byStage = Deal::where('status', 'open')->selectRaw('pipeline_stage_id, count(*) as n, sum(sale_value) as v')
            ->groupBy('pipeline_stage_id')->get()->keyBy('pipeline_stage_id');
        $maxValue = max((float) $byStage->max('v'), 1);

        $agentsMtd = $this->agentPerformance(Staff::where('department', 'sales'), $from, $to)->sortByDesc('commission')->take(5)->values();

        $ficaExpiring = Document::whereHas('category', fn ($c) => $c->where('name', 'like', '%FICA%'))
            ->whereBetween('retention_expires_at', [now(), now()->addDays(90)])->count();
        $leavePending = LeaveRequest::where('status', 'pending')->count();
        $toFile = ClientUploadLink::where('status', 'uploaded')->count();
        $renewals = Contract::where('status', 'renewal_due')->count();

        return [
            'period_label' => $label,
            'kpis' => [
                ['label' => "Deals closed ({$label})", 'value' => $closedMtd, 'format' => 'int',
                    'delta' => self::signed($closedMtd - $closedLast).' vs '.$vs, 'tone' => $closedMtd >= $closedLast ? 'up' : 'down'],
                ['label' => 'Pipeline value', 'value' => (float) (clone $open)->sum('sale_value'), 'format' => 'rand_short',
                    'delta' => (clone $open)->count().' active deals', 'tone' => 'neutral'],
                ['label' => 'Commission payable', 'value' => round($pending->sum(fn ($c) => $c->grossAmount()), 2), 'format' => 'rand_short',
                    'delta' => $pending->count().' awaiting payout', 'tone' => 'warn'],
                ['label' => 'Active listings', 'value' => $activeListings, 'format' => 'int',
                    'delta' => self::signed($newListings).' this week', 'tone' => $newListings > 0 ? 'up' : 'neutral'],
            ],
            'pipeline' => PipelineStage::ordered()->get()->filter(fn ($s) => $byStage->has($s->id))->values()->map(fn ($s) => [
                'name' => $s->name, 'color' => $s->color_hex,
                'count' => (int) $byStage[$s->id]->n, 'value' => (float) $byStage[$s->id]->v,
                'pct' => round((float) $byStage[$s->id]->v / $maxValue * 100),
            ]),
            'top_agents' => $agentsMtd,
            'recent_deals' => DealResource::collection(
                Deal::with(['stage', 'sellingAgent.user', 'listingAgent.user'])->latest('updated_at')->limit(5)->get()
            ),
            'alerts' => array_values(array_filter([
                $ficaExpiring ? ['title' => $ficaExpiring.' FICA documents expiring', 'subtitle' => 'Retention deadline within 90 days', 'tone' => 'warn', 'href' => '../docs/Retention.html'] : null,
                $leavePending ? ['title' => $leavePending.' leave request'.($leavePending > 1 ? 's' : '').' pending', 'subtitle' => 'Awaiting approval', 'tone' => 'brand', 'href' => '../hr/Leave.html'] : null,
                $pending->count() ? ['title' => 'Commission run pending', 'subtitle' => 'R '.number_format($pending->sum(fn ($c) => $c->grossAmount())).' across '.$pending->pluck('agent_id')->unique()->count().' agents', 'tone' => 'ok', 'href' => '../sales/Commissions.html'] : null,
                $toFile ? ['title' => $toFile.' client upload'.($toFile > 1 ? 's' : '').' to file', 'subtitle' => 'Documents submitted via upload links', 'tone' => 'danger', 'href' => '../docs/UploadLinks.html'] : null,
                $renewals ? ['title' => $renewals.' contract'.($renewals > 1 ? 's' : '').' due for renewal', 'subtitle' => 'HR contracts register', 'tone' => 'warn', 'href' => '../hr/Contracts.html'] : null,
            ])),
        ];
    }

    /** GET /dashboard/agent — portal/Dashboard (scoped to the signed-in agent). */
    public function agent(Request $request)
    {
        $staff = $request->user()->staff;
        abort_unless($staff, 404);
        $month = now()->startOfMonth();

        $openDeals = Deal::forAgent($staff->id)->where('status', 'open')->with('stage')->latest('updated_at')->get();
        $mtd = Commission::involvingAgent($staff->id)
            ->where(fn ($q) => $q->where('status', 'pending')->orWhere('paid_at', '>=', $month))->get()
            ->sum(fn ($c) => $c->shareFor($staff->id));
        $newLeads = Lead::where('assigned_agent_id', $staff->id)->where('status', 'new')->count();
        $done = $staff->courses()->wherePivot('status', 'complete')->count();
        $trainingDue = max(Course::count() - $done, 0);

        $reminders = collect();
        ClientUploadLink::where('agent_id', $staff->id)->whereIn('status', ['uploaded', 'awaiting_client'])->latest()->limit(3)->get()
            ->each(fn ($l) => $reminders->push($l->status === 'uploaded'
                ? ['title' => 'Documents from '.$l->client_name.' ready to file', 'when' => 'Submitted '.$l->updated_at->diffForHumans()]
                : ['title' => 'Upload link awaiting '.$l->client_name, 'when' => 'Expires '.$l->expires_at->diffForHumans()]));
        if ($cert = $staff->certification) {
            $reminders->push(['title' => "CPD points: {$cert->cpd_points} of {$cert->cpd_target} this cycle", 'when' => 'Deadline 31 Dec']);
        }
        DocumentTemplateVersion::with('template')->where('created_at', '>=', now()->subDays(30))->latest()->limit(2)->get()
            ->each(fn ($v) => $reminders->push(['title' => $v->template->name.' template updated', 'when' => 'v'.$v->version_number.' published']));

        return [
            'first_name' => strtok($request->user()->name, ' '),
            'stats' => [
                'active_deals' => $openDeals->count(),
                'commission_mtd' => round($mtd, 2),
                'new_leads' => $newLeads,
                'training_due' => $trainingDue,
            ],
            'deals' => $openDeals->take(5)->map(fn ($d) => ['id' => $d->id, 'property' => $d->property_title, 'stage' => $d->stage->name, 'value' => (float) $d->sale_value])->values(),
            'reminders' => $reminders->values(),
        ];
    }

    /** GET /dashboard/manager — manager/Dashboard and manager/Team (scoped to the manager's branch). */
    public function manager(Request $request)
    {
        $user = $request->user();
        $branchId = $user->hasRole('admin') && $request->filled('branch_id') ? $request->integer('branch_id') : $user->branch_id;
        $agents = Staff::where('department', 'sales')->where('branch_id', $branchId)->where('status', '!=', 'terminated');
        $month = now()->startOfMonth();
        $year = now()->startOfYear();
        $courseCount = Course::count();

        $team = (clone $agents)->with(['user', 'courses'])->get()->map(function (Staff $s) use ($year, $courseCount) {
            $ytd = Deal::forAgent($s->id)->where('status', 'closed')->where('closed_at', '>=', $year)->count();
            $commission = Commission::involvingAgent($s->id)->where('status', 'paid')->where('paid_at', '>=', $year)->get()->sum(fn ($c) => $c->shareFor($s->id));
            $complete = $s->courses->where('pivot.status', 'complete')->count();
            $started = $s->courses->whereIn('pivot.status', ['in_progress', 'complete'])->count();

            return [
                'name' => $s->user->name,
                'deals_ytd' => $ytd,
                'commission_ytd' => round($commission, 2),
                'pipeline' => (float) Deal::forAgent($s->id)->where('status', 'open')->sum('sale_value'),
                'training' => $complete >= $courseCount ? 'up_to_date' : ($started === 0 ? 'not_started' : ($courseCount - $complete === 1 ? 'one_pending' : 'in_progress')),
                'training_pending' => max($courseCount - $complete, 0),
            ];
        })->sortByDesc('deals_ytd')->values();

        return [
            'branch' => Branch::find($branchId)?->name,
            'stats' => [
                'deals_mtd' => Deal::forBranch($branchId)->where('status', 'closed')->where('closed_at', '>=', $month)->count(),
                'pipeline_value' => (float) Deal::forBranch($branchId)->where('status', 'open')->sum('sale_value'),
                'agents' => (clone $agents)->count(),
                'leave_pending' => LeaveRequest::where('status', 'pending')->whereHas('staff', fn ($s) => $s->where('branch_id', $branchId))->count(),
            ],
            'agents_mtd' => $this->agentPerformance(clone $agents, $month)->sortByDesc('commission')->values(),
            'team' => $team,
        ];
    }

    /** GET /dashboard/landlord — the seller's own sale. */
    public function landlord(Request $request)
    {
        $deal = Deal::where('seller_user_id', $request->user()->id)->with(['stage', 'listingAgent.user', 'activity'])->latest()->first();

        return [
            'name' => $request->user()->name,
            'sale' => $deal ? [
                'property' => $deal->property_title,
                'agent' => $deal->listingAgent?->user?->name,
                'agent_phone' => $deal->listingAgent?->user?->phone,
                'stage' => $deal->stage->name,
                'stage_color' => $deal->stage->color_hex,
                // Progress tracker: every stage, so the seller can see how far the sale has come.
                'stages' => PipelineStage::ordered()->get()->map(fn ($s) => ['name' => $s->name, 'position' => $s->position,
                    'done' => $s->position < $deal->stage->position, 'current' => $s->id === $deal->pipeline_stage_id])->values(),
                'expected_close' => $deal->expected_close_date?->toDateString(),
            ] : null,
            'updates' => $deal ? $deal->activity->take(5)->map(fn ($a) => ['text' => $a->text, 'at' => $a->created_at->toIso8601String()])->values() : [],
        ];
    }

    /** GET /dashboard/tenant */
    public function tenant(Request $request)
    {
        $t = $request->user()->tenancy;

        return [
            'name' => $request->user()->name,
            'tenancy' => $t ? ['property' => $t->property_title, 'lease_end' => $t->lease_end?->toDateString()] : null,
        ];
    }

    /** Deals closed + commission earned in [$since, $until], per agent. */
    private function agentPerformance($agentsQuery, $since, $until = null)
    {
        $until ??= now();

        return $agentsQuery->with('user')->get()->map(function (Staff $s) use ($since, $until) {
            $deals = Deal::forAgent($s->id)->where('status', 'closed')->whereBetween('closed_at', [$since, $until])->with('commission')->get();

            return [
                'initials' => $s->user->initials(),
                'name' => $s->user->name,
                'deals' => $deals->count(),
                'commission' => round($deals->sum(fn ($d) => $d->commission?->shareFor($s->id) ?? 0), 2),
            ];
        });
    }

    private static function signed(int $n): string
    {
        return ($n > 0 ? '+' : '').$n;
    }
}
