<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Certification;
use App\Models\ClientUploadLink;
use App\Models\Commission;
use App\Models\Contract;
use App\Models\Course;
use App\Models\CourseCompletion;
use App\Models\Deal;
use App\Models\DealActivityLog;
use App\Models\DealStageHistory;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentTemplate;
use App\Models\Lead;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LessonCompletion;
use App\Models\Listing;
use App\Models\OnboardingChecklist;
use App\Models\PipelineStage;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\CommissionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Sample agency mirroring the mock data in the UI designs, plus ~2 years of generated history so
 * analytics, commission tiers and retention have realistic numbers. Login for every user: "password".
 */
class DemoSeeder extends Seeder
{
    private const PLACEHOLDER = 'documents/demo/placeholder.pdf';

    /** @var array<string, Staff> */
    private array $staff = [];

    /** @var array<string, User> */
    private array $portalUsers = [];

    private $stages;

    private $categories;

    public function run(): void
    {
        mt_srand(2026);
        $this->stages = PipelineStage::ordered()->get()->values();
        $this->categories = DocumentCategory::pluck('id', 'name');
        Storage::put(self::PLACEHOLDER, self::pdf('Morena Properties — sample document'));

        Setting::current()->update([
            'company_name' => 'Morena Properties', 'vat_number' => '4230198765',
            'whatsapp_connected' => true, 'support_whatsapp' => '082 334 1120',
        ]);

        DB::transaction(function () {
            $this->people();
            $this->history();
            $this->openDeals();
            $this->leads();
            $this->commissions();
            $this->templates();
            $this->documents();
            $this->uploadLinks();
            $this->hr();
            $this->training();
        });
    }

    // ---------------------------------------------------------------------------------------------
    private function people(): void
    {
        $branches = collect(['Sandton', 'Pretoria', 'Midrand'])->mapWithKeys(fn ($b) => [$b => Branch::firstOrCreate(['name' => $b])->id]);

        // key, name, email, phone, role, title, dept, branch, employment, start, ffc, ffc expiry, status, last login
        $people = [
            ['thandiwe', 'Thandiwe Morena', 'thandiwe@morenaproperties.co.za', '082 100 2000', 'admin', 'Managing Director', 'admin', 'Sandton', 'permanent', '2015-03-01', null, null, 'active', '08:12'],
            ['kagiso', 'Kagiso Molefe', 'kagiso.m@morenaproperties.co.za', '083 455 1290', 'branch_manager', 'Branch Manager', 'admin', 'Pretoria', 'permanent', '2022-01-14', null, null, 'on_leave', '07:50'],
            ['busisiwe', 'Busisiwe Nkosi', 'busisiwe.n@morenaproperties.co.za', '072 118 4432', 'hr', 'HR Manager', 'hr', 'Sandton', 'permanent', '2020-02-01', null, null, 'active', '-1 day'],
            ['anita', 'Anita Singh', 'anita.s@morenaproperties.co.za', '076 901 2245', 'hr', 'Finance Administrator', 'finance', 'Sandton', 'permanent', '2018-06-09', null, null, 'active', '-3 days'],
            ['naledi', 'Naledi Mokoena', 'naledi.m@morenaproperties.co.za', '082 334 1120', 'agent', 'Senior Sales Agent', 'sales', 'Sandton', 'independent_contractor', '2021-03-03', 'FFC2021038812', '2026-12-31', 'active', '09:02'],
            ['sipho', 'Sipho Khumalo', 'sipho.k@morenaproperties.co.za', '071 556 8890', 'agent', 'Senior Sales Agent', 'sales', 'Sandton', 'independent_contractor', '2019-07-18', 'FFC2019027741', '2026-12-31', 'active', '-2 days'],
            ['riaan', 'Riaan Pretorius', 'riaan.p@morenaproperties.co.za', '083 772 0041', 'agent', 'Senior Sales Agent', 'sales', 'Pretoria', 'independent_contractor', '2018-04-02', 'FFC2018019203', '2026-12-31', 'active', '-1 day'],
            ['lerato', 'Lerato Dlamini', 'lerato.d@morenaproperties.co.za', '079 230 6612', 'agent', 'Sales Agent', 'sales', 'Sandton', 'independent_contractor', '2022-05-16', 'FFC2022044190', '2026-12-31', 'active', '-4 days'],
            ['johan', 'Johan van der Merwe', 'johan.v@morenaproperties.co.za', '084 610 7753', 'agent', 'Junior Sales Agent', 'sales', 'Pretoria', 'independent_contractor', '2026-09-02', null, null, 'active', '-1 day'],
            ['palesa', 'Palesa Mahlangu', 'palesa.m@morenaproperties.co.za', '061 448 9021', 'agent', 'Sales Agent', 'sales', 'Pretoria', 'independent_contractor', '2026-10-13', null, null, 'active', null],
            ['thabo', 'Thabo Nkosi', 'thabo.n@morenaproperties.co.za', '065 330 1187', 'agent', 'Admin Assistant', 'admin', 'Pretoria', 'permanent', '2026-10-20', null, null, 'active', null],
        ];

        foreach ($people as [$key, $name, $email, $phone, $role, $title, $dept, $branch, $emp, $start, $ffc, $ffcExp, $status, $login]) {
            $user = User::create([
                'name' => $name, 'email' => $email, 'phone' => $phone, 'password' => 'password',
                'branch_id' => $branches[$branch], 'status' => 'active',
                'last_login_at' => $login ? (str_contains($login, ':') ? now()->setTimeFromTimeString($login) : now()->modify($login)) : null,
            ]);
            $user->assignRole($role);
            $this->staff[$key] = $user->staff()->create([
                'role_title' => $title, 'department' => $dept, 'branch_id' => $branches[$branch],
                'employment_type' => $emp, 'start_date' => $start, 'ffc_number' => $ffc, 'ffc_expiry' => $ffcExp, 'status' => $status,
            ]);
        }

        $landlord = User::create(['name' => 'M. & S. Abrahams', 'email' => 'abrahams@example.co.za', 'phone' => '082 902 1145', 'password' => 'password', 'last_login_at' => now()->subDays(5)]);
        $landlord->assignRole('landlord');
        $tenant = User::create(['name' => 'R. Dube', 'email' => 'r.dube@example.co.za', 'phone' => '073 118 5520', 'password' => 'password']);
        $tenant->assignRole('tenant');
        $this->portalUsers = ['landlord' => $landlord, 'tenant' => $tenant];
        Tenancy::create(['tenant_user_id' => $tenant->id, 'property_title' => 'Unit 12, Oakfield Estate', 'lease_start' => '2026-04-01', 'lease_end' => '2027-03-31']);
    }

    // ---------------------------------------------------------------------------------------------
    /** ~2 years of closed and lost deals (with listings, leads and stage history) for analytics. */
    private function history(): void
    {
        $perMonth = [3, 3, 4, 3, 5, 4, 5, 5, 6, 6, 7, 7, /* last 12 → */ 4, 3, 5, 4, 6, 5, 7, 6, 8, 7, 9, 0];
        $streets = ['Dainfern Close', 'Edenvale Terrace', 'Benmore Gardens', 'Morningside Drive', 'Waterkloof Ridge', 'Lynnwood Road', 'Hurlingham Ave',
            'Craighall Park', 'Menlyn Views', 'Glen Austin Way', 'Kyalami Estate', 'Bedfordview Lane', 'Illovo Square', 'Brooklyn Crescent',
            'Sunninghill Close', 'Faerie Glen Road', 'Paulshof Gardens', 'Garsfontein Drive', 'Woodmead Place', 'Parkhurst 4th Ave'];
        $start = now()->startOfMonth()->subMonths(23);

        foreach ($perMonth as $i => $n) {
            $month = $start->copy()->addMonths($i);
            for ($k = 0; $k < $n; $k++) {
                $closedAt = $month->copy()->addDays(mt_rand(0, $month->daysInMonth - 1))->setTime(mt_rand(8, 17), mt_rand(0, 59));
                $this->closedDeal(mt_rand(1, 60).' '.$streets[array_rand($streets)], $closedAt);
            }
        }
        // October so far, so "this month" dashboards have data.
        foreach ([1, 2, 4, 6] as $day) {
            $this->closedDeal(mt_rand(1, 60).' '.$streets[array_rand($streets)], now()->startOfMonth()->addDays($day - 1)->setTime(11, 0));
        }
        // Named closed deals referenced in the designs.
        $this->closedDeal('9 Dainfern Close', Carbon::parse('2026-09-14 10:00'), 'lerato', 'naledi', 2_750_000);
        $this->closedDeal('5 Edenvale Terrace', Carbon::parse('2026-09-05 10:00'), 'johan', null, 1_200_000);

        // Lost deals: [reason, count in last 90 days, count older]
        $reasons = [['bond_declined', 5, 6], ['price_disagreement', 4, 5], ['buyer_withdrew', 3, 4], ['chose_another_property', 2, 3], ['seller_withdrew_mandate', 1, 2]];
        foreach ($reasons as [$reason, $recent, $older]) {
            for ($k = 0; $k < $recent + $older; $k++) {
                $lostAt = now()->subDays($k < $recent ? mt_rand(3, 85) : mt_rand(100, 360));
                $this->lostDeal(mt_rand(1, 60).' '.$streets[array_rand($streets)], $lostAt, $reason);
            }
        }
    }

    private function closedDeal(string $title, Carbon $closedAt, ?string $selling = null, ?string $listing = null, ?int $value = null): Deal
    {
        $selling ??= $this->pickAgent($closedAt);
        $listing ??= mt_rand(1, 100) <= 15 ? $this->pickAgent($closedAt, exclude: $selling) : $selling;
        $value ??= mt_rand(90, 550) * 10_000;
        $agent = $this->staff[$selling];

        // Days spent in each stage before the next one (jittered); last stage is "Deal Closed".
        $durations = collect([3, 2, 4, 8, 2, 4, 10, 5, 14])->map(fn ($d) => max(1, (int) round($d * mt_rand(70, 130) / 100)));
        $createdAt = $closedAt->copy()->subDays($durations->sum());

        $listingModel = $this->listing($title, $agent, $value, 'sold', $createdAt->copy()->subDays(mt_rand(10, 60)));
        $lead = $this->historyLead($agent, $createdAt);
        $deal = Deal::create([
            'listing_id' => $listingModel->id, 'lead_id' => $lead->id, 'branch_id' => $agent->branch_id,
            'property_title' => $title, 'sale_value' => $value, 'pipeline_stage_id' => $this->stages->last()->id,
            'listing_agent_id' => $this->staff[$listing]->id, 'selling_agent_id' => $agent->id,
            'buyer_name' => $lead->name, 'seller_name' => $this->surname().' family',
            'mandate_type' => mt_rand(0, 3) ? 'sole' : 'open', 'bond_required' => (bool) mt_rand(0, 4), 'bond_ltv_percent' => 80,
            'expected_close_date' => $closedAt->toDateString(), 'status' => 'closed', 'closed_at' => $closedAt,
            'created_at' => $createdAt, 'updated_at' => $closedAt,
        ]);
        $this->stageHistory($deal, $createdAt, $durations->all(), $this->stages->count());

        return $deal;
    }

    private function lostDeal(string $title, Carbon $lostAt, string $reason): void
    {
        $agentKey = $this->pickAgent($lostAt);
        $agent = $this->staff[$agentKey];
        $reached = mt_rand(1, 6); // index of the stage the deal died in
        $durations = collect(range(0, $reached))->map(fn () => mt_rand(2, 9));
        $createdAt = $lostAt->copy()->subDays($durations->sum());
        $lead = $this->historyLead($agent, $createdAt);

        $deal = Deal::create([
            'lead_id' => $lead->id, 'branch_id' => $agent->branch_id, 'property_title' => $title,
            'sale_value' => mt_rand(90, 450) * 10_000, 'pipeline_stage_id' => $this->stages[$reached]->id,
            'listing_agent_id' => $agent->id, 'selling_agent_id' => $agent->id, 'buyer_name' => $lead->name,
            'status' => 'lost', 'lost_reason' => $reason, 'created_at' => $createdAt, 'updated_at' => $lostAt,
        ]);
        $this->stageHistory($deal, $createdAt, $durations->all(), $reached + 1, closeLast: true);
    }

    /** Writes sequential stage history rows for the first $count stages. */
    private function stageHistory(Deal $deal, Carbon $from, array $durations, int $count, bool $closeLast = false): void
    {
        $at = $from->copy();
        for ($s = 0; $s < $count; $s++) {
            $isLast = $s === $count - 1;
            $exit = $isLast && ! $closeLast ? null : $at->copy()->addDays($durations[$s] ?? 1);
            DealStageHistory::create(['deal_id' => $deal->id, 'pipeline_stage_id' => $this->stages[$s]->id, 'entered_at' => $at->copy(), 'exited_at' => $exit]);
            if ($exit) {
                $at = $exit;
            }
        }
    }

    /** Weighted agent pick; Johan only after his start date, Palesa/Thabo not yet selling. */
    private function pickAgent(Carbon $at, ?string $exclude = null): string
    {
        // Tuned so seeded tiers match the designs: Naledi/Sipho/Riaan senior, Lerato experienced, Johan junior.
        $weights = ['naledi' => 30, 'sipho' => 26, 'riaan' => 22, 'lerato' => 4];
        if ($at->gte(Carbon::parse('2026-09-02'))) {
            $weights['johan'] = 2;
        }
        unset($weights[$exclude]);

        return self::weighted($weights);
    }

    private function historyLead(Staff $agent, Carbon $createdAt): Lead
    {
        $source = self::weighted(['referral' => 34, 'website' => 28, 'whatsapp' => 18, 'walk_in' => 12, 'portal' => 8]);
        $lead = Lead::create([
            'name' => $this->firstName().' '.$this->surname(), 'phone' => '0'.mt_rand(60, 84).' '.mt_rand(100, 999).' '.mt_rand(1000, 9999),
            'interest_description' => mt_rand(2, 4).'-bed, '.$agent->branch->name, 'source' => $source,
            'assigned_agent_id' => $agent->id, 'status' => 'converted',
            'created_at' => $createdAt->copy()->subDays(mt_rand(1, 10)), 'updated_at' => $createdAt,
        ]);
        // Leads that never converted (~18% conversion overall).
        for ($k = 0; $k < 4; $k++) {
            Lead::create([
                'name' => $this->firstName().' '.$this->surname(), 'phone' => '0'.mt_rand(60, 84).' '.mt_rand(100, 999).' '.mt_rand(1000, 9999),
                'interest_description' => mt_rand(1, 4).'-bed, '.$agent->branch->name,
                'source' => self::weighted(['referral' => 30, 'website' => 30, 'whatsapp' => 18, 'walk_in' => 12, 'portal' => 10]),
                'assigned_agent_id' => $agent->id, 'status' => 'lost',
                'created_at' => $createdAt->copy()->subDays(mt_rand(0, 25)), 'updated_at' => $createdAt,
            ]);
        }

        return $lead;
    }

    private function listing(string $title, Staff $agent, int $price, string $status, Carbon $listedAt, ?string $suburb = null, ?int $beds = null, ?int $baths = null): Listing
    {
        return Listing::create([
            'title' => $title, 'suburb' => $suburb ?? $agent->branch->name, 'price' => $price, 'status' => $status,
            'property_type' => self::weighted(['house' => 41, 'sectional_title' => 33, 'townhouse' => 16, 'vacant_land' => 6, 'commercial' => 4]),
            'beds' => $beds ?? mt_rand(2, 5), 'baths' => $baths ?? mt_rand(1, 3), 'agent_id' => $agent->id, 'branch_id' => $agent->branch_id,
            'listed_at' => $listedAt->toDateString(), 'mandate_expiry' => $listedAt->copy()->addMonths(6)->toDateString(),
            'created_at' => $listedAt, 'updated_at' => $listedAt,
        ]);
    }

    // ---------------------------------------------------------------------------------------------
    /** The live pipeline exactly as drawn in sales/Pipeline, sales/Deals and the dashboards. */
    private function openDeals(): void
    {
        // title, selling, listing, stage, value, expected close, days in stage, suburb, beds, baths
        $deals = [
            ['14 Rivonia Views', 'naledi', 'naledi', 'Offer Accepted', 2_450_000, '2026-10-30', 1, 'Rivonia, Sandton', 3, 2],
            ['Unit 6, Oakfield Estate', 'sipho', 'sipho', 'Bond Approved', 1_890_000, '2026-11-12', 3, 'Oakfield, Midrand', 2, 1],
            ['22 Sandton Heights', 'riaan', 'naledi', 'Registration (Transfer)', 5_200_000, '2026-10-18', 14, 'Sandton CBD', 4, 3],
            ['8 Melrose Close', 'lerato', 'lerato', 'Viewing Scheduled', 990_000, '2026-12-05', 4, 'Melrose, Johannesburg', 2, 1],
            ['41 Bryanston Road', 'sipho', 'sipho', 'Offer Made', 3_400_000, '2026-11-22', 1, 'Bryanston', 4, 3],
            ['12 Umhlanga Ridge', 'riaan', 'riaan', 'FICA / Due Diligence', 4_100_000, '2026-11-08', 9, 'Umhlanga Ridge', 3, 2],
            ['19 Parkview Lane', 'naledi', 'naledi', 'Viewing Scheduled', 2_100_000, '2026-12-01', 5, 'Parkview', 3, 2],
            ['3 Fourways Gardens', 'johan', 'johan', 'Lead', 1_350_000, null, 2, 'Fourways', 3, 2],
            ['5 Bramley Gardens', 'naledi', 'naledi', 'Lead', 1_680_000, null, 6, 'Bramley', 3, 2],
        ];
        $stageIdx = $this->stages->pluck('name')->flip();

        foreach ($deals as [$title, $sell, $list, $stage, $value, $close, $days, $suburb, $beds, $baths]) {
            $idx = $stageIdx[$stage];
            $listedAt = now()->subDays(23 + $idx * 4);
            $status = $idx >= $stageIdx['Offer Made'] ? 'under_offer' : 'active';
            $listing = $this->listing($title, $this->staff[$list], $value, $status, $title === '14 Rivonia Views' ? Carbon::parse('2026-09-14') : $listedAt, $suburb, $beds, $baths);
            $createdAt = now()->subDays($days + $idx * 4);

            $deal = Deal::create([
                'listing_id' => $listing->id, 'branch_id' => $this->staff[$sell]->branch_id, 'property_title' => $title,
                'sale_value' => $value, 'pipeline_stage_id' => $this->stages[$idx]->id,
                'listing_agent_id' => $this->staff[$list]->id, 'selling_agent_id' => $this->staff[$sell]->id,
                'buyer_name' => $title === '14 Rivonia Views' ? 'Precious Nkosi' : $this->firstName().' '.$this->surname(),
                'seller_name' => $title === '14 Rivonia Views' ? 'M. & S. Abrahams' : $this->surname().' family',
                'seller_user_id' => $title === '14 Rivonia Views' ? $this->portalUsers['landlord']->id : null,
                'mandate_type' => 'sole', 'bond_required' => true, 'bond_ltv_percent' => 80,
                'expected_close_date' => $close, 'status' => 'open', 'created_at' => $createdAt, 'updated_at' => now()->subDays($days),
            ]);
            $durations = array_fill(0, $idx, 4);
            $this->stageHistory($deal, $createdAt, [...$durations, $days], $idx + 1);
        }

        // More active stock for the listings grid / inventory widget.
        foreach (['Craighall Park', 'Linden', 'Northcliff', 'Centurion', 'Midrand', 'Waterkloof', 'Hatfield', 'Bryanston', 'Fourways', 'Morningside'] as $i => $suburb) {
            $agent = $this->staff[['naledi', 'sipho', 'riaan', 'lerato', 'johan'][$i % 5]];
            $listedAt = now()->subDays(mt_rand(5, 120));
            $l = $this->listing(mt_rand(2, 90).' '.$suburb.' '.['Close', 'Gardens', 'Road', 'Estate', 'Lane'][$i % 5], $agent, mt_rand(90, 600) * 10_000, 'active', $listedAt, $suburb);
            if ($i < 2) {
                $l->update(['mandate_expiry' => now()->subDays(mt_rand(5, 60))]); // expired mandates
            }
        }

        $rivonia = Deal::where('property_title', '14 Rivonia Views')->first();
        foreach ([['Viewing completed with buyer', 5 * 24], ['Offer submitted — R2,450,000', 3 * 24], ['Offer to Purchase uploaded by Naledi Mokoena', 17.2], ['Offer accepted by seller', 2]] as [$text, $hoursAgo]) {
            DealActivityLog::create(['deal_id' => $rivonia->id, 'user_id' => $this->staff['naledi']->user_id, 'text' => $text, 'created_at' => now()->subMinutes((int) ($hoursAgo * 60))]);
        }
        DealActivityLog::create(['deal_id' => $rivonia->id, 'user_id' => $this->staff['naledi']->user_id, 'text' => 'Mandate renewed', 'created_at' => now()->subWeeks(2)]);
        DealActivityLog::create(['deal_id' => $rivonia->id, 'user_id' => $this->staff['naledi']->user_id, 'text' => 'Buyer FICA documents received', 'created_at' => now()->subDays(3)->subHours(2)]);
    }

    private function leads(): void
    {
        // name, phone, interest, source, agent, status, days ago
        $leads = [
            ['Precious Nkosi', '082 441 0098', '3-bed, Sandton', 'website', 'naledi', 'new', 2],
            ['David Botha', '071 220 3345', '2-bed apartment, Rivonia', 'referral', 'sipho', 'contacted', 4],
            ['Zanele Mthembu', '063 904 7721', 'Office space, Bryanston', 'whatsapp', 'riaan', 'qualified', 7],
            ['Michael Steyn', '084 550 1123', 'Smallholding, Midrand', 'walk_in', 'lerato', 'new', 7],
            ['Amahle Dube', '079 802 6610', '4-bed house, Fourways', 'website', 'naledi', 'converted', 14],
            ['Pieter Joubert', '082 117 9934', 'Retail unit, Centurion', 'referral', 'johan', 'lost', 21],
            ['Sizwe Ndlovu', '072 660 4410', '3-bed townhouse, Morningside', 'portal', 'naledi', 'new', 1],
            ['Karen Pillay', '083 301 7782', 'Apartment, Sandton CBD', 'website', 'naledi', 'new', 3],
        ];
        foreach ($leads as [$name, $phone, $interest, $source, $agent, $status, $days]) {
            Lead::create([
                'name' => $name, 'phone' => $phone, 'interest_description' => $interest, 'source' => $source,
                'assigned_agent_id' => $this->staff[$agent]->id, 'status' => $status,
                'created_at' => now()->subDays($days), 'updated_at' => now()->subDays($days),
            ]);
        }
    }

    /** Persist commissions via the real service, then mark historical ones paid. */
    private function commissions(): void
    {
        $service = app(CommissionService::class);
        Deal::where('status', '!=', 'lost')->with(['sellingAgent', 'listingAgent', 'commission'])->orderBy('closed_at')->each(function (Deal $deal) use ($service) {
            $c = $service->calculateForDeal($deal);
            if ($c && $deal->status === 'closed' && $deal->closed_at->copy()->addDays(5)->isPast()) {
                $c->update(['status' => 'paid', 'paid_at' => $deal->closed_at->copy()->addDays(5)]);
            }
        });
    }

    // ---------------------------------------------------------------------------------------------
    private function templates(): void
    {
        // name, used for, category, current version, last updated
        $templates = [
            ['Offer to Purchase', 'Sales deals', 'Offers to Purchase', 4, '2026-09-03'],
            ['Sole Mandate Agreement', 'New listings', 'Mandates', 2, '2026-08-18'],
            ['Open Mandate Agreement', 'New listings', 'Mandates', 2, '2026-08-18'],
            ['Residential Lease Agreement', 'Rentals', 'Lease Agreements', 6, '2026-10-01'],
            ['FICA Declaration Form', 'All clients', 'FICA / Compliance', 3, '2026-07-22'],
            ['Commission Split Agreement', 'Agent onboarding', 'Staff / HR', 1, '2026-01-05'],
            ['Property Condition Report', 'Rentals', 'Lease Agreements', 2, '2026-06-14'],
        ];
        foreach ($templates as [$name, $usedFor, $cat, $version, $updated]) {
            $t = DocumentTemplate::updateOrCreate(['name' => $name], ['used_for' => $usedFor, 'category_id' => $this->categories[$cat]]);
            for ($v = 1; $v <= $version; $v++) {
                $path = 'templates/demo/'.str($name)->slug().'-v'.$v.'.pdf';
                Storage::put($path, self::pdf($name.' (template v'.$v.')'));
                $date = Carbon::parse($updated)->subMonths(($version - $v) * 4);
                $ver = $t->versions()->create(['version_number' => $v, 'file_path' => $path, 'uploaded_by' => $this->staff['thandiwe']->user_id, 'created_at' => $date, 'updated_at' => $date]);
            }
            $t->update(['current_version_id' => $ver->id]);
        }
    }

    private function documents(): void
    {
        $deal = fn ($title) => Deal::where('property_title', $title)->first();
        $lead = fn ($name) => Lead::where('name', $name)->first();
        $tenancy = Tenancy::first();
        $melrose = Listing::where('title', '8 Melrose Close')->first();
        $by = $this->staff['naledi']->user_id;

        // name, category, linked model, version, created at
        $docs = [
            ['Signed OTP — 14 Rivonia Views.pdf', 'Offers to Purchase', $deal('14 Rivonia Views'), 1, now()->subHours(2)],
            ['Offer to Purchase — 14 Rivonia Views.pdf', 'Offers to Purchase', $deal('14 Rivonia Views'), 2, now()->subDay()],
            ['FICA Pack — P. Nkosi.pdf', 'FICA / Compliance', $lead('Precious Nkosi'), 1, now()->subDays(2)],
            ['Sole Mandate — 8 Melrose Close.pdf', 'Mandates', $melrose, 1, now()->subDays(4)],
            ['Lease — Unit 12 Oakfield.pdf', 'Lease Agreements', $tenancy, 3, now()->subWeek()],
            ['Commission Invoice — Oct 2026.pdf', 'Commission Invoices', $this->staff['riaan'], 1, now()->subWeek()],
            ['FICA Pack — D. Botha.pdf', 'FICA / Compliance', $lead('David Botha'), 1, now()->subWeeks(2)],
            ['Sole Mandate Agreement.pdf', 'Mandates', $deal('14 Rivonia Views'), 1, Carbon::parse('2026-09-14 10:00')],
            ['Residential Lease Agreement.pdf', 'Lease Agreements', $tenancy, 1, Carbon::parse('2026-04-01 09:00')],
            ['Property Condition Report.pdf', 'Lease Agreements', $tenancy, 1, Carbon::parse('2026-04-01 09:30')],
            ['Independent Contractor Agreement.pdf', 'Staff / HR', $this->staff['naledi'], 1, Carbon::parse('2021-03-03')],
            ['FFC Certificate 2026.pdf', 'Staff / HR', $this->staff['naledi'], 1, Carbon::parse('2026-01-10')],
            ['ID copy.pdf', 'Staff / HR', $this->staff['naledi'], 1, Carbon::parse('2021-03-03')],
            ['Signed commission split agreement.pdf', 'Staff / HR', $this->staff['naledi'], 1, Carbon::parse('2021-03-03')],
        ];
        foreach ($docs as [$name, $cat, $model, $version, $at]) {
            $this->document($name, $cat, $model, $version, $at, $by);
        }

        // Records approaching the 5-year retention deadline (docs/Retention).
        foreach ([['FICA Pack — J. Radebe', 'FICA / Compliance', '2026-11-02'], ['Sale file — 7 Benmore Gardens', 'Offers to Purchase', '2026-11-18'], ['FICA Pack — T. Sithole', 'FICA / Compliance', '2026-12-29']] as [$name, $cat, $expires]) {
            $this->document($name, $cat, null, 1, Carbon::parse($expires)->subYears(5), $by, Carbon::parse($expires));
        }

        // A paper trail for every historical sale (shared placeholder file).
        Deal::where('status', 'closed')->with('lead')->each(function (Deal $d) {
            $this->document('Offer to Purchase — '.$d->property_title.'.pdf', 'Offers to Purchase', $d, 1, $d->created_at->copy()->addDays(10), null, file: false);
            $this->document('FICA Pack — '.$d->buyer_name.'.pdf', 'FICA / Compliance', $d, 1, $d->created_at->copy()->addDays(12), null, file: false);
        });
    }

    private function document(string $name, string $category, $model, int $version, Carbon $at, ?int $by, ?Carbon $expires = null, bool $file = true): Document
    {
        $path = self::PLACEHOLDER;
        if ($file) {
            $path = 'documents/demo/'.str($name)->slug().'-v'.$version.'.pdf';
            Storage::put($path, self::pdf(pathinfo($name, PATHINFO_FILENAME)));
        }

        return Document::create([
            'name' => $name, 'category_id' => $this->categories[$category],
            'linked_model_type' => $model ? $model::class : null, 'linked_model_id' => $model?->id,
            'version' => $version, 'file_path' => $path, 'uploaded_by' => $by,
            'retention_expires_at' => $expires ?? $at->copy()->addYears(5), 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    private function uploadLinks(): void
    {
        $rivonia = Deal::where('property_title', '14 Rivonia Views')->first();
        $fica = [['Copy of ID document', 'Certified or clear photo'], ['Proof of address', 'Not older than 3 months'],
            ['Proof of funds / bond pre-approval', 'Bank statement or bond letter'], ['Signed Offer to Purchase', 'Sent to you separately for signature'],
            ['Marriage certificate / ANC', 'If applicable']];

        // client, agent, token, status, item count, deal, phone
        $links = [
            ['Precious Nkosi', 'naledi', 'a8x2kq', 'uploaded', 3, $rivonia, '082 441 0098'],
            ['David Botha', 'sipho', 'q91mtz', 'filed', 4, null, '071 220 3345'],
            ['Zanele Mthembu', 'riaan', 'df73lp', 'awaiting_client', 4, null, '063 904 7721'],
            ['R. Dube (tenant)', 'lerato', 'n02vck', 'filed', 5, null, '073 118 5520'],
            ['M. & S. Abrahams', 'naledi', 'rt58xw', 'expired', 2, $rivonia, '082 902 1145'],
        ];
        foreach ($links as [$client, $agent, $token, $status, $count, $deal, $phone]) {
            $link = ClientUploadLink::create([
                'token' => $token, 'agent_id' => $this->staff[$agent]->id, 'client_name' => $client, 'client_phone' => $phone,
                'deal_id' => $deal?->id, 'status' => $status,
                'expires_at' => $status === 'expired' ? now()->subDays(3) : now()->addDays(10), 'last_sent_at' => now()->subDays(4),
            ]);
            foreach (array_slice($fica, 0, $count) as $i => [$label, $hint]) {
                $itemStatus = match ($status) {
                    'filed' => 'filed', 'uploaded' => 'uploaded', default => 'required'
                };
                // On the open demo link the client has already sent the first two.
                if ($status === 'awaiting_client' && $i < 2) {
                    $itemStatus = 'uploaded';
                }
                $doc = $itemStatus === 'required' ? null : $this->document($label.' — '.$client.'.pdf', 'FICA / Compliance',
                    $status === 'filed' && $deal ? $deal : $link, 1, now()->subDays(3), null);
                $link->items()->create(['document_label' => $label, 'hint' => $hint, 'status' => $itemStatus, 'uploaded_document_id' => $doc?->id]);
            }
        }
    }

    // ---------------------------------------------------------------------------------------------
    private function hr(): void
    {
        $s = $this->staff;
        // staff, type, start, expiry, status
        foreach ([
            ['naledi', 'Independent Contractor', '2021-03-03', null, 'active'],
            ['sipho', 'Independent Contractor', '2019-07-18', null, 'active'],
            ['busisiwe', 'Permanent Employee', '2020-02-01', null, 'active'],
            ['kagiso', 'Permanent Employee', '2022-01-14', '2027-01-13', 'renewal_due'],
            ['johan', 'Independent Contractor — Probation', '2026-09-02', '2026-12-01', 'probation'],
            ['anita', 'Permanent Employee', '2018-06-09', null, 'active'],
            ['riaan', 'Independent Contractor', '2018-04-02', null, 'active'],
            ['lerato', 'Independent Contractor', '2022-05-16', null, 'active'],
        ] as [$key, $type, $start, $expiry, $status]) {
            $path = 'contracts/demo/'.$key.'.pdf';
            Storage::put($path, self::pdf($type.' — '.$s[$key]->user->name));
            Contract::create(['staff_id' => $s[$key]->id, 'type' => $type, 'start_date' => $start, 'expiry_date' => $expiry, 'status' => $status, 'file_path' => $path]);
        }

        // annual remaining, sick remaining, taken YTD
        $balances = ['naledi' => [12, 8, 9], 'sipho' => [15, 10, 6], 'busisiwe' => [9, 7, 12], 'kagiso' => [6, 9, 15],
            'johan' => [18, 10, 1], 'anita' => [11, 5, 10], 'riaan' => [14, 10, 4], 'lerato' => [13, 9, 6],
            'thandiwe' => [20, 10, 3], 'palesa' => [15, 10, 0], 'thabo' => [15, 10, 0]];
        foreach ($balances as $key => [$annual, $sick, $taken]) {
            LeaveBalance::create(['staff_id' => $s[$key]->id, 'year' => now()->year, 'annual_days' => $annual, 'sick_days' => $sick, 'taken_days' => $taken]);
        }
        foreach ([['kagiso', 'annual', '2026-10-20', '2026-10-24', 5], ['anita', 'sick', '2026-10-09', '2026-10-09', 1], ['thabo', 'annual', '2026-10-14', '2026-10-15', 2]] as [$key, $type, $from, $to, $days]) {
            LeaveRequest::create(['staff_id' => $s[$key]->id, 'type' => $type, 'start_date' => $from, 'end_date' => $to, 'days' => $days]);
        }
        LeaveRequest::create(['staff_id' => $s['naledi']->id, 'type' => 'annual', 'start_date' => '2026-07-06', 'end_date' => '2026-07-10', 'days' => 5,
            'status' => 'approved', 'approved_by' => $s['thandiwe']->user_id, 'decided_at' => '2026-06-20']);

        foreach ([['johan', 4], ['palesa', 2], ['thabo', 1]] as [$key, $done]) {
            $c = OnboardingChecklist::create(['staff_id' => $s[$key]->id, 'start_date' => $s[$key]->start_date, 'role_title' => $s[$key]->role_title]);
            foreach (OnboardingChecklist::DEFAULT_TASKS as $i => $label) {
                $c->tasks()->create(['label' => $label, 'order' => $i, 'is_done' => $i < $done]);
            }
        }

        // agent, cpd points
        foreach (['naledi' => 10, 'sipho' => 8, 'riaan' => 5, 'lerato' => 10, 'johan' => 0, 'palesa' => 0] as $key => $cpd) {
            Certification::create(['staff_id' => $s[$key]->id, 'ffc_number' => $s[$key]->ffc_number, 'ffc_expiry' => $s[$key]->ffc_expiry, 'cpd_points' => $cpd]);
        }
    }

    private function training(): void
    {
        $courses = [
            ['Compliance', 'FICA & POPIA for Estate Agents', [['Introduction to FICA', 12], ['Client due-diligence requirements', 15], ['POPIA and data handling', 14], ['Record keeping & 5-year retention', 10], ['Reporting suspicious transactions', 9], ['Assessment quiz', 20]]],
            ['Sales Skills', 'Mastering the Offer to Purchase', [['Anatomy of an OTP', 10], ['Suspensive conditions', 12], ['Common drafting mistakes', 11], ['Presenting offers to sellers', 12]]],
            ['Onboarding', 'New Agent Induction', [['Welcome to Morena Properties', 10], ['Our brand & service standards', 15], ['The PPRA and your FFC', 15], ['Mandates explained', 15], ['Using the CRM', 20], ['Commission & payouts', 15], ['Compliance basics', 15], ['Your first 90 days', 15]]],
            ['Systems', 'Using the CRM Pipeline', [['Navigating your dashboard', 8], ['Leads and deals', 12], ['Moving deals through stages', 10], ['Documents and upload links', 12], ['Commission statements', 8]]],
            ['Compliance', 'PPRA Code of Conduct Refresher', [['Duties to clients', 14], ['Advertising rules', 12], ['Trust accounts', 14]]],
            ['Sales Skills', 'Negotiation & Closing Techniques', [['Preparing to negotiate', 10], ['Anchoring and concessions', 10], ['Handling objections', 10], ['Multiple-offer situations', 10], ['Closing with confidence', 10], ['After the deal', 11], ['Role-play assessment', 11]]],
        ];
        // course index => [agent => lessons completed]  (all lessons = complete)
        $progress = [
            0 => ['naledi' => 6, 'sipho' => 6, 'riaan' => 3, 'lerato' => 6, 'palesa' => 1],
            1 => ['naledi' => 4, 'sipho' => 4, 'riaan' => 4, 'lerato' => 2],
            2 => ['johan' => 8, 'palesa' => 3],
            3 => ['naledi' => 5, 'sipho' => 5, 'riaan' => 5, 'lerato' => 5, 'johan' => 5],
            4 => ['naledi' => 1, 'sipho' => 3, 'lerato' => 3],
            5 => ['sipho' => 7, 'riaan' => 7, 'lerato' => 2],
        ];

        foreach ($courses as $ci => [$category, $title, $lessons]) {
            $course = Course::create(['title' => $title, 'category' => $category, 'duration_minutes' => array_sum(array_column($lessons, 1))]);
            $lessonModels = collect($lessons)->map(fn ($l, $i) => $course->lessons()->create(['title' => $l[0], 'duration_minutes' => $l[1], 'order' => $i]));

            foreach ($progress[$ci] ?? [] as $key => $done) {
                $staffId = $this->staff[$key]->id;
                $completedAt = $key === 'naledi' && $ci === 0 ? Carbon::parse('2026-09-02') : now()->subDays(mt_rand(5, 200));
                foreach ($lessonModels->take($done) as $lesson) {
                    LessonCompletion::create(['lesson_id' => $lesson->id, 'staff_id' => $staffId, 'completed_at' => $completedAt]);
                }
                $complete = $done >= $lessonModels->count();
                CourseCompletion::create(['course_id' => $course->id, 'staff_id' => $staffId,
                    'status' => $complete ? 'complete' : 'in_progress', 'completed_at' => $complete ? $completedAt : null]);
            }
        }
    }

    // ---------------------------------------------------------------------------------------------
    private static function weighted(array $weights): string
    {
        $r = mt_rand(1, array_sum($weights));
        foreach ($weights as $key => $w) {
            if (($r -= $w) <= 0) {
                return $key;
            }
        }

        return array_key_first($weights);
    }

    private function firstName(): string
    {
        $names = ['Thabo', 'Lindiwe', 'Johan', 'Ayesha', 'Sibusiso', 'Megan', 'Kabelo', 'Nomvula', 'Pieter', 'Fatima', 'Lwazi', 'Chantel', 'Bongani', 'Priya', 'Andile', 'Liesl', 'Mpho', 'Ruan'];

        return $names[array_rand($names)];
    }

    private function surname(): string
    {
        $names = ['Radebe', 'Botha', 'Naidoo', 'Mahlaba', 'van Wyk', 'Sithole', 'Pillay', 'Ndlovu', 'Venter', 'Mokoena', 'Daniels', 'Zulu', 'Coetzee', 'Moosa', 'Khoza', 'Smit'];

        return $names[array_rand($names)];
    }

    /** Minimal one-page PDF so seeded downloads open in a viewer. */
    public static function pdf(string $title): string
    {
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], preg_replace('/[^\x20-\x7E]/', '-', $title));
        $stream = "BT /F1 20 Tf 72 760 Td ({$text}) Tj 0 -28 Td /F1 11 Tf (Morena Properties - sample file generated by DemoSeeder) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$obj}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref
0 '.(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $pdf .= sprintf("%010d 00000 n \n", $o);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
