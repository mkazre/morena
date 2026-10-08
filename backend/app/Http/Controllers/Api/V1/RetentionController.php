<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentCategory;
use Illuminate\Http\Request;

class RetentionController extends Controller
{
    /** GET /retention/summary — records per regulatory group and how many expire within 90 days. */
    public function summary()
    {
        $soon = now()->addDays(90);
        $groups = DocumentCategory::withCount([
            'documents as total',
            'documents as expiring' => fn ($q) => $q->whereBetween('retention_expires_at', [now(), $soon]),
        ])->get()->groupBy('retention_group');

        return ['data' => $groups->map(fn ($cats, $group) => [
            'name' => $group,
            'period' => $cats->first()->retention_years.' years from '.$cats->first()->retention_basis,
            'total' => (int) $cats->sum('total'),
            'expiring' => (int) $cats->sum('expiring'),
        ])->values()];
    }

    /** GET /retention/expiring?days=90 */
    public function expiring(Request $request)
    {
        $days = min(max($request->integer('days', 90), 1), 365);
        $docs = Document::with('category')
            ->whereBetween('retention_expires_at', [now(), now()->addDays($days)])
            ->orderBy('retention_expires_at')->limit(100)->get();

        return ['data' => $docs->map(fn ($d) => [
            'id' => $d->id,
            'name' => $d->name,
            'category' => $d->category?->retention_group,
            'expires_at' => $d->retention_expires_at->toDateString(),
            'days_left' => (int) now()->startOfDay()->diffInDays($d->retention_expires_at),
        ])];
    }
}
