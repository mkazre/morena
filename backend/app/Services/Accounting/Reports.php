<?php

namespace App\Services\Accounting;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingSetting;
use App\Models\Accounting\Bill;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\JournalLine;
use App\Models\Accounting\TaxRate;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Financial statements computed live from posted journal lines (voided entries excluded).
 * Amounts are "natural" balances: positive in the account's normal direction.
 */
class Reports
{
    public const GROUPS = [
        'asset' => ['Current assets' => ['bank', 'receivable', 'current_asset', 'vat_input'], 'Non-current assets' => ['fixed_asset']],
        'liability' => ['Current liabilities' => ['payable', 'current_liability', 'vat_output'], 'Non-current liabilities' => ['long_term_liability']],
    ];

    /** Sum of debits − credits per account for posted entries with $from ≤ date ≤ $to (either bound optional). */
    public function movements(?string $from, ?string $to): Collection
    {
        return JournalLine::query()
            ->join('journal_entries as je', 'je.id', '=', 'journal_lines.journal_entry_id')
            ->where('je.status', 'posted')
            ->when($from, fn ($q) => $q->where('je.date', '>=', $from))
            ->when($to, fn ($q) => $q->where('je.date', '<=', $to))
            ->groupBy('journal_lines.account_id')
            ->select('journal_lines.account_id', DB::raw('SUM(journal_lines.debit) as dr'), DB::raw('SUM(journal_lines.credit) as cr'))
            ->get()->keyBy('account_id')
            ->map(fn ($r) => round((float) $r->dr - (float) $r->cr, 2));
    }

    private function natural(Account $a, float $debitMinusCredit): float
    {
        return round($a->isDebitNormal() ? $debitMinusCredit : -$debitMinusCredit, 2);
    }

    private function accounts(): Collection
    {
        return Account::orderBy('code')->get();
    }

    public function trialBalance(string $asAt): array
    {
        $m = $this->movements(null, $asAt);
        $rows = $this->accounts()->filter(fn ($a) => abs($m[$a->id] ?? 0) >= 0.005)->map(fn ($a) => [
            'account_id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type,
            'debit' => max($m[$a->id], 0), 'credit' => max(-$m[$a->id], 0),
        ])->values();

        return [
            'as_at' => $asAt, 'rows' => $rows,
            'total_debit' => round($rows->sum('debit'), 2), 'total_credit' => round($rows->sum('credit'), 2),
        ];
    }

    public function profitAndLoss(string $from, string $to, bool $compare = false): array
    {
        $current = $this->plFor($from, $to);
        if (! $compare) {
            return $current + ['from' => $from, 'to' => $to];
        }
        $days = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        $pTo = Carbon::parse($from)->subDay();
        $pFrom = $pTo->copy()->subDays($days - 1);
        $previous = $this->plFor($pFrom->toDateString(), $pTo->toDateString());

        return $current + ['from' => $from, 'to' => $to, 'previous' => $previous + ['from' => $pFrom->toDateString(), 'to' => $pTo->toDateString()]];
    }

    private function plFor(string $from, string $to): array
    {
        $m = $this->movements($from, $to);
        $section = function (array $subtypes) use ($m) {
            $rows = $this->accounts()->whereIn('subtype', $subtypes)->map(fn ($a) => [
                'account_id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'amount' => $this->natural($a, $m[$a->id] ?? 0),
            ])->filter(fn ($r) => abs($r['amount']) >= 0.005)->values();

            return ['rows' => $rows, 'total' => round($rows->sum('amount'), 2)];
        };
        $income = $section(['revenue']);
        $cos = $section(['cost_of_sales']);
        $opex = $section(['operating_expense']);
        $otherIncome = $section(['other_income']);
        $otherExpense = $section(['other_expense']);
        $gross = round($income['total'] - $cos['total'], 2);
        $operating = round($gross - $opex['total'], 2);
        $net = round($operating + $otherIncome['total'] - $otherExpense['total'], 2);

        return [
            'income' => $income, 'cost_of_sales' => $cos, 'gross_profit' => $gross,
            'operating_expenses' => $opex, 'operating_profit' => $operating,
            'other_income' => $otherIncome, 'other_expenses' => $otherExpense, 'net_profit' => $net,
        ];
    }

    private function netProfit(?string $from, string $to): float
    {
        $m = $this->movements($from, $to);

        return round(-$this->accounts()->whereIn('type', ['income', 'expense'])->sum(fn ($a) => $m[$a->id] ?? 0), 2);
    }

    public function balanceSheet(string $asAt): array
    {
        $m = $this->movements(null, $asAt);
        $yearStart = AccountingSetting::current()->yearStart(Carbon::parse($asAt));
        $groups = [];
        foreach (self::GROUPS as $type => $sections) {
            foreach ($sections as $label => $subtypes) {
                $rows = $this->accounts()->whereIn('subtype', $subtypes)->map(fn ($a) => [
                    'account_id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'amount' => $this->natural($a, $m[$a->id] ?? 0),
                ])->filter(fn ($r) => abs($r['amount']) >= 0.005)->values();
                $groups[$type][] = ['label' => $label, 'rows' => $rows, 'total' => round($rows->sum('amount'), 2)];
            }
        }

        // Equity: posted equity accounts + profits not yet closed off (prior years → retained earnings; this year → current earnings).
        $equityRows = $this->accounts()->where('type', 'equity')->map(fn ($a) => [
            'account_id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'amount' => $this->natural($a, $m[$a->id] ?? 0),
        ])->values();
        $prior = $this->netProfit(null, $yearStart->subDay()->toDateString());
        $current = $this->netProfit($yearStart->toDateString(), $asAt);
        $equityRows = $equityRows->map(function ($r) use ($prior) {
            if (Account::find($r['account_id'])->subtype === 'retained_earnings') {
                $r['amount'] = round($r['amount'] + $prior, 2);
            }

            return $r;
        })->filter(fn ($r) => abs($r['amount']) >= 0.005)->values();
        $equityRows->push(['account_id' => null, 'code' => '', 'name' => 'Current year earnings', 'amount' => $current]);

        $assets = round(collect($groups['asset'])->sum('total'), 2);
        $liabilities = round(collect($groups['liability'])->sum('total'), 2);
        $equity = round($equityRows->sum('amount'), 2);

        return [
            'as_at' => $asAt, 'financial_year_start' => $yearStart->toDateString(),
            'assets' => $groups['asset'], 'total_assets' => $assets,
            'liabilities' => $groups['liability'], 'total_liabilities' => $liabilities,
            'equity' => ['rows' => $equityRows, 'total' => $equity],
            'total_liabilities_and_equity' => round($liabilities + $equity, 2),
            'balanced' => abs($assets - $liabilities - $equity) < 0.01,
        ];
    }

    /** Indirect method: net profit adjusted for balance-sheet movements, classified by account subtype. */
    public function cashFlow(string $from, string $to): array
    {
        $opening = $this->movements(null, Carbon::parse($from)->subDay()->toDateString());
        $closing = $this->movements(null, $to);
        $profit = $this->netProfit($from, $to);
        $sections = ['operating' => [], 'investing' => [], 'financing' => []];
        $cashOpen = 0;
        $cashClose = 0;

        foreach ($this->accounts()->whereIn('type', ['asset', 'liability', 'equity']) as $a) {
            $o = $opening[$a->id] ?? 0;
            $c = $closing[$a->id] ?? 0;
            if ($a->subtype === 'bank') {
                $cashOpen += $o;
                $cashClose += $c;

                continue;
            }
            $effect = round(-($c - $o), 2); // an increase in a debit balance uses cash
            if (abs($effect) < 0.005) {
                continue;
            }
            $bucket = match ($a->subtype) {
                'fixed_asset' => 'investing',
                'long_term_liability', 'equity', 'retained_earnings' => 'financing',
                default => 'operating',
            };
            $sections[$bucket][] = ['code' => $a->code, 'name' => $a->name, 'amount' => $effect];
        }
        $op = round($profit + collect($sections['operating'])->sum('amount'), 2);
        $inv = round(collect($sections['investing'])->sum('amount'), 2);
        $fin = round(collect($sections['financing'])->sum('amount'), 2);

        return [
            'from' => $from, 'to' => $to, 'net_profit' => $profit,
            'operating' => ['rows' => $sections['operating'], 'total' => $op],
            'investing' => ['rows' => $sections['investing'], 'total' => $inv],
            'financing' => ['rows' => $sections['financing'], 'total' => $fin],
            'net_change' => round($op + $inv + $fin, 2),
            'opening_cash' => round($cashOpen, 2), 'closing_cash' => round($cashClose, 2),
        ];
    }

    public function generalLedger(int $accountId, string $from, string $to): array
    {
        $account = Account::findOrFail($accountId);
        $openingDm = $this->movements(null, Carbon::parse($from)->subDay()->toDateString())[$account->id] ?? 0;
        $running = $this->natural($account, $openingDm);
        $opening = $running;
        $lines = JournalLine::with(['entry', 'contact'])
            ->join('journal_entries as je', 'je.id', '=', 'journal_lines.journal_entry_id')
            ->where('je.status', 'posted')->where('journal_lines.account_id', $account->id)
            ->whereBetween('je.date', [$from, $to])
            ->orderBy('je.date')->orderBy('je.id')->select('journal_lines.*')->get()
            ->map(function ($l) use (&$running, $account) {
                $running = round($running + $this->natural($account, $l->debit - $l->credit), 2);

                return [
                    'date' => $l->entry->date->toDateString(), 'entry_id' => $l->journal_entry_id, 'number' => $l->entry->number,
                    'source' => $l->entry->sourceLabel(), 'reference' => $l->entry->reference,
                    'description' => $l->description ?: $l->entry->memo, 'contact' => $l->contact?->name,
                    'debit' => $l->debit, 'credit' => $l->credit, 'balance' => $running,
                ];
            });

        return [
            'account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'type' => $account->type],
            'from' => $from, 'to' => $to, 'opening_balance' => $opening, 'lines' => $lines, 'closing_balance' => $running,
        ];
    }

    /** Debtors (invoices) or creditors (bills) outstanding at $asAt, bucketed by days past due. */
    public function aging(string $kind, string $asAt): array
    {
        $class = $kind === 'payables' ? Bill::class : Invoice::class;
        $at = Carbon::parse($asAt);
        $buckets = ['current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'd90plus' => 0];
        $byContact = [];
        foreach ($class::with('contact')->whereIn('status', ['approved', 'partial'])->where('date', '<=', $asAt)->get() as $doc) {
            $due = $doc->balanceDue();
            if ($due < 0.005) {
                continue;
            }
            $late = $doc->due_date->lt($at) ? (int) $doc->due_date->diffInDays($at) : 0;
            $b = match (true) { $late <= 0 => 'current', $late <= 30 => 'd30', $late <= 60 => 'd60', $late <= 90 => 'd90', default => 'd90plus' };
            $id = $doc->contact_id;
            $byContact[$id] ??= ['contact_id' => $id, 'contact' => $doc->contact->name] + $buckets + ['total' => 0];
            $byContact[$id][$b] = round($byContact[$id][$b] + $due, 2);
            $byContact[$id]['total'] = round($byContact[$id]['total'] + $due, 2);
            $buckets[$b] = round($buckets[$b] + $due, 2);
        }
        $rows = collect($byContact)->sortByDesc('total')->values();

        return ['kind' => $kind, 'as_at' => $asAt, 'rows' => $rows, 'totals' => $buckets + ['total' => round(array_sum($buckets), 2)]];
    }

    /** VAT201 summary for a period. Verify against SARS eFiling before submitting. */
    public function vat(string $from, string $to): array
    {
        $rates = TaxRate::all()->keyBy('id');
        $lines = JournalLine::query()
            ->join('journal_entries as je', 'je.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'journal_lines.account_id')
            ->where('je.status', 'posted')->whereBetween('je.date', [$from, $to])
            ->whereNotNull('journal_lines.tax_rate_id')
            ->select('journal_lines.*', 'a.type as account_type', 'a.subtype as account_subtype')->get();

        // Supplies (sales) by rate, from income lines; purchases by rate, from expense/asset lines.
        $supplies = ['standard' => 0, 'zero' => 0, 'exempt' => 0];
        $purchases = ['standard' => 0, 'zero' => 0, 'exempt' => 0];
        $outputVat = 0;
        $inputVat = 0;
        foreach ($lines as $l) {
            $code = $rates[$l->tax_rate_id]->code ?? 'NONE';
            $bucket = ['STD' => 'standard', 'ZERO' => 'zero', 'EXEMPT' => 'exempt'][$code] ?? null;
            if ($l->account_subtype === 'vat_output') {
                $outputVat += $l->credit - $l->debit;
            } elseif ($l->account_subtype === 'vat_input') {
                $inputVat += $l->debit - $l->credit;
            } elseif ($bucket && $l->account_type === 'income') {
                $supplies[$bucket] += $l->credit - $l->debit;
            } elseif ($bucket && in_array($l->account_type, ['expense', 'asset'], true)) {
                $purchases[$bucket] += $l->debit - $l->credit;
            }
        }
        $outputVat = round($outputVat, 2);
        $inputVat = round($inputVat, 2);
        $std = round($supplies['standard'], 2);

        return [
            'from' => $from, 'to' => $to,
            'supplies' => [
                'standard_excl' => $std, 'standard_incl' => round($std + $outputVat, 2),
                'zero_rated' => round($supplies['zero'], 2), 'exempt' => round($supplies['exempt'], 2),
            ],
            'output_vat' => $outputVat,
            'purchases' => ['standard_excl' => round($purchases['standard'], 2), 'zero_rated' => round($purchases['zero'], 2), 'exempt' => round($purchases['exempt'], 2)],
            'input_vat' => $inputVat,
            'net_vat' => round($outputVat - $inputVat, 2), // positive = payable to SARS, negative = refund due
        ];
    }

    /** Natural balance of one account at a date (used for bank balances & dashboard). */
    public function balance(Account $a, ?string $asAt = null): float
    {
        return $this->natural($a, $this->movements(null, $asAt ?? now()->toDateString())[$a->id] ?? 0);
    }
}
