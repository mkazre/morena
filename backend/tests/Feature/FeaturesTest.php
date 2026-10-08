<?php

namespace Tests\Feature;

use App\Models\Accounting\Account;
use App\Models\Accounting\Contact;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\RecurringDocument;
use App\Models\Accounting\TaxRate;
use App\Models\AppNotification;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Certification;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class FeaturesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        $this->withHeader('Referer', 'http://localhost:8000/auth/Login.html');
    }

    /** Drop the session user and the guards cached by actingAs(), like a fresh browser. */
    private function signOut(): void
    {
        auth()->guard('web')->logout();
        $this->app['auth']->forgetGuards();
    }

    public function test_totp_matches_rfc6238_vector(): void
    {
        // RFC 6238 test secret "12345678901234567890" (base32 GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ) at T=59s → 287082
        $this->assertSame('287082', Totp::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', intdiv(59, 30)));
        $this->assertTrue(Totp::verify('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287082', 59));
        $this->assertFalse(Totp::verify('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '000000', 59));
    }

    public function test_two_factor_login_flow_and_recovery_code(): void
    {
        $user = $this->makeUser('admin', attrs: ['email' => 'boss@example.com']);
        $this->actingAs($user);
        $secret = str_replace(' ', '', $this->postJson('/api/v1/auth/two-factor/setup')->assertOk()->json('secret'));
        $codes = $this->postJson('/api/v1/auth/two-factor/confirm', ['code' => Totp::code($secret, intdiv(time(), 30))])->assertOk()->json('recovery_codes');
        $this->assertCount(8, $codes);
        $this->signOut();

        $this->postJson('/api/v1/auth/login', ['email' => 'boss@example.com', 'password' => 'password'])->assertOk()->assertJson(['two_factor_required' => true]);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized(); // password alone isn't enough
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => '000000'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => $codes[0]])->assertOk()->assertJsonPath('user.two_factor_enabled', true);
        // A recovery code works once only.
        $this->signOut();
        $this->postJson('/api/v1/auth/login', ['email' => 'boss@example.com', 'password' => 'password']);
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => $codes[0]])->assertUnprocessable();
    }

    public function test_lockout_after_five_failures(): void
    {
        $this->makeUser('agent', attrs: ['email' => 'a@example.com']);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'password'])
            ->assertUnprocessable()->assertJsonFragment(['Too many failed attempts. Try again after '.User::where('email', 'a@example.com')->first()->locked_until->format('H:i').' or reset your password.']);
        $this->assertSame(6, \App\Models\LoginEvent::where('email', 'a@example.com')->where('success', false)->count());
    }

    public function test_lead_duplicates_scoring_and_import(): void
    {
        $agent = $this->makeAgent();
        $this->actingAs($agent->user);
        $this->postJson('/api/v1/leads', ['name' => 'Thabo M', 'phone' => '082 441 0098', 'source' => 'referral'])->assertCreated();
        $this->postJson('/api/v1/leads', ['name' => 'Thabo Again', 'phone' => '+27 82 441 0098', 'source' => 'website'])
            ->assertStatus(409)->assertJsonPath('duplicates.0.name', 'Thabo M');
        $this->postJson('/api/v1/leads', ['name' => 'Thabo Again', 'phone' => '+27 82 441 0098', 'source' => 'website', 'confirm_duplicate' => true])->assertCreated();
        $this->assertGreaterThan(40, Lead::where('name', 'Thabo M')->value('score')); // referral 30 + phone 15

        $csv = "Name,Phone,Email,Interest,Source,Consent\nZodwa K,0711112222,z@example.com,3-bed Midrand,walk-in,yes\nDup,082 441 0098,,,,\n";
        $res = $this->post('/api/v1/leads/import', ['file' => UploadedFile::fake()->createWithContent('leads.csv', $csv)], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame(1, $res['created']);
        $this->assertSame('duplicate', $res['skipped'][0]['reason']);
        $z = Lead::where('name', 'Zodwa K')->first();
        $this->assertTrue($z->marketing_consent);
        $this->assertNotNull($z->consent_recorded_at);
        $this->assertSame($agent->id, $z->assigned_agent_id);
    }

    public function test_tasks_appointments_my_day_and_ical_feed(): void
    {
        $agent = $this->makeAgent();
        $this->actingAs($agent->user);
        $this->postJson('/api/v1/tasks', ['title' => 'Call seller', 'due_at' => now()->subHour()->toIso8601String(), 'priority' => 'high'])->assertOk();
        $this->postJson('/api/v1/appointments', ['type' => 'viewing', 'title' => 'Viewing 14 Rivonia', 'starts_at' => now()->addHours(2)->toIso8601String(), 'location' => 'Rivonia'])->assertOk();
        $day = $this->getJson('/api/v1/my-day')->assertOk()->json();
        $this->assertSame('Call seller', $day['tasks'][0]['title']);
        $this->assertSame('Viewing 14 Rivonia', $day['appointments'][0]['title']);

        $url = $this->getJson('/api/v1/calendar/feed-url')->assertOk()->json('url');
        $this->signOut();
        $ics = $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->getContent();
        $this->assertStringContainsString('SUMMARY:Viewing: Viewing 14 Rivonia', $ics);
        $this->assertStringContainsString('SUMMARY:Task: Call seller', $ics);
        $this->get('/calendar/'.str_repeat('x', 40).'.ics')->assertNotFound();
    }

    public function test_mentions_notify_and_search_is_scoped(): void
    {
        $me = $this->makeAgent(name: 'Mpho Agent');
        $other = $this->makeAgent(name: 'Lindi Other');
        $admin = $this->makeUser('admin', attrs: ['name' => 'Big Boss']);
        $stage = PipelineStage::ordered()->value('id');
        $mine = Deal::create(['property_title' => '7 Mine Street', 'sale_value' => 1, 'pipeline_stage_id' => $stage, 'selling_agent_id' => $me->id]);
        Deal::create(['property_title' => '9 Their Street', 'sale_value' => 1, 'pipeline_stage_id' => $stage, 'selling_agent_id' => $other->id]);

        $this->actingAs($admin);
        $this->postJson("/api/v1/deals/{$mine->id}/comments", ['body' => '@Mpho Agent please chase the FICA'])->assertOk();
        $this->assertSame(1, AppNotification::where('user_id', $me->user_id)->where('type', 'mention')->count());

        $this->actingAs($me->user);
        $titles = collect($this->getJson('/api/v1/search?q=Street')->json('data'))->pluck('title');
        $this->assertContains('7 Mine Street', $titles);
        $this->assertNotContains('9 Their Street', $titles);
        $this->getJson('/api/v1/notifications')->assertJsonPath('unread', 1);
        $this->postJson('/api/v1/notifications/read')->assertJsonPath('unread', 0);
    }

    public function test_daily_job_alerts_are_deduplicated(): void
    {
        $agent = $this->makeAgent();
        Certification::create(['staff_id' => $agent->id, 'ffc_number' => 'FFC1', 'ffc_expiry' => now()->addDays(20)->toDateString(), 'cpd_points' => 10]);
        Artisan::call('morena:daily');
        Artisan::call('morena:daily');
        $this->assertSame(1, AppNotification::where('user_id', $agent->user_id)->where('type', 'expiry')->count());
    }

    public function test_recurring_bill_runs_once_per_period(): void
    {
        $supplier = Contact::create(['name' => 'Landlord Pty', 'type' => 'supplier']);
        RecurringDocument::create(['kind' => 'bill', 'contact_id' => $supplier->id, 'frequency' => 'monthly', 'day_of_month' => 1,
            'next_run_on' => now()->startOfMonth()->toDateString(), 'lines' => [['description' => 'Office rent', 'account_id' => Account::where('code', '6030')->value('id'),
                'tax_rate_id' => TaxRate::where('code', 'STD')->value('id'), 'quantity' => 1, 'unit_price' => 20000]]]);
        $this->assertSame(1, \App\Http\Controllers\Api\V1\Finance\ExtrasController::runRecurring());
        $this->assertSame(0, \App\Http\Controllers\Api\V1\Finance\ExtrasController::runRecurring());
        $this->assertSame(23000.0, \App\Models\Accounting\Bill::first()->total);
        $this->assertSame(now()->startOfMonth()->addMonth()->toDateString(), RecurringDocument::first()->next_run_on->toDateString());
    }

    public function test_budget_vs_actual_and_audit_trail(): void
    {
        $this->actingAs($this->makeUser('admin'));
        $rent = Account::where('code', '6030')->value('id');
        $month = now()->format('Y-m');
        $this->putJson('/api/v1/finance/budgets', ['account_id' => $rent, 'amounts' => [$month => 25000]])->assertOk();
        $this->postJson('/api/v1/finance/payments', ['direction' => 'made', 'bank_account_id' => Account::system('bank_main')->id, 'date' => now()->toDateString(),
            'lines' => [['account_id' => $rent, 'amount' => 30000]]])->assertOk();
        $row = collect($this->getJson('/api/v1/finance/reports/budget-vs-actual')->assertOk()->json('rows'))->firstWhere('code', '6030');
        $this->assertSame(25000, (int) $row['budget']);
        $this->assertSame(30000, (int) $row['actual']);
        $this->assertSame(120, $row['percent']);

        $this->putJson('/api/v1/settings', ['company_name' => 'Morena Group'])->assertOk();
        $log = AuditLog::where('subject_type', \App\Models\Setting::class)->latest('id')->first();
        $this->assertSame(['Morena Properties', 'Morena Group'], $log->changes['company_name']);
        $this->getJson('/api/v1/audit')->assertOk()->assertJsonFragment(['event' => 'updated']);
    }

    public function test_cma_and_targets(): void
    {
        $agent = $this->makeAgent();
        $stage = PipelineStage::finalStage()->id;
        foreach ([1_500_000, 1_800_000, 2_100_000] as $i => $price) {
            $l = \App\Models\Listing::create(['title' => "$i Oak Rd", 'suburb' => 'Fourways', 'price' => $price, 'beds' => 3, 'agent_id' => $agent->id, 'status' => 'sold', 'listed_at' => now()->subDays(60)]);
            Deal::create(['property_title' => $l->title, 'listing_id' => $l->id, 'sale_value' => $price, 'pipeline_stage_id' => $stage, 'selling_agent_id' => $agent->id,
                'status' => 'closed', 'closed_at' => now()->subDays(5)]);
        }
        $this->actingAs($agent->user);
        $cma = $this->getJson('/api/v1/cma?suburb=Fourways&beds=3&asking_price=2000000')->assertOk()->json();
        $this->assertSame(3, $cma['count']);
        $this->assertEquals(1800000, $cma['median']);
        $this->assertEquals(11.1, $cma['asking_vs_median_percent']);

        $this->actingAs($this->makeUser('admin'));
        $this->putJson('/api/v1/targets', ['staff_id' => $agent->id, 'period' => now()->format('Y-m'), 'deals_target' => 4, 'value_target' => 8000000, 'listings_target' => 2])->assertSuccessful();
        $row = collect($this->getJson('/api/v1/targets')->json('data'))->firstWhere('staff_id', $agent->id);
        $this->assertSame(3, $row['deals']);
        $this->assertSame(4, $row['deals_target']);
    }
}
