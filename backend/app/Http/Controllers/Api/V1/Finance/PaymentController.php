<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Models\Accounting\Payment;
use App\Services\Accounting\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $q = Payment::with(['contact', 'bankAccount'])
            ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->string('direction')))
            ->when($request->filled('from'), fn ($q) => $q->where('date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('date', '<=', $request->date('to')))
            ->orderByDesc('date')->orderByDesc('id')->limit(500);

        return ['data' => $q->get()->map(fn ($p) => [
            'id' => $p->id, 'number' => $p->number, 'direction' => $p->direction, 'date' => $p->date->toDateString(),
            'contact' => $p->contact?->name, 'bank_account' => $p->bankAccount->name, 'amount' => $p->amount,
            'method' => $p->method, 'reference' => $p->reference, 'status' => $p->status, 'journal_entry_id' => $p->journal_entry_id,
        ])];
    }

    public function store(Request $request, PaymentService $payments)
    {
        $data = $request->validate([
            'direction' => ['required', Rule::in(['received', 'made'])],
            'bank_account_id' => ['required', 'exists:accounts,id'],
            'date' => ['required', 'date'], 'contact_id' => ['nullable', 'exists:contacts,id'],
            'method' => ['nullable', Rule::in(['eft', 'cash', 'card', 'cheque', 'debit_order'])],
            'reference' => ['nullable', 'string', 'max:100'], 'memo' => ['nullable', 'string', 'max:255'],
            'allocations' => ['nullable', 'array'], 'allocations.*.id' => ['required', 'integer'], 'allocations.*.amount' => ['required', 'numeric', 'min:0.01'],
            'lines' => ['nullable', 'array'], 'lines.*.account_id' => ['required', 'exists:accounts,id'],
            'lines.*.amount' => ['required', 'numeric', 'min:0.01'], 'lines.*.tax_rate_id' => ['nullable', 'exists:tax_rates,id'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);
        $p = $payments->record($data, $request->user());

        return ['id' => $p->id, 'number' => $p->number, 'amount' => $p->amount, 'journal_entry_id' => $p->journal_entry_id];
    }

    public function void(Payment $payment, PaymentService $payments)
    {
        abort_if($payment->status === 'void', 422, 'Already voided.');
        $payments->void($payment);

        return ['id' => $payment->id, 'status' => 'void'];
    }
}
