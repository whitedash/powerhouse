<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

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
