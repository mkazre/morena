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

        // Same branded layout as every other system email. New (invited) users get a welcome, others a reset.
        ResetPassword::toMailUsing(function ($user, string $token) {
            $s = \App\Models\Setting::current();
            $url = url('/auth/Login.html?token='.$token.'&email='.urlencode($user->email));
            $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);
            $invited = $user->status === 'invited';
            $first = strtok((string) $user->name, ' ');

            return (new \Illuminate\Notifications\Messages\MailMessage)
                ->subject($invited ? 'Welcome to '.$s->company_name.' — set your password' : 'Reset your '.($s->system_name ?: 'Morena CRM').' password')
                ->view(['emails.branded', 'emails.branded-text'], $invited ? [
                    'heading' => 'Welcome, '.$first,
                    'body' => "An account has been created for you on {$s->system_name}, {$s->company_name}'s workspace for sales, people, documents and training.\n\nSet your password to sign in for the first time.",
                    'actionUrl' => $url, 'actionText' => 'Set my password',
                    'note' => "This link expires in {$minutes} minutes. If it has expired, use \"Forgot password?\" on the sign-in page.",
                ] : [
                    'heading' => 'Reset your password',
                    'body' => "Hi {$first},\n\nWe received a request to reset the password for your {$s->system_name} account.",
                    'actionUrl' => $url, 'actionText' => 'Choose a new password',
                    'note' => "This link expires in {$minutes} minutes. If you didn't ask for this, you can ignore this email — your password stays the same.",
                ]);
        });
    }
}
