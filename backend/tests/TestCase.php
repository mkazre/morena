<?php

namespace Tests;

use App\Models\Branch;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Roles, default pipeline, commission rules and document categories. */
    protected function seedReference(): void
    {
        $this->seed(ReferenceDataSeeder::class);
    }

    protected function makeUser(string $role, ?Branch $branch = null, array $attrs = []): User
    {
        $user = User::factory()->create(['branch_id' => $branch?->id, 'status' => 'active', ...$attrs]);
        $user->assignRole($role);

        return $user;
    }

    protected function makeAgent(?Branch $branch = null, string $name = 'Agent'): Staff
    {
        $branch ??= Branch::firstOrCreate(['name' => 'Sandton']);
        $user = $this->makeUser('agent', $branch, ['name' => $name]);

        return $user->staff()->create(['role_title' => 'Sales Agent', 'department' => 'sales', 'branch_id' => $branch->id]);
    }
}
