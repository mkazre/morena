<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Certification;

class CertificationController extends Controller
{
    public function index()
    {
        $rows = Certification::with('staff.user')->whereHas('staff', fn ($s) => $s->where('status', '!=', 'terminated'))->get()
            ->sortBy('staff.user.name')->values();

        return ['data' => $rows->map(fn (Certification $c) => [
            'id' => $c->id,
            'staff_id' => $c->staff_id,
            'name' => $c->staff->user->name,
            'ffc_number' => $c->ffc_number,
            'ffc_expiry' => $c->ffc_expiry?->toDateString(),
            'cpd_points' => $c->cpd_points,
            'cpd_target' => $c->cpd_target,
            'status' => self::status($c),
        ])];
    }

    /** Derived from the record so it never goes stale: no FFC → pending, FFC expired or CPD short → behind. */
    public static function status(Certification $c): string
    {
        if (! $c->ffc_number) {
            return 'pending';
        }
        if (($c->ffc_expiry && $c->ffc_expiry->isPast()) || $c->cpd_points < $c->cpd_target * 0.75) {
            return 'behind';
        }

        return 'compliant';
    }
}
