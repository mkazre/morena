<?php

namespace App\Providers;

use App\Services\Messaging\LogChannel;
use App\Services\Messaging\NotificationChannel;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Swap for a WhatsApp Business implementation once credentials exist (admin/Settings → Integrations).
        $this->app->bind(NotificationChannel::class, LogChannel::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Behind cPanel's Apache the app may not see the original scheme; keep generated links (upload links,
        // reset emails) on https whenever APP_URL is https.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Reset / invite emails point at the static login page, which switches to "set password" mode.
        ResetPassword::createUrlUsing(fn ($user, string $token) => url('/auth/Login.html?token='.$token.'&email='.urlencode($user->email)));
    }
}
