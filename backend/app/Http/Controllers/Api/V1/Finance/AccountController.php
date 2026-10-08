<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Models\Accounting\Account;
use App\Models\Accounting\Contact;
use App\Models\Accounting\TaxRate;
use App\Services\Accounting\Reports;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Chart of accounts, tax rates and contacts (customers / suppliers). */
class AccountController extends Controller
{
    public const SUBTYPES = [
        'asset' => ['bank', 'receivable', 'current_asset', 'fixed_asset', 'vat_input'],
        'liability' => ['payable', 'current_liability', 'vat_output', 'long_term_liability'],
        'equity' => ['equity', 'retained_earnings'],
        'income' => ['revenue', 'other_income'],
        'expense' => ['cost_of_sales', 'operating_expense', 'other_expense'],
    ];

    public function index(Request $request, Reports $reports)
    {
        $balances = $reports->movements(null, now()->toDateString());

        return ['data' => Account::orderBy('code')
            ->when(! $request->boolean('include_archived'), fn ($q) => $q->where('is_active', true))
            ->get()->map(fn (Account $a) => $this->present($a, $balances[$a->id] ?? 0))];
    }

    public function store(Request $request)
    {
        return $this->present(Account::create($this->validated($request)), 0);
    }

    public function update(Request $request, Account $account)
    {
        $data = $this->validated($request, $account);
        if ($account->system_key) {
            // System accounts drive automatic postings: renaming is fine, re-typing or archiving is not.
            unset($data['type'], $data['subtype']);
            abort_if(isset($data['is_active']) && ! $data['is_active'], 422, 'This account is used by automatic postings and cannot be archived.');
        }
        $account->update($data);

        return $this->present($account, 0);
    }

    public function taxRates()
    {
        return ['data' => TaxRate::orderByDesc('is_default')->orderBy('rate')->get(['id', 'name', 'code', 'rate', 'is_default'])];
    }

    public function contacts(Request $request)
    {
        $q = Contact::query()->where('is_active', true)
            ->when($request->filled('type'), fn ($q) => $q->whereIn('type', [$request->string('type'), 'both']))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->withSum(['invoices as receivable' => fn ($i) => $i->whereIn('status', ['approved', 'partial'])], 'total')
            ->withSum(['invoices as received' => fn ($i) => $i->whereIn('status', ['approved', 'partial'])], 'amount_paid')
            ->withSum(['bills as payable' => fn ($b) => $b->whereIn('status', ['approved', 'partial'])], 'total')
            ->withSum(['bills as paid' => fn ($b) => $b->whereIn('status', ['approved', 'partial'])], 'amount_paid')
            ->orderBy('name');

        return ['data' => $q->get()->map(fn ($c) => [
            'id' => $c->id, 'type' => $c->type, 'name' => $c->name, 'email' => $c->email, 'phone' => $c->phone,
            'vat_number' => $c->vat_number, 'address' => $c->address, 'staff_id' => $c->staff_id,
            'owes_us' => round((float) $c->receivable - (float) $c->received, 2),
            'we_owe' => round((float) $c->payable - (float) $c->paid, 2),
        ])];
    }

    public function storeContact(Request $request)
    {
        return Contact::create($this->validatedContact($request));
    }

    public function updateContact(Request $request, Contact $contact)
    {
        $contact->update($this->validatedContact($request, true));

        return $contact;
    }

    private function validatedContact(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'type' => [$partial ? 'sometimes' : 'required', Rule::in(['customer', 'supplier', 'both'])],
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'email' => ['nullable', 'email'], 'phone' => ['nullable', 'string', 'max:30'],
            'vat_number' => ['nullable', 'string', 'max:30'], 'address' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function validated(Request $request, ?Account $account = null): array
    {
        $partial = (bool) $account;
        $type = $request->input('type', $account?->type);

        return $request->validate([
            'code' => [$partial ? 'sometimes' : 'required', 'string', 'max:20', Rule::unique('accounts', 'code')->ignore($account?->id)],
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'type' => [$partial ? 'sometimes' : 'required', Rule::in(array_keys(self::SUBTYPES))],
            'subtype' => [$partial ? 'sometimes' : 'required', Rule::in(self::SUBTYPES[$type] ?? [])],
            'description' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:40'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function present(Account $a, float $debitMinusCredit): array
    {
        return [
            'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type, 'subtype' => $a->subtype,
            'system' => (bool) $a->system_key, 'system_key' => $a->system_key, 'description' => $a->description,
            'bank_account_number' => $a->bank_account_number, 'is_active' => $a->is_active,
            'balance' => round($a->isDebitNormal() ? $debitMinusCredit : -$debitMinusCredit, 2),
        ];
    }
}
