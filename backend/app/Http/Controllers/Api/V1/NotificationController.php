<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $q = AppNotification::where('user_id', $request->user()->id);

        return [
            'unread' => (clone $q)->whereNull('read_at')->count(),
            'data' => $q->latest()->limit(40)->get()->map(fn ($n) => [
                'id' => $n->id, 'type' => $n->type, 'title' => $n->title, 'body' => $n->body, 'url' => $n->url,
                'read' => (bool) $n->read_at, 'at' => $n->created_at->toIso8601String(),
            ]),
        ];
    }

    public function unreadCount(Request $request)
    {
        return ['unread' => AppNotification::where('user_id', $request->user()->id)->whereNull('read_at')->count()];
    }

    /** POST { ids: [...] } or {} for "mark all read". */
    public function markRead(Request $request)
    {
        $ids = $request->validate(['ids' => ['sometimes', 'array'], 'ids.*' => ['integer']])['ids'] ?? null;
        AppNotification::where('user_id', $request->user()->id)->whereNull('read_at')
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))->update(['read_at' => now()]);

        return $this->unreadCount($request);
    }
}
