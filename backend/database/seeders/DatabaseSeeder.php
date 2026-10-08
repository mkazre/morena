<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);

        // Sample agency data mirroring the UI designs — never in production.
        if (! app()->isProduction()) {
            $this->call(DemoSeeder::class);
        }
    }
}
