<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingsController extends Controller
{
    public function show()
    {
        $s = Setting::current();

        return [
            'company_name' => $s->company_name,
            'vat_number' => $s->vat_number,
            'branches' => Branch::orderBy('name')->pluck('name'),
            'sidebar_logo_width' => $s->sidebar_logo_width,
            'integrations' => [
                ['key' => 'payprop', 'name' => 'PayProp', 'desc' => 'Rental collections & landlord statements', 'connected' => $s->payprop_connected],
                ['key' => 'whatsapp', 'name' => 'WhatsApp Business API', 'desc' => 'Send upload links and reminders', 'connected' => $s->whatsapp_connected],
            ],
        ];
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'company_name' => ['sometimes', 'string', 'max:255'],
            'vat_number' => ['nullable', 'string', 'max:30'],
            'sidebar_logo_width' => ['sometimes', 'integer', 'between:100,200'],
            'branches' => ['sometimes', 'array', 'min:1'],
            'branches.*' => ['string', 'max:100'],
        ]);

        DB::transaction(function () use ($data) {
            Setting::current()->update(collect($data)->except('branches')->all());

            if (isset($data['branches'])) {
                $names = collect($data['branches'])->map(fn ($n) => trim($n))->filter()->unique();
                foreach ($names as $name) {
                    Branch::firstOrCreate(['name' => $name]);
                }
                // Only drop branches nobody is attached to.
                Branch::whereNotIn('name', $names)
                    ->whereDoesntHave('staff')->whereDoesntHave('deals')
                    ->delete();
            }
        });

        return $this->show();
    }

    public function branches()
    {
        return ['data' => Branch::orderBy('name')->get(['id', 'name'])];
    }
}
