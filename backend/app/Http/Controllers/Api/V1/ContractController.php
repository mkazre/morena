<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Services\FileStore;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContractController extends Controller
{
    public function index()
    {
        return ['data' => Contract::with('staff.user')->latest('start_date')->get()->map(fn ($c) => $this->present($c))];
    }

    public function store(Request $request, FileStore $files)
    {
        $data = $this->validated($request);
        $data = $this->withFile($request, $data, $files);

        return $this->present(Contract::create($data)->load('staff.user'));
    }

    public function update(Request $request, Contract $contract, FileStore $files)
    {
        $data = $this->withFile($request, $this->validated($request, partial: true), $files);
        $contract->update($data);

        return $this->present($contract->load('staff.user'));
    }

    public function download(Contract $contract, FileStore $files)
    {
        abort_unless($contract->file_path, 404, 'No signed copy uploaded for this contract yet.');

        return $files->download($contract->file_path, $contract->staff->user->name.' — '.$contract->type);
    }

    private function withFile(Request $request, array $data, FileStore $files): array
    {
        unset($data['file']);
        if ($request->hasFile('file')) {
            $data['file_path'] = $files->put($request->file('file'), 'contracts');
        }

        return $data;
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'staff_id' => [$req, 'exists:staff,id'],
            'type' => [$req, 'string', 'max:255'],
            'start_date' => [$req, 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['sometimes', Rule::in(['active', 'renewal_due', 'probation', 'terminated'])],
            'file' => ['nullable', 'file', 'mimes:'.FileStore::ALLOWED_MIMES, 'max:'.FileStore::MAX_KB],
        ]);
    }

    private function present(Contract $c): array
    {
        return [
            'id' => $c->id,
            'staff_id' => $c->staff_id,
            'name' => $c->staff->user->name,
            'type' => $c->type,
            'start_date' => $c->start_date->toDateString(),
            'expiry_date' => $c->expiry_date?->toDateString(),
            'status' => $c->status,
            'has_file' => (bool) $c->file_path,
        ];
    }
}
