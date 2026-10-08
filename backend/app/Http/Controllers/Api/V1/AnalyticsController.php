<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Commission;
use App\Models\Deal;
use App\Models\DealStageHistory;
use App\Models\Lead;
use App\Models\Listing;
use App\Models\PipelineStage;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * GET /analytics?range=ytd|quarter|month|12m[&format=csv] — admin/Analytics (handover §8 field mapping).
 * Aggregation is done in PHP over the range's rows so it runs identically on SQLite, MySQL and PostgreSQL.
 */
class AnalyticsController extends Controller
{
    public const LOST_REASONS = [
        'bond_declined' => 'Bond declined', 'price_disagreement' => 'Price disagreement',
        'buyer_withdrew' => 'Buyer withdrew', 'chose_another_property' => 'Chose another property',
        'seller_withdrew_mandate' => 'Seller withdrew mandate',
    ];

    public function index(Request $request)
    {
        $range = in_array($request->query('range'), ['ytd', 'quarter', 'month', '12m']) ? $request->query('range') : 'ytd';
        [$from, $to] = self::window($range, now());
        [$pFrom, $pTo] = [$from->copy()->subYear(), $to->copy()->subYear()];

        $closed = $this->closedBetween($from, $to);
        $prior = $this->closedBetween($pFrom, $pTo);
        $leads = Lead::whereBetween('created_at', [$from, $to])->get();
        $priorLeads = Lead::whereBetween('created_at', [$pFrom, $pTo])->count();
        $paid = Commission::where('status', 'paid')->whereBetween('paid_at', [$from, $to])->get();

        $data = [
            'range' => $range,
            'kpis' => $this->kpis($closed, $prior, $leads->count(), $priorLeads, $paid),
            'months' => $this->months(),
            'funnel' => $this->funnel($from, $to, $leads->count()),
            'branches' => $this->branches($closed),
            'sources' => $this->share($leads->countBy('source'), Lead::SOURCES),
            'prop_types' => $this->share($closed->filter->listing->countBy(fn ($d) => $d->listing->property_type), Listing::PROPERTY_TYPES),
            'leaderboard' => $this->leaderboard($closed),
            'velocity' => $this->velocity($from, $to),
            'commission_hist' => $this->commissionHistory(),
            'inventory' => $this->inventory(),
            'lost_reasons' => $this->lostReasons(),
        ];

        return $request->query('format') === 'csv' ? $this->csv($data) : $data;
    }

    public static function window(string $range, Carbon $now): array
    {
        return match ($range) {
            'month' => [$now->copy()->startOfMonth(), $now->copy()],
            'quarter' => [$now->copy()->startOfQuarter(), $now->copy()],
            '12m' => [$now->copy()->subMonths(12), $now->copy()],
            default => [$now->copy()->startOfYear(), $now->copy()],
        };
    }

    private function closedBetween(Carbon $from, Carbon $to): Collection
    {
        return Deal::where('status', 'closed')->whereBetween('closed_at', [$from, $to])
            ->with(['listing', 'commission', 'sellingAgent.user', 'listingAgent.user', 'branch'])->get();
    }

    private function kpis(Collection $closed, Collection $prior, int $leads, int $priorLeads, Collection $paid): array
    {
        $value = (float) $closed->sum('sale_value');
        $pValue = (float) $prior->sum('sale_value');
        $avg = $closed->count() ? $value / $closed->count() : 0;
        $pAvg = $prior->count() ? $pValue / $prior->count() : 0;
        $days = fn (Collection $c) => $c->count() ? round($c->avg(fn ($d) => $d->created_at->diffInDays($d->closed_at))) : 0;
        $conv = $leads ? $closed->count() / $leads * 100 : 0;
        $pConv = $priorLeads ? $prior->count() / $priorLeads * 100 : 0;
        $commission = (float) $paid->sum('commission_amount');

        $pct = fn ($now, $was) => $was > 0 ? self::signedPct(($now - $was) / $was * 100).' vs last year' : 'No prior-year data';

        return [
            ['label' => 'Total sales value', 'value' => $value, 'format' => 'rand_short', 'delta' => $pct($value, $pValue), 'tone' => $value >= $pValue ? 'up' : 'down'],
            ['label' => 'Deals closed', 'value' => $closed->count(), 'format' => 'int', 'delta' => self::signed($closed->count() - $prior->count()).' vs last year', 'tone' => $closed->count() >= $prior->count() ? 'up' : 'down'],
            ['label' => 'Avg. deal size', 'value' => $avg, 'format' => 'rand_short', 'delta' => $pct($avg, $pAvg), 'tone' => $avg >= $pAvg ? 'up' : 'down'],
            // Fewer days to close is better.
            ['label' => 'Avg. days to close', 'value' => $days($closed), 'format' => 'int', 'delta' => self::signed($days($closed) - $days($prior)).' days vs last year', 'tone' => $days($closed) <= $days($prior) ? 'up' : 'down'],
            ['label' => 'Conversion (lead→deal)', 'value' => round($conv, 1), 'format' => 'pct', 'delta' => self::signed(round($conv - $pConv, 1)).'pp vs last year', 'tone' => $conv >= $pConv ? 'up' : 'down'],
            ['label' => 'Commission paid', 'value' => $commission, 'format' => 'rand_short', 'delta' => ($value > 0 ? round($commission / $value * 100, 1) : 0).'% effective rate', 'tone' => 'neutral'],
        ];
    }

    /** Last 12 calendar months, oldest first: closed value and count. */
    private function months(): array
    {
        $start = now()->startOfMonth()->subMonths(11);
        $deals = Deal::where('status', 'closed')->where('closed_at', '>=', $start)->get(['sale_value', 'closed_at']);
        $byMonth = $deals->groupBy(fn ($d) => $d->closed_at->format('Y-m'));

        return collect(range(0, 11))->map(function ($i) use ($start, $byMonth) {
            $m = $start->copy()->addMonths($i);
            $rows = $byMonth->get($m->format('Y-m'), collect());

            return ['label' => $m->format('M'), 'value' => (float) $rows->sum('sale_value'), 'count' => $rows->count()];
        })->all();
    }

    /** Distinct deals that reached each stage in the window (from deal_stage_history), ordered by position. */
    private function funnel(Carbon $from, Carbon $to, int $leadCount): array
    {
        $reached = DealStageHistory::whereBetween('entered_at', [$from, $to])->get()
            ->groupBy('pipeline_stage_id')->map(fn ($rows) => $rows->pluck('deal_id')->unique()->count());
        $stages = PipelineStage::ordered()->get();
        $rows = collect([['name' => 'Leads', 'count' => $leadCount]])
            ->concat($stages->map(fn ($s) => ['name' => $s->name, 'count' => (int) ($reached[$s->id] ?? 0)]));

        return $rows->values()->all();
    }

    private function branches(Collection $closed): array
    {
        return Branch::orderBy('name')->get()->map(fn ($b) => [
            'name' => $b->name,
            'count' => $closed->where('branch_id', $b->id)->count(),
            'value' => (float) $closed->where('branch_id', $b->id)->sum('sale_value'),
        ])->sortByDesc('value')->values()->all();
    }

    /** Normalise counts to whole-number percentages, keeping every known key. */
    private function share(Collection $counts, array $labels): array
    {
        $total = max($counts->sum(), 1);

        return collect($labels)->map(fn ($label, $key) => [
            'name' => $label, 'pct' => (int) round(($counts[$key] ?? 0) / $total * 100),
        ])->sortByDesc('pct')->values()->all();
    }

    private function leaderboard(Collection $closed): array
    {
        return $closed->groupBy(fn ($d) => $d->primaryAgent()?->id)->filter(fn ($_, $id) => $id)
            ->map(function (Collection $deals, $agentId) {
                $agent = $deals->first()->primaryAgent();

                return [
                    'name' => $agent->user->name,
                    'deals' => $deals->count(),
                    'value' => (float) $deals->sum('sale_value'),
                    'avg_days' => (int) round($deals->avg(fn ($d) => $d->created_at->diffInDays($d->closed_at))),
                    'commission' => round($deals->sum(fn ($d) => $d->commission?->shareFor((int) $agentId) ?? 0), 2),
                ];
            })->sortByDesc('value')->values()->take(10)->all();
    }

    /** Average days spent in each stage before moving to the next. */
    private function velocity(Carbon $from, Carbon $to): array
    {
        $stages = PipelineStage::ordered()->get()->values();
        $spans = DealStageHistory::whereNotNull('exited_at')->whereBetween('exited_at', [$from, $to])->get()
            ->groupBy('pipeline_stage_id')->map(fn ($rows) => round($rows->avg(fn ($r) => $r->entered_at->diffInHours($r->exited_at) / 24)));

        return $stages->slice(0, -1)->map(fn ($s, $i) => [
            'name' => $s->name.' → '.$stages[$i + 1]->name,
            'days' => $spans->has($s->id) ? (int) $spans[$s->id] : null,
        ])->values()->all();
    }

    private function commissionHistory(): array
    {
        $start = now()->startOfMonth()->subMonths(5);
        $paid = Commission::where('status', 'paid')->where('paid_at', '>=', $start)->get()->groupBy(fn ($c) => $c->paid_at->format('Y-m'));

        return collect(range(0, 5))->map(function ($i) use ($start, $paid) {
            $m = $start->copy()->addMonths($i);

            return ['label' => $m->format('M'), 'value' => round((float) $paid->get($m->format('Y-m'), collect())->sum('commission_amount'), 2)];
        })->all();
    }

    private function inventory(): array
    {
        $active = Listing::where('status', 'active')->get(['listed_at']);

        return [
            ['label' => 'Active listings', 'sub' => 'Currently on market', 'value' => $active->count(), 'tone' => 'neutral'],
            ['label' => 'Under offer', 'sub' => 'Awaiting registration', 'value' => Listing::where('status', 'under_offer')->count(), 'tone' => 'warn'],
            ['label' => 'Avg. days on market', 'sub' => 'Across active listings', 'value' => (int) round($active->filter->listed_at->avg(fn ($l) => $l->listed_at->diffInDays(now())) ?? 0), 'tone' => 'neutral'],
            ['label' => 'Expired mandates (90d)', 'sub' => 'Need renewal or relist', 'value' => Listing::where('status', 'active')->whereBetween('mandate_expiry', [now()->subDays(90), now()])->count(), 'tone' => 'danger'],
        ];
    }

    private function lostReasons(): array
    {
        $counts = Deal::where('status', 'lost')->where('updated_at', '>=', now()->subDays(90))->get()->countBy('lost_reason');

        return collect(self::LOST_REASONS)->map(fn ($label, $key) => ['name' => $label, 'count' => (int) ($counts[$key] ?? 0)])->values()->all();
    }

    private function csv(array $data)
    {
        $lines = [['Morena Properties analytics', strtoupper($data['range']), now()->toDateString()], []];
        $lines[] = ['KPI', 'Value', 'Change'];
        foreach ($data['kpis'] as $k) {
            $lines[] = [$k['label'], $k['value'], $k['delta']];
        }
        $lines[] = [];
        $lines[] = ['Agent', 'Deals', 'Value', 'Avg days', 'Commission'];
        foreach ($data['leaderboard'] as $a) {
            $lines[] = [$a['name'], $a['deals'], $a['value'], $a['avg_days'], $a['commission']];
        }
        $lines[] = [];
        $lines[] = ['Month', 'Sales value', 'Deals'];
        foreach ($data['months'] as $m) {
            $lines[] = [$m['label'], $m['value'], $m['count']];
        }

        $out = fopen('php://temp', 'r+');
        foreach ($lines as $l) {
            fputcsv($out, $l);
        }
        rewind($out);

        return response(stream_get_contents($out), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="morena-analytics-'.$data['range'].'-'.now()->format('Ymd').'.csv"',
        ]);
    }

    private static function signed(float|int $n): string
    {
        return ($n > 0 ? '+' : '').$n;
    }

    private static function signedPct(float $n): string
    {
        return self::signed((int) round($n)).'%';
    }
}
