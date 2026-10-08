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
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('web')->attempt([...$credentials, 'status' => 'active'], $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match an active account.']);
        }

        abort_unless($request->hasSession(), 400, 'Sign in from the Morena web app (first-party origin required).');
        $request->session()->regenerate();
        $user = $request->user();
        $user->forceFill(['last_login_at' => now()])->save();

        return $this->me($request);
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
            'landlord' => '/landlord/Dashboard.html',
            'tenant' => '/tenant/Dashboard.html',
            default => '/portal/Dashboard.html',
        };
    }
}
