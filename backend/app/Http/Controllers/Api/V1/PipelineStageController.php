<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\DocumentTemplate;
use App\Models\PipelineStage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PipelineStageController extends Controller
{
    public function index()
    {
        return ['data' => PipelineStage::ordered()->with('documentTemplates')->withCount('deals')->get()->map(fn ($s) => $this->present($s))];
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $stage = PipelineStage::create([
            'name' => $data['name'],
            'color_hex' => $data['color_hex'] ?? '#c23552',
            'position' => (PipelineStage::max('position') ?? -1) + 1,
        ]);
        $this->syncDocuments($stage, $data['documents'] ?? []);

        return $this->present($stage->load('documentTemplates'));
    }

    public function update(Request $request, PipelineStage $stage)
    {
        $data = $this->validated($request, partial: true);
        $stage->update(collect($data)->only(['name', 'color_hex'])->all());
        if (array_key_exists('documents', $data)) {
            $this->syncDocuments($stage, $data['documents'] ?? []);
        }

        return $this->present($stage->load('documentTemplates'));
    }

    public function destroy(PipelineStage $stage)
    {
        abort_if($stage->deals()->exists(), 422, 'Move the deals in this stage to another stage before deleting it.');
        abort_if(PipelineStage::count() <= 1, 422, 'The pipeline needs at least one stage.');
        $stage->delete();

        return response()->noContent();
    }

    /** POST /pipeline-stages/reorder { ids: [3,1,2,...] } */
    public function reorder(Request $request)
    {
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer', 'exists:pipeline_stages,id']])['ids'];
        DB::transaction(function () use ($ids) {
            foreach (array_values($ids) as $pos => $id) {
                PipelineStage::whereKey($id)->update(['position' => $pos]);
            }
        });

        return $this->index();
    }

    /**
     * POST /pipeline-stages/reset — restore the default seed. Existing stages with deals are kept
     * (renamed/repositioned onto the matching default where names match) so no deal is orphaned.
     */
    public function reset()
    {
        DB::transaction(function () {
            $kept = collect();
            foreach (PipelineStage::DEFAULTS as $pos => [$name, $color, $docs]) {
                $stage = PipelineStage::firstOrNew(['name' => $name]);
                $stage->fill(['position' => $pos, 'color_hex' => $color, 'is_default_seed' => true])->save();
                $this->syncDocuments($stage, $docs);
                $kept->push($stage->id);
            }
            $extras = PipelineStage::whereNotIn('id', $kept)->get();
            foreach ($extras as $extra) {
                Deal::where('pipeline_stage_id', $extra->id)->update(['pipeline_stage_id' => $kept->first()]);
                $extra->delete();
            }
        });

        return $this->index();
    }

    /** Required documents are document templates; unknown names create a template stub. */
    private function syncDocuments(PipelineStage $stage, array $names): void
    {
        $ids = collect($names)->filter()->map(fn ($n) => DocumentTemplate::firstOrCreate(
            ['name' => trim($n)], ['used_for' => 'Sales deals']
        )->id);
        $stage->documentTemplates()->sync($ids);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:100'],
            'color_hex' => ['sometimes', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'documents' => ['sometimes', 'array'],
            'documents.*' => ['string', 'max:100'],
        ]);
    }

    private function present(PipelineStage $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'position' => $s->position,
            'color_hex' => $s->color_hex,
            'documents' => $s->documentTemplates->pluck('name')->values(),
            'deals_count' => $s->deals_count ?? null,
        ];
    }
}
