<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

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

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            [
                'email' => ['required', 'email'],
                'password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()->symbols()],
            ],
        );

        if ($validator->fails()) {
            $this->command->error('UserSeeder: SUPER_ADMIN_PASSWORD does not meet policy (min 12 chars, mixed case, number, symbol).');
            foreach ($validator->errors()->all() as $msg) {
                $this->command->error("  - {$msg}");
            }

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
