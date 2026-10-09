<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingSetting;
use App\Models\Accounting\BankTransaction;
use App\Models\Accounting\Bill;
use App\Models\Accounting\Invoice;
use App\Services\Accounting\Reports;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Finance dashboard + accounting settings. */
class FinanceController extends Controller
{
    public function dashboard(Reports $reports)
    {
        $today = now();
        $settings = AccountingSetting::current();
        $yearStart = $settings->yearStart($today)->toDateString();
        $monthStart = $today->copy()->startOfMonth()->toDateString();
        $mtd = $reports->profitAndLoss($monthStart, $today->toDateString());
        $ytd = $reports->profitAndLoss($yearStart, $today->toDateString());
        $receivable = Invoice::whereIn('status', ['approved', 'partial'])->get();
        $payable = Bill::whereIn('status', ['approved', 'partial'])->get();

        // Income vs expenses for the last 6 months.
        $months = collect(range(5, 0))->map(function ($i) use ($reports, $today) {
            $s = $today->copy()->startOfMonth()->subMonths($i);
            $pl = $reports->profitAndLoss($s->toDateString(), $s->copy()->endOfMonth()->toDateString());

            return ['label' => $s->format('M'), 'income' => round($pl['income']['total'] + $pl['other_income']['total'], 2),
                'expenses' => round($pl['cost_of_sales']['total'] + $pl['operating_expenses']['total'] + $pl['other_expenses']['total'], 2)];
        });
        $vatQuarterStart = $today->copy()->subMonths(($today->month - 1) % 2)->startOfMonth()->toDateString(); // 2-monthly VAT periods (Category B)

        return [
            'bank_accounts' => Account::bank()->where('is_active', true)->orderBy('code')->get()->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'balance' => $reports->balance($a)]),
            'cash' => round(Account::bank()->get()->sum(fn ($a) => $reports->balance($a)), 2),
            'receivable' => round($receivable->sum(fn ($d) => $d->balanceDue()), 2),
            'receivable_overdue' => round($receivable->filter->isOverdue()->sum(fn ($d) => $d->balanceDue()), 2),
            'payable' => round($payable->sum(fn ($d) => $d->balanceDue()), 2),
            'payable_overdue' => round($payable->filter->isOverdue()->sum(fn ($d) => $d->balanceDue()), 2),
            'profit_mtd' => $mtd['net_profit'], 'income_mtd' => $mtd['income']['total'],
            'profit_ytd' => $ytd['net_profit'], 'income_ytd' => $ytd['income']['total'], 'year_start' => $yearStart,
            'vat_period' => $reports->vat($vatQuarterStart, $today->toDateString()),
            'unmatched_bank_lines' => BankTransaction::where('status', 'unmatched')->count(),
            'months' => $months,
            'top_expenses' => collect($ytd['operating_expenses']['rows'])->concat($ytd['cost_of_sales']['rows'])->sortByDesc('amount')->take(5)->values(),
            'lock_date' => $settings->lock_date?->toDateString(),
        ];
    }

    /**
     * GET /finance/dashboard/charts — data for the dashboard graphs: 12 months of income, expenses, net profit and
     * month-end cash; receivables/payables aging; where the money comes from and goes to this financial year;
     * top customers. Every figure comes from the ledger, so it always agrees with the reports.
     */
    public function charts(Reports $reports)
    {
        $today = now();
        $settings = AccountingSetting::current();
        $yearStart = $settings->yearStart($today)->toDateString();
        $banks = Account::bank()->get();

        $months = collect(range(11, 0))->map(function ($i) use ($reports, $today, $banks) {
            $s = $today->copy()->startOfMonth()->subMonths($i);
            $end = $i === 0 ? $today->toDateString() : $s->copy()->endOfMonth()->toDateString();
            $pl = $reports->profitAndLoss($s->toDateString(), $end);
            $income = $pl['income']['total'] + $pl['other_income']['total'];
            $expenses = $pl['cost_of_sales']['total'] + $pl['operating_expenses']['total'] + $pl['other_expenses']['total'];

            return ['label' => $s->format('M Y'), 'short' => $s->format('M'), 'income' => round($income, 2), 'expenses' => round($expenses, 2),
                'net' => round($income - $expenses, 2), 'cash' => round($banks->sum(fn ($a) => $reports->balance($a, $end)), 2)];
        })->values();

        $ytd = $reports->profitAndLoss($yearStart, $today->toDateString());
        $slices = function ($rows, int $n = 6) {
            $sorted = collect($rows)->filter(fn ($r) => abs($r['amount']) > 0.004)->sortByDesc('amount')->values();
            $top = $sorted->take($n)->map(fn ($r) => ['label' => $r['name'], 'value' => round($r['amount'], 2)]);
            $rest = round($sorted->slice($n)->sum('amount'), 2);

            return $rest > 0 ? $top->push(['label' => 'Other', 'value' => $rest])->values() : $top->values();
        };
        $bucket = fn (array $t) => [
            ['label' => 'Current', 'value' => $t['current']], ['label' => '1–30 days', 'value' => $t['d30']], ['label' => '31–60 days', 'value' => $t['d60']],
            ['label' => '61–90 days', 'value' => $t['d90']], ['label' => '90+ days', 'value' => $t['d90plus']],
        ];
        $customers = Invoice::with('contact')->whereNotIn('status', ['draft', 'void'])->whereBetween('date', [$yearStart, $today->toDateString()])->get()
            ->groupBy('contact_id')->map(fn ($g) => ['label' => $g->first()->contact->name, 'value' => round($g->sum('total'), 2)])
            ->sortByDesc('value')->take(8)->values();

        return [
            'year_start' => $yearStart, 'as_at' => $today->toDateString(),
            'months' => $months,
            'expenses_ytd' => $slices(collect($ytd['operating_expenses']['rows'])->concat($ytd['cost_of_sales']['rows'])->concat($ytd['other_expenses']['rows'])),
            'income_ytd' => $slices(collect($ytd['income']['rows'])->concat($ytd['other_income']['rows'])),
            'receivables_aging' => $bucket($reports->aging('receivables', $today->toDateString())['totals']),
            'payables_aging' => $bucket($reports->aging('payables', $today->toDateString())['totals']),
            'top_customers' => $customers,
            'totals' => ['income_ytd' => round($ytd['income']['total'] + $ytd['other_income']['total'], 2), 'net_ytd' => $ytd['net_profit'],
                'margin_ytd' => ($ytd['income']['total'] ?: 0) ? round($ytd['net_profit'] / $ytd['income']['total'] * 100, 1) : null],
        ];
    }

    public function settings()
    {
        $s = AccountingSetting::current();

        return $s->only(['financial_year_start_month', 'invoice_prefix', 'invoice_due_days', 'invoice_footer', 'auto_invoice_on_deal_close', 'default_bank_account_id'])
            + ['lock_date' => $s->lock_date?->toDateString()];
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'financial_year_start_month' => ['sometimes', 'integer', 'between:1,12'],
            'lock_date' => ['nullable', 'date', 'before_or_equal:today'],
            'invoice_prefix' => ['sometimes', 'string', 'max:10'],
            'invoice_due_days' => ['sometimes', 'integer', 'between:0,365'],
            'invoice_footer' => ['nullable', 'string', 'max:500'],
            'auto_invoice_on_deal_close' => ['sometimes', 'boolean'],
            'default_bank_account_id' => ['nullable', Rule::exists('accounts', 'id')->where('subtype', 'bank')],
        ]);
        if (array_key_exists('lock_date', $data) && $data['lock_date']) {
            $data['lock_date'] = Carbon::parse($data['lock_date'])->toDateString();
        }
        AccountingSetting::current()->update($data);

        return $this->settings();
    }
}
