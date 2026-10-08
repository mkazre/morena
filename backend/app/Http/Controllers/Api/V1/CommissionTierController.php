<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CommissionTier;
use Illuminate\Http\Request;

class CommissionTierController extends Controller
{
    public function index()
    {
        return ['data' => CommissionTier::orderBy('position')->get()];
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['position'] ??= (CommissionTier::max('position') ?? -1) + 1;

        return CommissionTier::create($data);
    }

    public function update(Request $request, CommissionTier $tier)
    {
        $tier->update($this->validated($request, partial: true));

        return $tier;
    }

    public function destroy(CommissionTier $tier)
    {
        abort_if(CommissionTier::count() <= 1, 422, 'At least one tier is required.');
        $tier->delete();

        return response()->noContent();
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$req, 'string', 'max:100'],
            'min_deals' => ['nullable', 'integer', 'min:0'],
            'min_volume' => ['nullable', 'numeric', 'min:0'],
            'agent_split_percent' => [$req, 'numeric', 'between:0,100'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);
        if (isset($data['agent_split_percent'])) {
            $data['agency_split_percent'] = 100 - $data['agent_split_percent'];
        }

        return $data;
    }
}
