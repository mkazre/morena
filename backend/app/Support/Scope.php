<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Server-side data scoping per role (handover §4: portal routes are the same resources,
 * auto-scoped to the authenticated user — never trust a client-passed agent filter).
 */
class Scope
{
    public static function isOrgWide(User $u): bool
    {
        return $u->hasAnyRole(['admin', 'hr']);
    }

    public static function staffId(User $u): ?int
    {
        return $u->staff?->id;
    }

    /**
     * Restrict a deals/leads/listings-style query.
     *
     * @param  callable(Builder,int):void  $agentScope  applies the "owned by agent" condition
     * @param  (callable(Builder,int):void)|null  $branchScope  applies the "in branch" condition (default: branch_id column)
     */
    public static function apply(Builder $q, User $u, callable $agentScope, ?callable $branchScope = null): Builder
    {
        if (self::isOrgWide($u)) {
            return $q;
        }
        if ($u->hasRole('branch_manager')) {
            $branchScope ??= fn ($q, $branchId) => $q->where('branch_id', $branchId);
            $branchScope($q, (int) $u->branch_id);

            return $q;
        }
        if ($u->hasRole('agent') && $u->staff) {
            $agentScope($q, $u->staff->id);

            return $q;
        }

        return $q->whereRaw('1 = 0');
    }

    /** Optional admin-side filters (?branch_id=, ?agent_id=) — ignored for scoped roles. */
    public static function filters(Builder $q, Request $r, User $u, ?callable $agentFilter = null, ?callable $branchFilter = null): Builder
    {
        if (! self::isOrgWide($u)) {
            return $q;
        }
        if ($r->filled('branch_id')) {
            $branchFilter ??= fn ($q, $branchId) => $q->where('branch_id', $branchId);
            $branchFilter($q, $r->integer('branch_id'));
        }
        if ($r->filled('agent_id') && $agentFilter) {
            $agentFilter($q, $r->integer('agent_id'));
        }

        return $q;
    }
}
