<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** System name, version, and the sign-in page panel (gradient + background image). */
class BrandingController extends Controller
{
    /** GET /api/v1/branding — public: the sign-in page needs it before anyone is signed in. */
    public function show()
    {
        return Setting::current()->branding();
    }

    /** PUT /api/v1/settings/branding (admin) */
    public function update(Request $request)
    {
        $data = $request->validate([
            'system_name' => ['sometimes', 'string', 'max:80'],
            'login_headline' => ['nullable', 'string', 'max:200'],
            'login_gradient' => ['sometimes', 'nullable', 'array'],
            'login_gradient.type' => ['required_with:login_gradient', Rule::in(['linear', 'radial'])],
            'login_gradient.angle' => ['nullable', 'integer', 'between:0,360'],
            'login_gradient.stops' => ['required_with:login_gradient', 'array', 'min:1', 'max:10'],
            'login_gradient.stops.*.color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'login_gradient.stops.*.opacity' => ['required', 'integer', 'between:0,100'],
            'login_gradient.stops.*.position' => ['nullable', 'integer', 'between:0,100'],
        ], ['login_gradient.stops.*.color.regex' => 'Colours must look like #7a1528.']);
        if (array_key_exists('login_gradient', $data) && $data['login_gradient']) {
            $g = $data['login_gradient'];
            $data['login_gradient'] = [
                'type' => $g['type'], 'angle' => (int) ($g['angle'] ?? 160),
                'stops' => array_values(array_map(fn ($s) => ['color' => strtolower($s['color']), 'opacity' => (int) $s['opacity'],
                    'position' => isset($s['position']) ? (int) $s['position'] : null], $g['stops'])),
            ];
        }
        Setting::current()->update($data);

        return Setting::current()->branding();
    }

    /** POST /api/v1/settings/login-image (admin) — jpg/png/webp, max 5 MB. Replaces the previous image. */
    public function uploadImage(Request $request)
    {
        $request->validate(['image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
        $s = Setting::current();
        $path = $request->file('image')->store('branding', 'local');
        if ($s->login_bg_image) {
            Storage::disk('local')->delete($s->login_bg_image);
        }
        $s->update(['login_bg_image' => $path]);

        return $s->branding();
    }

    public function deleteImage()
    {
        $s = Setting::current();
        if ($s->login_bg_image) {
            Storage::disk('local')->delete($s->login_bg_image);
            $s->update(['login_bg_image' => null]);
        }

        return $s->branding();
    }

    /** GET /branding/login-image — served by Laravel so it works even where storage symlinks are blocked. */
    public static function image()
    {
        $path = Setting::current()->login_bg_image;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'public, max-age=604800']);
    }
}
