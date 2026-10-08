<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Accounting\Automation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DealService
{
    public function __construct(private CommissionService $commissions, private Automation $accounting) {}

    public function create(array $data, ?User $by): Deal
    {
        return DB::transaction(function () use ($data, $by) {
            $data['pipeline_stage_id'] ??= PipelineStage::ordered()->value('id');
            $deal = Deal::create($data);
            $deal->stageHistory()->create(['pipeline_stage_id' => $deal->pipeline_stage_id, 'entered_at' => now()]);
            $this->log($deal, $by, 'Deal created — R'.number_format((float) $deal->sale_value));
            $this->commissions->calculateForDeal($deal->fresh());

            return $deal;
        });
    }

    public function update(Deal $deal, array $data, ?User $by): Deal
    {
        return DB::transaction(function () use ($deal, $data, $by) {
            $newStage = $data['pipeline_stage_id'] ?? null;
            unset($data['pipeline_stage_id']);
            $deal->update($data);
            if ($newStage && (int) $newStage !== $deal->pipeline_stage_id) {
                $this->moveTo($deal, PipelineStage::findOrFail($newStage), $by);
            }
            $this->afterChange($deal, $by);

            return $deal->fresh();
        });
    }

    /** POST /deals/{id}/advance-stage — move to the next stage by position. */
    public function advance(Deal $deal, ?User $by): Deal
    {
        $stages = PipelineStage::ordered()->get();
        $idx = $stages->search(fn ($s) => $s->id === $deal->pipeline_stage_id);
        $next = $stages->get($idx === false ? 0 : $idx + 1);
        if (! $next) {
            throw ValidationException::withMessages(['stage' => 'This deal is already at the final stage.']);
        }

        return DB::transaction(function () use ($deal, $next, $by) {
            $this->moveTo($deal, $next, $by);
            $this->afterChange($deal, $by);

            return $deal->fresh();
        });
    }

    private function moveTo(Deal $deal, PipelineStage $stage, ?User $by): void
    {
        $deal->stageHistory()->whereNull('exited_at')->update(['exited_at' => now()]);
        $deal->stageHistory()->create(['pipeline_stage_id' => $stage->id, 'entered_at' => now()]);

        $isFinal = PipelineStage::finalStage()?->id === $stage->id;
        $deal->update([
            'pipeline_stage_id' => $stage->id,
            'status' => $isFinal ? 'closed' : 'open',
            'closed_at' => $isFinal ? now() : null,
        ]);
        if ($deal->listing) {
            $deal->listing->update(['status' => $isFinal ? 'sold' : 'under_offer']);
        }

        $this->log($deal, $by, 'Moved to '.$stage->name);
    }

    /** Recalculate commission; when the deal has just closed, raise the commission invoice in the books. */
    private function afterChange(Deal $deal, ?User $by): void
    {
        $this->commissions->calculateForDeal($deal->fresh());
        $deal = $deal->fresh(['commission', 'seller']);
        if ($deal->status === 'closed') {
            $this->accounting->invoiceDealCommission($deal, $by);
        }
    }

    public function log(Deal $deal, ?User $by, string $text): void
    {
        $deal->activity()->create(['user_id' => $by?->id, 'text' => $text, 'created_at' => now()]);
    }
}
