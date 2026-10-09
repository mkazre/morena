<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Mailer;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        $this->withHeader('Referer', 'http://localhost:8000/auth/Login.html');
    }

    public function test_branding_is_public_and_only_admins_change_it(): void
    {
        $this->getJson('/api/v1/branding')->assertOk()
            ->assertJsonPath('system_name', 'Morena CRM')
            ->assertJsonPath('version.number', config('morena.version'))
            ->assertJsonPath('login.gradient.type', 'linear');

        $this->actingAs($this->makeUser('agent'));
        $this->putJson('/api/v1/settings/branding', ['system_name' => 'Hacked'])->assertForbidden();

        $this->actingAs($this->makeUser('admin'));
        $this->putJson('/api/v1/settings/branding', [
            'system_name' => 'Morena Workspace', 'login_headline' => 'Welcome home',
            'login_gradient' => ['type' => 'radial', 'angle' => 45, 'stops' => [
                ['color' => '#7A1528', 'opacity' => 80, 'position' => 0], ['color' => '#1c1a19', 'opacity' => 40], ['color' => '#c23552', 'opacity' => 0, 'position' => 100],
            ]],
        ])->assertOk()->assertJsonPath('system_name', 'Morena Workspace')->assertJsonPath('login.gradient.stops.0.color', '#7a1528')
            ->assertJsonPath('login.gradient.stops.1.position', null)->assertJsonPath('login.headline', 'Welcome home');

        $this->putJson('/api/v1/settings/branding', ['login_gradient' => ['type' => 'linear', 'stops' => [['color' => 'red', 'opacity' => 50]]]])
            ->assertUnprocessable()->assertJsonValidationErrors('login_gradient.stops.0.color');
        $this->putJson('/api/v1/settings/branding', ['login_gradient' => ['type' => 'linear', 'stops' => [['color' => '#ffffff', 'opacity' => 150]]]])
            ->assertUnprocessable();

        $this->getJson('/api/v1/auth/me')->assertJsonPath('settings.system_name', 'Morena Workspace');
    }

    public function test_login_image_upload_serve_and_remove(): void
    {
        Storage::fake('local');
        $this->actingAs($this->makeUser('admin'));
        $url = $this->post('/api/v1/settings/login-image', ['image' => UploadedFile::fake()->image('bg.jpg', 1600, 1200)], ['Accept' => 'application/json'])
            ->assertOk()->json('login.image_url');
        $this->assertStringContainsString('/branding/login-image?v=', $url);
        $this->get(parse_url($url, PHP_URL_PATH))->assertOk();

        $this->post('/api/v1/settings/login-image', ['image' => UploadedFile::fake()->create('evil.php', 10, 'application/x-php')], ['Accept' => 'application/json'])
            ->assertUnprocessable();

        $this->deleteJson('/api/v1/settings/login-image')->assertOk()->assertJsonPath('login.image_url', null);
        $this->get('/branding/login-image')->assertNotFound();
        $this->assertSame([], Storage::disk('local')->allFiles('branding'));
    }

    public function test_system_emails_use_the_branded_layout_with_embedded_logo(): void
    {
        // Render the same views the Mailer sends.
        $html = view('emails.branded', ['body' => "Hi Thabo,\nSee https://example.com/x", 'heading' => 'Weekly summary', 'actionUrl' => 'https://app.test/a', 'actionText' => 'Open'])->render();
        $this->assertStringContainsString('assets/logo.png', $html);
        $this->assertStringContainsString('#7a1528', $html);
        $this->assertStringContainsString('<a href="https://example.com/x"', $html);
        $this->assertStringContainsString('Hi Thabo,<br', $html);
        $this->assertStringContainsString(Setting::current()->company_name, $html);
        $this->assertStringNotContainsString('<script', view('emails.branded', ['body' => '<script>alert(1)</script>'])->render());

        // Real send through the array mailer: HTML part embeds the logo (cid:) and a text part exists.
        config(['mail.default' => 'array']);
        $log = app(Mailer::class)->send('client@example.com', 'Hello', "Line one\nLine two");
        $this->assertSame('sent', $log->status);
        $sent = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertStringContainsString('cid:', $sent->getHtmlBody());
        $this->assertStringContainsString('Line one', $sent->getTextBody());
        $this->assertNotEmpty($sent->getAttachments()); // the embedded logo
    }

    public function test_invite_and_reset_emails_are_branded(): void
    {
        Notification::fake();
        $invited = $this->makeUser('agent', attrs: ['email' => 'new@example.com', 'status' => 'invited', 'name' => 'Lerato Dube']);
        $active = $this->makeUser('agent', attrs: ['email' => 'old@example.com', 'name' => 'Sipho Khumalo']);
        $invited->notify(new ResetPassword('tok1'));
        $active->notify(new ResetPassword('tok2'));

        Notification::assertSentTo($invited, ResetPassword::class, function ($n) use ($invited) {
            $m = $n->toMail($invited);
            $this->assertStringStartsWith('Welcome to', $m->subject);
            $this->assertSame('emails.branded', $m->view[0]);
            $this->assertStringContainsString('token=tok1', $m->viewData['actionUrl']);

            return true;
        });
        Notification::assertSentTo($active, ResetPassword::class, fn ($n) => str_starts_with($n->toMail($active)->subject, 'Reset your'));
    }

    public function test_finance_charts_endpoint(): void
    {
        $this->actingAs($this->makeUser('admin'));
        $res = $this->getJson('/api/v1/finance/dashboard/charts')->assertOk()->json();
        $this->assertCount(12, $res['months']);
        $this->assertCount(5, $res['receivables_aging']);
        $this->assertArrayHasKey('cash', $res['months'][11]);
        $this->actingAs($this->makeUser('agent'));
        $this->getJson('/api/v1/finance/dashboard/charts')->assertForbidden();
    }
}
