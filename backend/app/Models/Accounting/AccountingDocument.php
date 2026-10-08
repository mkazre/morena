<?php

namespace App\Models\Accounting;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Behaviour shared by invoices (receivables) and bills (payables). */
trait AccountingDocument
{
    protected function casts(): array
    {
        return [
            'date' => DateOnly::class, 'due_date' => DateOnly::class,
            'subtotal' => 'float', 'vat_total' => 'float', 'total' => 'float', 'amount_paid' => 'float',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function allocations(): MorphMany
    {
        return $this->morphMany(PaymentAllocation::class, 'document');
    }

    public function balanceDue(): float
    {
        return round($this->total - $this->amount_paid, 2);
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, ['approved', 'partial'], true) && $this->due_date->lt(now()->startOfDay());
    }

    /** Re-derive amount paid + status from allocations on posted (not voided) payments. */
    public function refreshPaymentStatus(): void
    {
        if (in_array($this->status, ['draft', 'void'], true)) {
            return;
        }
        $paid = round((float) $this->allocations()->whereHas('payment', fn ($p) => $p->where('status', 'posted'))->sum('amount'), 2);
        $this->update([
            'amount_paid' => $paid,
            'status' => $paid <= 0 ? 'approved' : ($paid + 0.004 >= $this->total ? 'paid' : 'partial'),
        ]);
    }
}
