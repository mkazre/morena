<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClientUploadLink;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Services\FileStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Unauthenticated client-facing side of upload links (§7.2–7.3). */
class PublicUploadController extends Controller
{
    private function find(string $token): ClientUploadLink
    {
        $link = ClientUploadLink::where('token', $token)->with(['items', 'agent.user', 'deal'])->firstOrFail()->refreshExpiry();
        abort_if($link->status === 'expired', 410, 'This link has expired. Please ask your agent for a new one.');
        abort_if($link->status === 'filed', 410, 'These documents have already been received and filed. Thank you!');

        return $link;
    }

    public function show(string $token)
    {
        $link = $this->find($token);

        return [
            'client_name' => $link->client_name,
            'property' => $link->deal?->property_title,
            'agent' => $link->agent->user->name,
            'agent_phone' => $link->agent->user->phone,
            'status' => $link->status,
            'items' => $link->items->map(fn ($i) => [
                'id' => $i->id, 'label' => $i->document_label, 'hint' => $i->hint,
                'required' => $i->is_required, 'status' => $i->status,
            ]),
        ];
    }

    /** multipart: files[<item id>] = file (one or more items per request). */
    public function submit(Request $request, string $token, FileStore $files)
    {
        $link = $this->find($token);
        $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:'.FileStore::MAX_KB],
        ]);

        $category = DocumentCategory::where('name', 'FICA / Compliance')->first() ?? DocumentCategory::first();
        $items = $link->items->keyBy('id');

        DB::transaction(function () use ($request, $link, $items, $category, $files) {
            foreach ($request->file('files') as $itemId => $file) {
                $item = $items->get((int) $itemId);
                abort_unless($item, 422, 'Unknown checklist item.');
                $doc = Document::create([
                    'name' => $item->document_label.' — '.$link->client_name,
                    'category_id' => $category?->id,
                    'linked_model_type' => ClientUploadLink::class,
                    'linked_model_id' => $link->id,
                    'file_path' => $files->put($file, 'client-uploads/'.$link->id),
                    'retention_expires_at' => now()->addYears($category?->retention_years ?? 5),
                ]);
                $item->update(['uploaded_document_id' => $doc->id, 'status' => 'uploaded']);
            }

            $outstanding = $link->items()->where('is_required', true)->where('status', 'required')->exists();
            if (! $outstanding) {
                $link->update(['status' => 'uploaded']);
            }
        });

        return $this->show($token);
    }
}
