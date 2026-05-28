<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('SUPER_ADMIN_EMAIL');
        $password = env('SUPER_ADMIN_PASSWORD');

        if (! $email || ! $password) {
            $this->command->warn('Skipping UserSeeder — SUPER_ADMIN_EMAIL and SUPER_ADMIN_PASSWORD must both be set in .env');

            return;
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Super Admin',
                'password' => $password,
                'role' => 'super_admin',
                'avatar_colour' => '#F59E0B',
            ],
        );
    }
}
