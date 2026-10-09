<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use \App\Models\Concerns\Audited;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payprop_connected' => 'boolean', 'whatsapp_connected' => 'boolean', 'login_gradient' => 'array'];
    }

    /** The sign-in panel design used when nothing has been customised (the original charcoal → maroon). */
    public const DEFAULT_GRADIENT = ['type' => 'linear', 'angle' => 160, 'stops' => [
        ['color' => '#1c1a19', 'opacity' => 100, 'position' => 0],
        ['color' => '#3a1420', 'opacity' => 100, 'position' => 70],
    ]];

    /** Public branding: footer, sign-in page and email header. Safe to expose without signing in. */
    public function branding(): array
    {
        return [
            'company_name' => $this->company_name,
            'system_name' => $this->system_name ?: 'Morena CRM',
            'version' => self::version(),
            'login' => [
                'gradient' => $this->login_gradient ?: self::DEFAULT_GRADIENT,
                'image_url' => $this->login_bg_image ? url('/branding/login-image').'?v='.substr(md5($this->login_bg_image), 0, 8) : null,
                'headline' => $this->login_headline,
            ],
        ];
    }

    /** "1.4.0 (build 20261009-1200)" — the build stamp comes from the release zip's manifest. */
    public static function version(): array
    {
        $build = null;
        $manifest = base_path('release-manifest.txt');
        if (is_file($manifest) && ($first = (new \SplFileObject($manifest))->fgets()) && str_starts_with($first, '# ')) {
            $build = trim(substr($first, 2));
        }

        return ['number' => config('morena.version'), 'build' => $build];
    }

    public static function current(): self
    {
        $s = static::query()->firstOrCreate(['id' => 1]);

        // A just-created row has no DB defaults loaded yet.
        return $s->wasRecentlyCreated ? $s->fresh() : $s;
    }
}
