<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ProductSeeder::class,
            UserSeeder::class,
            // Roles/permissions/scopes + user backfill. After UserSeeder so
            // the backfill can mirror existing users' role enum. INERT in
            // phase 1 — populated but read by no enforcement.
            RolesAndPermissionsSeeder::class,
            BillingEntitySeeder::class,
            SettingsSeeder::class,
        ]);
    }
}
