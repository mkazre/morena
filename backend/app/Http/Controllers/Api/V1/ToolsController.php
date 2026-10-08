<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use App\Models\CommissionSetting;
use App\Models\CommissionTier;
use App\Models\Deal;
use App\Models\Listing;
use App\Models\Setting;
use App\Models\Staff;
use App\Support\Scope;
use Illuminate\Http\Request;

/** Calculators, brochure data, comparative market analysis and listing import. */
class ToolsController extends Controller
{
    /** Read-only commission rules + transfer-duty table for the agent calculators. */
    public function rules(Request $request)
    {
        $s = CommissionSetting::current();
        $myTier = $request->user()->staff ? app(\App\Services\CommissionService::class)->resolveTier($request->user()->staff) : null;

        return [
            'base_rate_percent' => $s->base_rate_percent, 'vat_registered' => $s->vat_registered, 'vat_rate' => $s->vat_rate,
            'co_listing_split_default_percent' => $s->co_listing_split_default_percent, 'referral_fee_percent' => $s->referral_fee_percent,
            'tiers' => CommissionTier::orderBy('position')->get(['id', 'name', 'agent_split_percent', 'agency_split_percent', 'min_deals', 'min_volume']),
            'my_tier_id' => $myTier?->id,
            'transfer_duty' => config('morena.transfer_duty'), 'prime_rate' => config('morena.prime_rate'),
        ];
    }

    public function listing(Request $request, Listing $listing)
    {
        $agent = $listing->agent()->with('user')->first();

        return (new ListingResource($listing->load('agent.user')))->additional([
            'agent_contact' => $agent ? ['name' => $agent->user->name, 'phone' => $agent->user->phone, 'email' => $agent->user->email, 'ffc' => $agent->ffc_number] : null,
            'company' => Setting::current()->only(['company_name', 'vat_number']),
            'can_edit' => Scope::isOrgWide($request->user()) || $listing->agent_id === $request->user()->staff?->id,
        ]);
    }

    /**
     * Comparative market analysis from Morena's own sold history: same suburb (or branch area) first, similar
     * bedrooms, last 24 months. Not a valuation — a talking point for the seller conversation.
     */
    public function cma(Request $request)
    {
        $data = $request->validate([
            'suburb' => ['required', 'string', 'max:120'], 'beds' => ['nullable', 'integer', 'between:0,20'],
            'property_type' => ['nullable', 'string'], 'asking_price' => ['nullable', 'numeric', 'min:0'],
        ]);
        $base = Deal::with('listing')->where('status', 'closed')->where('closed_at', '>=', now()->subMonths(24))->whereHas('listing');
        $comps = (clone $base)->whereHas('listing', fn ($l) => $l->where('suburb', 'like', '%'.$data['suburb'].'%')
            ->when($data['beds'] ?? null, fn ($q, $b) => $q->whereBetween('beds', [$b - 1, $b + 1]))
            ->when($data['property_type'] ?? null, fn ($q, $t) => $q->where('property_type', $t)))->latest('closed_at')->limit(30)->get();
        $scope = 'suburb';
        if ($comps->count() < 3) { // widen to similar homes anywhere if the suburb is thin
            $scope = 'similar homes in all areas';
            $comps = (clone $base)->whereHas('listing', fn ($l) => $l->when($data['beds'] ?? null, fn ($q, $b) => $q->whereBetween('beds', [$b - 1, $b + 1]))
                ->when($data['property_type'] ?? null, fn ($q, $t) => $q->where('property_type', $t)))->latest('closed_at')->limit(30)->get();
        }
        $prices = $comps->pluck('sale_value')->map(fn ($v) => (float) $v)->sort()->values();
        $median = $prices->count() ? ($prices->count() % 2 ? $prices[intdiv($prices->count(), 2)] : ($prices[$prices->count() / 2 - 1] + $prices[$prices->count() / 2]) / 2) : null;
        $active = Listing::where('status', 'active')->where('suburb', 'like', '%'.$data['suburb'].'%')->get(['title', 'price', 'beds', 'baths', 'listed_at']);

        return [
            'scope' => $scope, 'count' => $prices->count(),
            'low' => $prices->first(), 'high' => $prices->last(), 'median' => $median, 'average' => $prices->count() ? round($prices->avg(), 2) : null,
            'avg_days_on_market' => $comps->count() ? (int) round($comps->avg(fn ($d) => $d->listing->listed_at ? $d->listing->listed_at->diffInDays($d->closed_at) : $d->created_at->diffInDays($d->closed_at))) : null,
            'asking_vs_median_percent' => ($median && ($data['asking_price'] ?? null)) ? round(($data['asking_price'] - $median) / $median * 100, 1) : null,
            'comparables' => $comps->map(fn ($d) => [
                'property' => $d->property_title, 'suburb' => $d->listing->suburb, 'beds' => $d->listing->beds, 'baths' => $d->listing->baths,
                'type' => Listing::PROPERTY_TYPES[$d->listing->property_type] ?? $d->listing->property_type,
                'price' => (float) $d->sale_value, 'sold_on' => $d->closed_at->toDateString(),
            ]),
            'competing' => $active->map(fn ($l) => ['title' => $l->title, 'price' => (float) $l->price, 'beds' => $l->beds, 'baths' => $l->baths, 'listed_at' => $l->listed_at?->toDateString()]),
        ];
    }

    /** POST /listings/import (CSV): title, suburb, price, beds, baths, type, agent_email, mandate_expiry, status. */
    public function importListings(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);
        $rows = array_map('str_getcsv', preg_split('/\r\n|\n|\r/', trim(file_get_contents($request->file('file')->getRealPath()))));
        $head = array_map(fn ($h) => strtolower(trim($h)), array_shift($rows) ?? []);
        $at = fn (...$names) => collect($names)->map(fn ($n) => array_search($n, $head, true))->first(fn ($i) => $i !== false);
        $i = ['title' => $at('title', 'address', 'property'), 'suburb' => $at('suburb', 'area'), 'price' => $at('price', 'asking price'),
            'beds' => $at('beds', 'bedrooms'), 'baths' => $at('baths', 'bathrooms'), 'type' => $at('type', 'property type'),
            'agent' => $at('agent_email', 'agent email', 'agent'), 'mandate' => $at('mandate_expiry', 'mandate expiry'), 'status' => $at('status')];
        abort_if($i['title'] === null || $i['price'] === null, 422, 'The CSV needs "title" and "price" columns.');
        $user = $request->user();
        $agents = Staff::with('user')->get()->keyBy(fn ($s) => strtolower($s->user->email));
        $types = array_flip(array_map('strtolower', Listing::PROPERTY_TYPES)) + array_combine(array_keys(Listing::PROPERTY_TYPES), array_keys(Listing::PROPERTY_TYPES));
        $created = 0;
        $skipped = [];
        foreach ($rows as $n => $r) {
            $title = trim($r[$i['title']] ?? '');
            $price = (float) preg_replace('/[^0-9.]/', '', $r[$i['price']] ?? '');
            if ($title === '' || $price <= 0) {
                $skipped[] = ['row' => $n + 2, 'reason' => 'missing title or price'];

                continue;
            }
            if (Listing::where('title', $title)->exists()) {
                $skipped[] = ['row' => $n + 2, 'title' => $title, 'reason' => 'already listed'];

                continue;
            }
            $agent = $user->hasRole('agent') ? $user->staff : $agents->get(strtolower(trim($r[$i['agent']] ?? '')));
            $type = strtolower(trim($r[$i['type']] ?? ''));
            Listing::create([
                'title' => $title, 'suburb' => trim($r[$i['suburb']] ?? '') ?: 'Unknown', 'price' => $price,
                'beds' => (int) ($r[$i['beds']] ?? 0), 'baths' => (int) ($r[$i['baths']] ?? 0),
                'property_type' => $types[$type] ?? 'house', 'agent_id' => $agent?->id, 'branch_id' => $agent?->branch_id ?? $user->branch_id,
                'status' => in_array($s = strtolower(trim($r[$i['status']] ?? 'active')), ['active', 'under_offer', 'sold'], true) ? $s : 'active',
                'mandate_expiry' => ($m = trim($r[$i['mandate']] ?? '')) ? date('Y-m-d', strtotime($m)) : null, 'listed_at' => now()->toDateString(),
            ]);
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }
}
