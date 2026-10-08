<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LoginEvent;
use Illuminate\Http\Request;

/** Admin: system-wide audit trail and sign-in history. */
class AuditController extends Controller
{
    public function index(Request $request)
    {
        $q = AuditLog::with('user')
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('subject_type', 'like', '%'.$request->string('type')))
            ->when($request->filled('search'), fn ($q) => $q->where('label', 'like', '%'.$request->string('search').'%'))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')))
            ->latest('created_at');
        $page = $q->paginate(100);

        return [
            'data' => collect($page->items())->map(fn ($a) => [
                'id' => $a->id, 'at' => $a->created_at->toIso8601String(), 'by' => $a->user?->name ?? 'system', 'event' => $a->event,
                'type' => $a->subject_type ? class_basename($a->subject_type) : null, 'label' => $a->label, 'changes' => $a->changes, 'ip' => $a->ip,
            ]),
            'meta' => ['total' => $page->total(), 'last_page' => $page->lastPage()],
        ];
    }

    public function logins(Request $request)
    {
        return ['data' => LoginEvent::with('user')->latest('created_at')
            ->when($request->boolean('failed'), fn ($q) => $q->where('success', false))->limit(200)->get()->map(fn ($e) => [
                'at' => $e->created_at->toIso8601String(), 'email' => $e->email, 'name' => $e->user?->name, 'success' => $e->success,
                'reason' => $e->reason, 'ip' => $e->ip, 'device' => $e->user_agent,
            ])];
    }
}
