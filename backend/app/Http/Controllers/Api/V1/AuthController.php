<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public const MAX_ATTEMPTS = 5;

    public const LOCK_MINUTES = 15;

    /**
     * Password step. Five wrong passwords lock the account for 15 minutes. If the user has two-factor
     * authentication on, the session only remembers who passed the password step until the code is given.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        abort_unless($request->hasSession(), 400, 'Sign in from the Morena web app (first-party origin required).');
        $user = User::where('email', $credentials['email'])->first();

        if ($user?->locked_until?->isFuture()) {
            $this->event($request, $credentials['email'], $user, false, 'locked');
            throw ValidationException::withMessages(['email' => 'Too many failed attempts. Try again after '.$user->locked_until->format('H:i').' or reset your password.']);
        }
        if (! $user || $user->status !== 'active' || ! Auth::guard('web')->validate($credentials)) {
            if ($user) {
                $fails = $user->failed_logins + 1;
                $user->forceFill(['failed_logins' => $fails, 'locked_until' => $fails >= self::MAX_ATTEMPTS ? now()->addMinutes(self::LOCK_MINUTES) : null])->save();
            }
            $this->event($request, $credentials['email'], $user, false, $user && $user->status !== 'active' ? 'inactive' : 'bad_password');
            throw ValidationException::withMessages(['email' => 'These credentials do not match an active account.']);
        }

        if ($user->two_factor_confirmed_at) {
            $request->session()->put('two_factor', ['user_id' => $user->id, 'remember' => $request->boolean('remember'), 'at' => now()->timestamp]);

            return ['two_factor_required' => true];
        }

        return $this->completeLogin($request, $user, $request->boolean('remember'));
    }

    /** Second step: 6-digit authenticator code, or one of the single-use recovery codes. */
    public function twoFactorChallenge(Request $request)
    {
        $code = $request->validate(['code' => ['required', 'string', 'max:20']])['code'];
        $pending = $request->session()->get('two_factor');
        if (! $pending || now()->timestamp - $pending['at'] > 300) {
            $request->session()->forget('two_factor');
            throw ValidationException::withMessages(['code' => 'Your sign-in timed out. Enter your password again.']);
        }
        $user = User::findOrFail($pending['user_id']);
        $ok = \App\Services\Totp::verify(decrypt($user->two_factor_secret), $code);
        if (! $ok) {
            $codes = $user->two_factor_recovery_codes ? json_decode(decrypt($user->two_factor_recovery_codes), true) : [];
            $normal = strtoupper(preg_replace('/\s/', '', $code));
            if (in_array($normal, $codes, true)) {
                $ok = true;
                $user->forceFill(['two_factor_recovery_codes' => encrypt(json_encode(array_values(array_diff($codes, [$normal]))))])->save();
            }
        }
        if (! $ok) {
            $this->event($request, $user->email, $user, false, '2fa_failed');
            throw ValidationException::withMessages(['code' => 'That code is not valid. Check your authenticator app (codes change every 30 seconds).']);
        }
        $request->session()->forget('two_factor');

        return $this->completeLogin($request, $user, $pending['remember']);
    }

    private function completeLogin(Request $request, User $user, bool $remember)
    {
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now(), 'failed_logins' => 0, 'locked_until' => null])->save();
        $this->event($request, $user->email, $user, true);

        return $this->me($request);
    }

    private function event(Request $request, string $email, ?User $user, bool $success, ?string $reason = null): void
    {
        \App\Models\LoginEvent::create([
            'user_id' => $user?->id, 'email' => $email, 'success' => $success, 'reason' => $reason,
            'ip' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 250), 'created_at' => now(),
        ]);
    }

    // ---- two-factor setup (signed-in user) ----------------------------------------------------

    public function twoFactorSetup(Request $request)
    {
        $user = $request->user();
        abort_if($user->two_factor_confirmed_at, 422, 'Two-factor authentication is already on.');
        $secret = \App\Services\Totp::secret();
        $user->forceFill(['two_factor_secret' => encrypt($secret)])->save();

        return ['secret' => trim(chunk_split($secret, 4, ' ')), 'uri' => \App\Services\Totp::uri($secret, $user->email)];
    }

    public function twoFactorConfirm(Request $request)
    {
        $code = $request->validate(['code' => ['required', 'string']])['code'];
        $user = $request->user();
        abort_unless($user->two_factor_secret, 422, 'Start the setup first.');
        if (! \App\Services\Totp::verify(decrypt($user->two_factor_secret), $code)) {
            throw ValidationException::withMessages(['code' => 'That code is not valid — make sure the phone\'s clock is set automatically.']);
        }
        $codes = collect(range(1, 8))->map(fn () => strtoupper(\Illuminate\Support\Str::random(5).'-'.\Illuminate\Support\Str::random(5)))->all();
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => encrypt(json_encode($codes))])->save();
        \App\Models\AuditLog::write('2fa_enabled', 'Two-factor authentication turned on', $user);

        return ['recovery_codes' => $codes];
    }

    public function twoFactorDisable(Request $request)
    {
        $request->validate(['password' => ['required', 'string']]);
        $user = $request->user();
        if (! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages(['password' => 'Your password is incorrect.']);
        }
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();
        \App\Models\AuditLog::write('2fa_disabled', 'Two-factor authentication turned off', $user);

        return response()->noContent();
    }

    /** Recent sign-ins for the signed-in user (security page). */
    public function loginHistory(Request $request)
    {
        return ['data' => \App\Models\LoginEvent::where('user_id', $request->user()->id)->latest('created_at')->limit(20)->get()
            ->map(fn ($e) => ['at' => $e->created_at->toIso8601String(), 'success' => $e->success, 'reason' => $e->reason, 'ip' => $e->ip, 'device' => $e->user_agent])];
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    /** Returns user + role + nav scope (handover §4) plus org branding used by every page shell. */
    public function me(Request $request)
    {
        /** @var User $user */
        $user = $request->user()->load('staff.branch', 'branch');
        $settings = Setting::current();

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'initials' => $user->initials(),
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->primaryRole(),
                'nav_role' => $user->navRole(),
                'branch' => $user->branch?->name,
                'staff_id' => $user->staff?->id,
                'two_factor_enabled' => (bool) $user->two_factor_confirmed_at,
            ],
            'home' => self::homeFor($user),
            'settings' => [
                'company_name' => $settings->company_name,
                'sidebar_logo_width' => $settings->sidebar_logo_width,
            ],
        ];
    }

    public function updatePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', PasswordRule::min(8)],
        ]);

        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Your current password is incorrect.']);
        }
        $user->update(['password' => $data['password']]);

        return response()->noContent();
    }

    /** POST /auth/forgot-password — always 204 so the endpoint can't be used to probe for accounts. */
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::broker()->sendResetLink($request->only('email'));

        return response()->noContent();
    }

    /** POST /auth/reset-password — used by both "forgot password" and invite links. */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', PasswordRule::min(8)],
        ]);

        $status = Password::broker()->reset(
            $request->only('email', 'password', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                    'status' => $user->status === 'invited' ? 'active' : $user->status,
                ])->save();
            }
        );
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return response()->noContent();
    }

    /** Landing page after login, per role. */
    public static function homeFor(User $user): string
    {
        return match ($user->primaryRole()) {
            'admin' => '/admin/Dashboard.html',
            'branch_manager' => '/manager/Dashboard.html',
            'hr' => '/hr/StaffDirectory.html',
            'finance' => '/finance/Dashboard.html',
            'landlord' => '/landlord/Dashboard.html',
            'tenant' => '/tenant/Dashboard.html',
            default => '/portal/Dashboard.html',
        };
    }
}
