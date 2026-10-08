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
