<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CommissionResource;
use App\Models\Commission;
use App\Support\Scope;
use Illuminate\Http\Request;

class CommissionController extends Controller
{
    /** GET /commissions?month=2026-10&agent_id= — ledger plus summary totals. */
    public function index(Request $request)
    {
        $user = $request->user();
        $byAgent = fn ($q, $id) => $q->involvingAgent($id);
        $byBranch = fn ($q, $branchId) => $q->whereHas('deal', fn ($d) => $d->where('branch_id', $branchId));
        $q = Scope::filters(Scope::apply(Commission::query(), $user, $byAgent, $byBranch), $request, $user, $byAgent, $byBranch)
            ->with(['deal', 'agent.user', 'coAgent.user', 'tier']);

        if ($request->filled('month')) {
            [$y, $m] = explode('-', $request->string('month'));
            $q->where(fn ($w) => $w->where('status', 'pending')
                ->orWhere(fn ($p) => $p->whereYear('paid_at', $y)->whereMonth('paid_at', $m)));
        }

        $rows = $q->orderByRaw("status = 'paid'")->latest('paid_at')->latest('id')->get();
        $staffId = Scope::isOrgWide($user) ? null : $user->staff?->id;
        $ytd = $rows->filter(fn ($c) => $c->status === 'paid' && $c->paid_at?->year === now()->year);
        $thisMonth = $rows->filter(fn ($c) => $c->status === 'paid' && $c->paid_at?->isSameMonth(now()));
        $pending = $rows->where('status', 'pending');
        $share = fn ($c) => $staffId ? $c->shareFor($staffId) : $c->grossAmount();

        return [
            'data' => CommissionResource::collection($rows),
            'summary' => [
                'paid_this_month' => round($thisMonth->sum($share), 2),
                'pending' => round($pending->sum($share), 2),
                'payable_this_month' => round($thisMonth->sum($share) + $pending->sum($share), 2),
                'agency_share' => round($thisMonth->sum('agency_share') + $pending->sum('agency_share'), 2),
                'paid_ytd' => round($ytd->sum($share), 2),
            ],
        ];
    }

    public function markPaid(Commission $commission)
    {
        abort_if($commission->status === 'paid', 422, 'Already paid.');
        $commission->update(['status' => 'paid', 'paid_at' => now()]);

        return new CommissionResource($commission->load(['deal', 'agent.user', 'coAgent.user', 'tier']));
    }
}
