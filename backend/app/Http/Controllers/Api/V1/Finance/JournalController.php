<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Models\Accounting\JournalEntry;
use App\Services\Accounting\Ledger;
use Illuminate\Http\Request;

class JournalController extends Controller
{
    public function index(Request $request)
    {
        $q = JournalEntry::withSum('lines as amount', 'debit')->with('creator')
            ->when($request->filled('from'), fn ($q) => $q->where('date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('date', '<=', $request->date('to')))
            ->when($request->input('source') === 'manual', fn ($q) => $q->whereNull('source_type'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('number', 'like', '%'.$request->string('search').'%')
                ->orWhere('reference', 'like', '%'.$request->string('search').'%')
                ->orWhere('memo', 'like', '%'.$request->string('search').'%')))
            ->orderByDesc('date')->orderByDesc('id');

        $page = $q->paginate(min($request->integer('per_page', 50), 200));

        return [
            'data' => collect($page->items())->map(fn ($e) => [
                'id' => $e->id, 'number' => $e->number, 'date' => $e->date->toDateString(), 'reference' => $e->reference,
                'memo' => $e->memo, 'source' => $e->sourceLabel(), 'status' => $e->status, 'amount' => round((float) $e->amount, 2),
                'created_by' => $e->creator?->name,
            ]),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ];
    }

    public function show(JournalEntry $journal)
    {
        $journal->load(['lines.account', 'lines.contact', 'lines.taxRate', 'creator']);

        return [
            'id' => $journal->id, 'number' => $journal->number, 'date' => $journal->date->toDateString(),
            'reference' => $journal->reference, 'memo' => $journal->memo, 'status' => $journal->status,
            'source' => $journal->sourceLabel(), 'source_type' => $journal->source_type ? class_basename($journal->source_type) : null,
            'source_id' => $journal->source_id, 'reverses_id' => $journal->reverses_id,
            'created_by' => $journal->creator?->name, 'created_at' => $journal->created_at->toIso8601String(),
            'lines' => $journal->lines->map(fn ($l) => [
                'account' => $l->account->code.' · '.$l->account->name, 'account_id' => $l->account_id,
                'contact' => $l->contact?->name, 'tax' => $l->taxRate?->name, 'description' => $l->description,
                'debit' => $l->debit, 'credit' => $l->credit,
            ]),
        ];
    }

    public function store(Request $request, Ledger $ledger)
    {
        $data = $request->validate([
            'date' => ['required', 'date'], 'reference' => ['nullable', 'string', 'max:100'], 'memo' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'exists:accounts,id'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'], 'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'], 'lines.*.contact_id' => ['nullable', 'exists:contacts,id'],
        ]);
        $entry = $ledger->post($data, $data['lines'], null, $request->user());

        return $this->show($entry);
    }

    /** Manual journals only — documents and payments are voided from their own screens. */
    public function void(JournalEntry $journal, Ledger $ledger)
    {
        abort_if($journal->source_type !== null, 422, 'This entry was created by a '.strtolower($journal->sourceLabel()).'. Void it from there.');
        $ledger->void($journal);

        return $this->show($journal->fresh());
    }

    public function reverse(Request $request, JournalEntry $journal, Ledger $ledger)
    {
        $date = $request->validate(['date' => ['required', 'date']])['date'];

        return $this->show($ledger->reverse($journal->load('lines'), $date, $request->user()));
    }
}
