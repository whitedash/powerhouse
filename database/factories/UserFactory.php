<?php

namespace Database\Factories;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * Phase 3a bridge: enforcement now resolves via Spatie permissions, so an
     * internal user must hold the Spatie role matching its enum (exactly as
     * the phase-1 seeder backfills REAL users). This mirrors that for
     * factory-created (test/dev) users, keeping permission-based enforcement
     * access-identical. referrer users hold no Spatie role (design §5.1).
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            if (! in_array($user->role, ['super_admin', 'staff'], true)) {
                return;
            }

            // Seed the roles/permissions lazily if the table is empty (e.g. a
            // RefreshDatabase test that didn't run the seeder explicitly).
            if (! Permission::query()->where('guard_name', 'web')->exists()) {
                app(RolesAndPermissionsSeeder::class)->run();
            }

            $user->assignRole($user->role);
        });
    }

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => 'staff',
            'avatar_colour' => fake()->randomElement(['#F59E0B', '#10B981', '#3B82F6', '#7C3AED']),
            'remember_token' => Str::random(10),
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(fn () => ['role' => 'super_admin']);
    }

    public function referrer(): static
    {
        return $this->state(fn () => ['role' => 'referrer']);
    }
}
