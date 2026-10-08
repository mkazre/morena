<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Models\Accounting\AccountingSetting;
use App\Models\Accounting\Bill;
use App\Models\Accounting\Invoice;
use App\Models\Setting;
use App\Services\Accounting\DocumentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** Invoices and bills share one controller; the route's {kind} is "invoices" or "bills". */
class DocumentController extends Controller
{
    public function __construct(private DocumentService $docs) {}

    private function model(string $kind): string
    {
        return $kind === 'bills' ? Bill::class : Invoice::class;
    }

    private function find(string $kind, int $id): Model
    {
        return $this->model($kind)::with(['lines.account', 'lines.taxRate', 'contact', 'allocations.payment'])->findOrFail($id);
    }

    public function index(Request $request, string $kind)
    {
        $status = $request->input('status');
        $q = $this->model($kind)::with('contact')
            ->when($status === 'outstanding', fn ($q) => $q->whereIn('status', ['approved', 'partial']))
            ->when($status === 'overdue', fn ($q) => $q->whereIn('status', ['approved', 'partial'])->where('due_date', '<', now()->toDateString()))
            ->when($status && ! in_array($status, ['outstanding', 'overdue'], true), fn ($q) => $q->where('status', $status))
            ->when($request->filled('contact_id'), fn ($q) => $q->where('contact_id', $request->integer('contact_id')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('number', 'like', '%'.$request->string('search').'%')
                ->orWhere('reference', 'like', '%'.$request->string('search').'%')
                ->orWhereHas('contact', fn ($c) => $c->where('name', 'like', '%'.$request->string('search').'%'))))
            ->orderByDesc('date')->orderByDesc('id');
        $all = $this->model($kind)::whereIn('status', ['approved', 'partial'])->get();

        return [
            'data' => $q->limit(500)->get()->map(fn ($d) => $this->summary($d)),
            'summary' => [
                'outstanding' => round($all->sum(fn ($d) => $d->balanceDue()), 2),
                'overdue' => round($all->filter->isOverdue()->sum(fn ($d) => $d->balanceDue()), 2),
                'draft_count' => $this->model($kind)::where('status', 'draft')->count(),
            ],
        ];
    }

    public function show(string $kind, int $id)
    {
        return $this->present($this->find($kind, $id));
    }

    public function store(Request $request, string $kind)
    {
        $class = $this->model($kind);
        $doc = $this->docs->save(new $class, $this->validated($request, $kind), $request->user());
        if ($request->boolean('approve')) {
            $doc = $this->docs->approve($doc, $request->user());
        }

        return $this->present($this->find($kind, $doc->id));
    }

    public function update(Request $request, string $kind, int $id)
    {
        $doc = $this->docs->save($this->find($kind, $id), $this->validated($request, $kind), $request->user());

        return $this->present($this->find($kind, $doc->id));
    }

    public function approve(Request $request, string $kind, int $id)
    {
        $this->docs->approve($this->find($kind, $id), $request->user());

        return $this->present($this->find($kind, $id));
    }

    public function void(string $kind, int $id)
    {
        $this->docs->void($this->find($kind, $id));

        return $this->present($this->find($kind, $id));
    }

    private function validated(Request $request, string $kind): array
    {
        return $request->validate([
            'contact_id' => ['required', 'exists:contacts,id'],
            'date' => ['required', 'date'], 'due_date' => ['required', 'date', 'after_or_equal:date'],
            'reference' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:2000'],
            'deal_id' => [$kind === 'invoices' ? 'nullable' : 'prohibited', 'exists:deals,id'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.account_id' => ['required', 'exists:accounts,id'],
            'lines.*.tax_rate_id' => ['nullable', 'exists:tax_rates,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'lines.*.unit_price' => ['required', 'numeric'],
        ]);
    }

    private function summary(Model $d): array
    {
        return [
            'id' => $d->id, 'number' => $d->number, 'contact' => $d->contact->name, 'contact_id' => $d->contact_id,
            'date' => $d->date->toDateString(), 'due_date' => $d->due_date->toDateString(), 'reference' => $d->reference,
            'total' => $d->total, 'balance_due' => $d->balanceDue(), 'status' => $d->isOverdue() ? 'overdue' : $d->status,
        ];
    }

    private function present(Model $d): array
    {
        $company = Setting::current();
        $acc = AccountingSetting::current();

        return $this->summary($d) + [
            'raw_status' => $d->status, 'notes' => $d->notes, 'subtotal' => $d->subtotal, 'vat_total' => $d->vat_total,
            'amount_paid' => $d->amount_paid, 'deal_id' => $d->deal_id ?? null, 'journal_entry_id' => $d->journal_entry_id,
            'contact_detail' => $d->contact->only(['name', 'email', 'phone', 'vat_number', 'address']),
            'lines' => $d->lines->map(fn ($l) => [
                'id' => $l->id, 'description' => $l->description, 'account_id' => $l->account_id,
                'account' => $l->account->code.' · '.$l->account->name, 'tax_rate_id' => $l->tax_rate_id, 'tax' => $l->taxRate?->name,
                'quantity' => $l->quantity, 'unit_price' => $l->unit_price, 'line_total' => $l->line_total, 'vat_amount' => $l->vat_amount,
            ]),
            'payments' => $d->allocations->filter(fn ($a) => $a->payment)->map(fn ($a) => [
                'payment_id' => $a->payment_id, 'number' => $a->payment->number, 'date' => $a->payment->date->toDateString(),
                'amount' => $a->amount, 'status' => $a->payment->status,
            ])->values(),
            'company' => ['name' => $company->company_name, 'vat_number' => $company->vat_number, 'footer' => $acc->invoice_footer],
        ];
    }
}
