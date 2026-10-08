<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        // Sanctum only starts a session for first-party (stateful) origins, as the browser pages are.
        $this->withHeader('Referer', 'http://localhost:8000/auth/Login.html');
    }

    public function test_login_returns_role_nav_and_home(): void
    {
        $this->makeUser('branch_manager', attrs: ['email' => 'kagiso@example.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'kagiso@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.role', 'branch_manager')
            ->assertJsonPath('user.nav_role', 'manager')
            ->assertJsonPath('home', '/manager/Dashboard.html');

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.email', 'kagiso@example.com');
    }

    public function test_suspended_and_wrong_password_are_rejected(): void
    {
        $this->makeUser('agent', attrs: ['email' => 'gone@example.com', 'status' => 'suspended']);

        $this->postJson('/api/v1/auth/login', ['email' => 'gone@example.com', 'password' => 'password'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['email' => 'gone@example.com', 'password' => 'nope'])->assertUnprocessable();
    }

    public function test_api_requires_auth_and_role(): void
    {
        $this->getJson('/api/v1/deals')->assertUnauthorized();

        $this->actingAs($this->makeUser('tenant'));
        $this->getJson('/api/v1/deals')->assertForbidden();
        $this->getJson('/api/v1/dashboard/admin')->assertForbidden();
    }

    public function test_hr_adding_staff_creates_invited_login_with_leave_and_certification(): void
    {
        Notification::fake();
        $this->actingAs($this->makeUser('hr'));
        $this->postJson('/api/v1/staff', [
            'name' => 'Palesa New', 'email' => 'palesa@example.com', 'role_title' => 'Sales Agent',
            'department' => 'sales', 'employment_type' => 'independent_contractor',
        ])->assertCreated();

        $user = User::where('email', 'palesa@example.com')->first();
        $this->assertSame('invited', $user->status);
        $this->assertTrue($user->hasRole('agent'));
        $this->assertNotNull($user->staff->leaveBalance);
        $this->assertNotNull($user->staff->certification);
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_invited_user_activates_via_reset_link(): void
    {
        Notification::fake();
        $this->actingAs($this->makeUser('admin'));
        $this->postJson('/api/v1/users/invite', ['name' => 'New Agent', 'email' => 'new@example.com', 'role' => 'agent'])
            ->assertOk()->assertJsonPath('status', 'invited');

        $token = null;
        Notification::assertSentTo(User::where('email', 'new@example.com')->first(), ResetPassword::class,
            function ($n) use (&$token) {
                $token = $n->token;

                return true;
            });

        auth()->guard('web')->logout();
        $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => 'new@example.com', 'password' => 'a-strong-pass'])->assertNoContent();
        $this->postJson('/api/v1/auth/login', ['email' => 'new@example.com', 'password' => 'a-strong-pass'])->assertOk();
    }
}
