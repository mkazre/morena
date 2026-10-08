<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Models\Accounting\AccountingSetting;
use App\Services\Accounting\Reports;
use Illuminate\Http\Request;

/** GET /finance/reports/{report}?from=&to=&as_at=&account_id=&compare=1&format=csv */
class ReportController extends Controller
{
    public function show(Request $request, string $report, Reports $reports)
    {
        $today = now()->toDateString();
        $yearStart = AccountingSetting::current()->yearStart(now())->toDateString();
        $from = $request->input('from', $yearStart);
        $to = $request->input('to', $today);
        $asAt = $request->input('as_at', $today);

        $data = match ($report) {
            'trial-balance' => $reports->trialBalance($asAt),
            'balance-sheet' => $reports->balanceSheet($asAt),
            'profit-loss' => $reports->profitAndLoss($from, $to, $request->boolean('compare')),
            'cash-flow' => $reports->cashFlow($from, $to),
            'general-ledger' => $reports->generalLedger($request->integer('account_id'), $from, $to),
            'aged-receivables' => $reports->aging('receivables', $asAt),
            'aged-payables' => $reports->aging('payables', $asAt),
            'vat' => $reports->vat($from, $to),
            default => abort(404, 'Unknown report.'),
        };

        return $request->input('format') === 'csv' ? $this->csv($report, $data) : $data;
    }

    private function csv(string $report, array $d)
    {
        $rows = [];
        $section = function (string $title, array $s) use (&$rows) {
            $rows[] = [$title];
            foreach ($s['rows'] as $r) {
                $rows[] = [$r['code'] ?? '', $r['name'], $r['amount']];
            }
            $rows[] = ['', 'Total '.$title, $s['total']];
            $rows[] = [];
        };
        switch ($report) {
            case 'trial-balance':
                $rows[] = ['Code', 'Account', 'Debit', 'Credit'];
                foreach ($d['rows'] as $r) {
                    $rows[] = [$r['code'], $r['name'], $r['debit'], $r['credit']];
                }
                $rows[] = ['', 'Total', $d['total_debit'], $d['total_credit']];
                break;
            case 'profit-loss':
                foreach (['income' => 'Income', 'cost_of_sales' => 'Cost of sales', 'operating_expenses' => 'Operating expenses', 'other_income' => 'Other income', 'other_expenses' => 'Other expenses'] as $k => $t) {
                    $section($t, $d[$k]);
                }
                $rows[] = ['', 'Net profit', $d['net_profit']];
                break;
            case 'balance-sheet':
                foreach (array_merge($d['assets'], $d['liabilities']) as $g) {
                    $section($g['label'], $g);
                }
                $section('Equity', $d['equity']);
                break;
            case 'general-ledger':
                $rows[] = ['Date', 'Entry', 'Source', 'Description', 'Contact', 'Debit', 'Credit', 'Balance'];
                $rows[] = [$d['from'], '', '', 'Opening balance', '', '', '', $d['opening_balance']];
                foreach ($d['lines'] as $l) {
                    $rows[] = [$l['date'], $l['number'], $l['source'], $l['description'], $l['contact'], $l['debit'], $l['credit'], $l['balance']];
                }
                break;
            case 'aged-receivables':
            case 'aged-payables':
                $rows[] = ['Contact', 'Current', '1–30', '31–60', '61–90', '90+', 'Total'];
                foreach ($d['rows'] as $r) {
                    $rows[] = [$r['contact'], $r['current'], $r['d30'], $r['d60'], $r['d90'], $r['d90plus'], $r['total']];
                }
                break;
            default:
                array_walk_recursive($d, function ($v, $k) use (&$rows) {
                    $rows[] = [$k, $v];
                });
        }
        $out = fopen('php://temp', 'r+');
        foreach ($rows as $r) {
            fputcsv($out, $r);
        }
        rewind($out);

        return response(stream_get_contents($out), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="morena-'.$report.'-'.now()->format('Ymd').'.csv"',
        ]);
    }
}
