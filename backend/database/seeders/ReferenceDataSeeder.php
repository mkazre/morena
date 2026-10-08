<?php

namespace Database\Seeders;

use App\Models\CommissionSetting;
use App\Models\CommissionTier;
use App\Models\DocumentCategory;
use App\Models\DocumentTemplate;
use App\Models\PipelineStage;
use App\Models\Setting;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Data every installation needs (safe to run in production; idempotent). */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['admin', 'branch_manager', 'hr', 'finance', 'agent', 'landlord', 'tenant'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        Setting::current();
        CommissionSetting::current();

        if (! CommissionTier::exists()) {
            // SA market norms (CLAUDE.md): junior 20–40%, experienced 40–60%, senior 60–70%+.
            CommissionTier::insert([
                ['name' => 'Junior agent', 'min_deals' => null, 'min_volume' => null, 'agent_split_percent' => 40, 'agency_split_percent' => 60, 'position' => 0, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Experienced agent', 'min_deals' => 5, 'min_volume' => null, 'agent_split_percent' => 55, 'agency_split_percent' => 45, 'position' => 1, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Senior / top agent', 'min_deals' => 10, 'min_volume' => 10_000_000, 'agent_split_percent' => 70, 'agency_split_percent' => 30, 'position' => 2, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        // [name, retention group (docs/Retention), clock starts from]
        $categories = [
            ['Offers to Purchase', 'Sale transaction records', 'registration'],
            ['Lease Agreements', 'Rental transaction records', 'lease end'],
            ['FICA / Compliance', 'FICA / client due diligence', 'relationship end'],
            ['Mandates', 'Mandates', 'expiry'],
            ['Commission Invoices', 'Sale transaction records', 'registration'],
            ['Staff / HR', 'Staff / HR records', 'end of employment'],
        ];
        foreach ($categories as [$name, $group, $basis]) {
            DocumentCategory::firstOrCreate(['name' => $name], ['retention_years' => 5, 'retention_group' => $group, 'retention_basis' => $basis]);
        }

        if (! PipelineStage::exists()) {
            foreach (PipelineStage::DEFAULTS as $pos => [$name, $color, $docs]) {
                $stage = PipelineStage::create(['name' => $name, 'position' => $pos, 'color_hex' => $color, 'is_default_seed' => true]);
                $stage->documentTemplates()->sync(collect($docs)->map(
                    fn ($d) => DocumentTemplate::firstOrCreate(['name' => $d], ['used_for' => 'Sales deals'])->id
                ));
            }
        }

        $this->call(ChartOfAccountsSeeder::class);
    }
}
