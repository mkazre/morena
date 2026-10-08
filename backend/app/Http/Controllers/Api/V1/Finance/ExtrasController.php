<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Models\Accounting\Account;
use App\Models\Accounting\Bill;
use App\Models\Accounting\Budget;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\Payment;
use App\Models\Accounting\RecurringDocument;
use App\Models\Document;
use App\Services\Accounting\DocumentService;
use App\Services\Accounting\Reports;
use App\Services\FileStore;
use Carbon\Carbon;
use Database\Seeders\RecordsFolderSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Recurring invoices/bills, budgets (budget vs actual) and receipt capture on payments. */
class ExtrasController extends Controller
{
    // ---- recurring -------------------------------------------------------------------------------

    public function recurring()
    {
        return ['data' => RecurringDocument::with('contact')->orderBy('next_run_on')->get()->map(fn ($r) => [
            'id' => $r->id, 'kind' => $r->kind, 'contact' => $r->contact->name, 'contact_id' => $r->contact_id, 'reference' => $r->reference,
            'frequency' => $r->frequency, 'day_of_month' => $r->day_of_month, 'next_run_on' => $r->next_run_on->toDateString(),
            'ends_on' => $r->ends_on?->toDateString(), 'auto_approve' => $r->auto_approve, 'is_active' => $r->is_active, 'due_days' => $r->due_days,
            'lines' => $r->lines, 'amount' => round(collect($r->lines)->sum(fn ($l) => $l['quantity'] * $l['unit_price']), 2),
        ])];
    }

    public function saveRecurring(Request $request, ?RecurringDocument $recurring = null)
    {
        $data = $request->validate([
            'kind' => [$recurring ? 'sometimes' : 'required', Rule::in(['invoice', 'bill'])],
            'contact_id' => [$recurring ? 'sometimes' : 'required', 'exists:contacts,id'],
            'reference' => ['nullable', 'string', 'max:100'],
            'frequency' => ['sometimes', Rule::in(['monthly', 'quarterly', 'yearly'])],
            'day_of_month' => ['sometimes', 'integer', 'between:1,28'], 'due_days' => ['sometimes', 'integer', 'between:0,120'],
            'next_run_on' => [$recurring ? 'sometimes' : 'required', 'date'], 'ends_on' => ['nullable', 'date'],
            'auto_approve' => ['sometimes', 'boolean'], 'is_active' => ['sometimes', 'boolean'],
            'lines' => [$recurring ? 'sometimes' : 'required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:255'], 'lines.*.account_id' => ['required', 'exists:accounts,id'],
            'lines.*.tax_rate_id' => ['nullable', 'exists:tax_rates,id'], 'lines.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'lines.*.unit_price' => ['required', 'numeric'],
        ]);
        $recurring ? $recurring->update($data) : $recurring = RecurringDocument::create($data);

        return $recurring;
    }

    public function deleteRecurring(RecurringDocument $recurring)
    {
        $recurring->delete();

        return response()->noContent();
    }

    /** Raise every recurring document that is due (run daily by the scheduler; safe to re-run). */
    public static function runRecurring(?Carbon $today = null): int
    {
        $today ??= now()->startOfDay();
        $docs = app(DocumentService::class);
        $made = 0;
        foreach (RecurringDocument::where('is_active', true)->where('next_run_on', '<=', $today->toDateString())->get() as $r) {
            DB::transaction(function () use ($r, $docs, &$made) {
                $date = $r->next_run_on;
                $class = $r->kind === 'bill' ? Bill::class : Invoice::class;
                $doc = $docs->save(new $class, ['contact_id' => $r->contact_id, 'date' => $date->toDateString(),
                    'due_date' => $date->copy()->addDays($r->due_days)->toDateString(), 'reference' => $r->reference ? $r->reference.' '.$date->format('M Y') : null,
                    'lines' => $r->lines, 'notes' => 'Recurring '.$r->frequency.' '.$r->kind], null);
                if ($r->auto_approve) {
                    $docs->approve($doc, null);
                }
                $next = match ($r->frequency) { 'quarterly' => $date->copy()->addMonthsNoOverflow(3), 'yearly' => $date->copy()->addYear(), default => $date->copy()->addMonthNoOverflow() };
                $next = $next->day(min($r->day_of_month, $next->daysInMonth));
                $r->update(['next_run_on' => $next->toDateString(), 'is_active' => ! $r->ends_on || $next->lte($r->ends_on)]);
                $made++;
            });
        }

        return $made;
    }

    // ---- budgets ---------------------------------------------------------------------------------

    /** GET /finance/budgets?year=2026 → budget per account per month for the financial year starting in that year. */
    public function budgets(Request $request)
    {
        [$months] = $this->fyMonths($request->integer('year', 0));

        return [
            'months' => $months,
            'data' => Budget::whereIn('period', $months)->get()->groupBy('account_id')->map(fn ($rows) => $rows->pluck('amount', 'period')),
        ];
    }

    /** PUT /finance/budgets { account_id, amounts: {"2026-03": 1000, …} } */
    public function saveBudget(Request $request)
    {
        $data = $request->validate(['account_id' => ['required', 'exists:accounts,id'], 'amounts' => ['required', 'array'], 'amounts.*' => ['nullable', 'numeric']]);
        foreach ($data['amounts'] as $period => $amount) {
            abort_unless(preg_match('/^\d{4}-\d{2}$/', $period), 422, 'Bad period '.$period);
            $amount === null || $amount === ''
                ? Budget::where('account_id', $data['account_id'])->where('period', $period)->delete()
                : Budget::updateOrCreate(['account_id' => $data['account_id'], 'period' => $period], ['amount' => $amount]);
        }

        return ['ok' => true];
    }

    /** Budget vs actual for income & expense accounts over a date range (by month). */
    public function budgetVsActual(Request $request, Reports $reports)
    {
        [$months, $from, $to] = $this->fyMonths($request->integer('year', 0));
        $to = min($to, now()->toDateString());
        $budgets = Budget::whereIn('period', $months)->get()->groupBy('account_id');
        $actual = $reports->movements($from, $to);
        $rows = Account::whereIn('type', ['income', 'expense'])->orderBy('code')->get()->map(function ($a) use ($budgets, $actual, $months, $to) {
            $elapsed = collect($months)->filter(fn ($m) => $m <= substr($to, 0, 7));
            $budget = round((float) ($budgets[$a->id] ?? collect())->whereIn('period', $elapsed->all())->sum('amount'), 2);
            $act = round(($a->isDebitNormal() ? 1 : -1) * ($actual[$a->id] ?? 0), 2);

            return ['account_id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type, 'budget' => $budget, 'actual' => $act,
                'variance' => round($act - $budget, 2), 'percent' => $budget ? round($act / $budget * 100) : null,
                'full_year_budget' => round((float) ($budgets[$a->id] ?? collect())->sum('amount'), 2)];
        })->filter(fn ($r) => $r['budget'] || $r['actual'] || $r['full_year_budget'])->values();

        return ['from' => $from, 'to' => $to, 'rows' => $rows];
    }

    private function fyMonths(int $year): array
    {
        $settings = \App\Models\Accounting\AccountingSetting::current();
        $start = $year ? Carbon::create($year, $settings->financial_year_start_month, 1) : Carbon::instance($settings->yearStart(now()));
        $months = collect(range(0, 11))->map(fn ($i) => $start->copy()->addMonths($i)->format('Y-m'))->all();

        return [$months, $start->toDateString(), $start->copy()->addYear()->subDay()->toDateString()];
    }

    // ---- receipts --------------------------------------------------------------------------------

    /** Attach a receipt photo/PDF to a payment (files into Finance / Invoices & Receipts). */
    public function attachReceipt(Request $request, Payment $payment, FileStore $files)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,heic,webp', 'max:'.FileStore::MAX_KB]]);
        $file = $request->file('file');
        $folder = RecordsFolderSeeder::byPath('Finance/Invoices & Receipts');
        $doc = Document::create([
            'name' => 'Receipt '.$payment->number.' — '.($payment->reference ?: $payment->date->toDateString()).'.'.$file->getClientOriginalExtension(),
            'folder_id' => $folder?->id, 'category_id' => $folder?->default_category_id ?? DB::table('document_categories')->value('id'),
            'file_path' => $files->put($file, 'receipts'), 'size_bytes' => $file->getSize(), 'mime_type' => $file->getMimeType(),
            'uploaded_by' => $request->user()->id, 'retention_expires_at' => now()->addYears(5),
        ]);
        $payment->update(['receipt_document_id' => $doc->id]);

        return ['document_id' => $doc->id, 'download_url' => '/api/v1/documents/'.$doc->id.'/download'];
    }
}
