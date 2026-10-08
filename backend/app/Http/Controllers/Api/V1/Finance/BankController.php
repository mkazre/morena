<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Models\Accounting\Account;
use App\Models\Accounting\BankReconciliation;
use App\Models\Accounting\BankTransaction;
use App\Services\Accounting\BankService;
use App\Services\Accounting\Reports;
use Illuminate\Http\Request;

class BankController extends Controller
{
    public function __construct(private BankService $bank) {}

    public function accounts(Reports $reports)
    {
        return ['data' => Account::bank()->where('is_active', true)->orderBy('code')->get()->map(function ($a) use ($reports) {
            $last = BankReconciliation::where('bank_account_id', $a->id)->latest('statement_date')->first();

            return [
                'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'account_number' => $a->bank_account_number,
                'ledger_balance' => $reports->balance($a),
                'statement_balance' => round(($last?->statement_balance ?? 0) + (float) BankTransaction::where('bank_account_id', $a->id)
                    ->where('status', '!=', 'ignored')->when($last, fn ($q) => $q->where('date', '>', $last->statement_date))->sum('amount'), 2),
                'unmatched' => BankTransaction::where('bank_account_id', $a->id)->where('status', 'unmatched')->count(),
                'last_reconciled' => $last?->statement_date?->toDateString(),
            ];
        })];
    }

    public function transactions(Request $request)
    {
        $q = BankTransaction::with('journal')
            ->where('bank_account_id', $request->integer('bank_account_id'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('date')->orderByDesc('id')->limit(500);

        return ['data' => $q->get()->map(fn ($t) => [
            'id' => $t->id, 'date' => $t->date->toDateString(), 'description' => $t->description, 'reference' => $t->reference,
            'amount' => $t->amount, 'status' => $t->status, 'journal_entry_id' => $t->journal_entry_id, 'journal_number' => $t->journal?->number,
        ])];
    }

    public function import(Request $request)
    {
        $request->validate(['bank_account_id' => ['required', 'exists:accounts,id'], 'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);
        $account = Account::bank()->findOrFail($request->integer('bank_account_id'));

        return $this->bank->import($account, file_get_contents($request->file('file')->getRealPath()));
    }

    public function suggestions(BankTransaction $transaction)
    {
        return ['data' => $this->bank->suggestions($transaction)];
    }

    public function match(Request $request, BankTransaction $transaction)
    {
        $id = $request->validate(['journal_entry_id' => ['required', 'exists:journal_entries,id']])['journal_entry_id'];

        return $this->bank->match($transaction, $id);
    }

    public function create(Request $request, BankTransaction $transaction)
    {
        $data = $request->validate([
            'document_id' => ['nullable', 'integer'], 'account_id' => ['required_without:document_id', 'nullable', 'exists:accounts,id'],
            'tax_rate_id' => ['nullable', 'exists:tax_rates,id'], 'contact_id' => ['nullable', 'exists:contacts,id'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->bank->create($transaction, $data, $request->user());
    }

    public function unmatch(BankTransaction $transaction)
    {
        return $this->bank->unmatch($transaction);
    }

    public function ignore(BankTransaction $transaction)
    {
        abort_if($transaction->status === 'reconciled', 422, 'Already reconciled.');
        $transaction->update(['status' => $transaction->status === 'ignored' ? 'unmatched' : 'ignored', 'journal_entry_id' => null]);

        return $transaction;
    }

    public function reconcile(Request $request)
    {
        $data = $request->validate([
            'bank_account_id' => ['required', 'exists:accounts,id'], 'statement_date' => ['required', 'date'],
            'statement_balance' => ['required', 'numeric'],
        ]);

        return $this->bank->reconcile(Account::bank()->findOrFail($data['bank_account_id']), $data['statement_date'], (float) $data['statement_balance'], $request->user());
    }

    public function reconciliations(Request $request)
    {
        return ['data' => BankReconciliation::with('bankAccount')
            ->when($request->filled('bank_account_id'), fn ($q) => $q->where('bank_account_id', $request->integer('bank_account_id')))
            ->latest('statement_date')->limit(50)->get()->map(fn ($r) => [
                'id' => $r->id, 'bank_account' => $r->bankAccount->name, 'statement_date' => $r->statement_date->toDateString(),
                'statement_balance' => $r->statement_balance, 'ledger_balance' => $r->ledger_balance,
            ])];
    }
}
