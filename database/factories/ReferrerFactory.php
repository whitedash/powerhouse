<?php

namespace Database\Factories;

use App\Models\Referrer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Referrer>
 */
class ReferrerFactory extends Factory
{
    protected $model = Referrer::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['role' => 'referrer']),
            'referral_code' => strtoupper(fake()->bothify('????####')),
            'is_active' => true,
        ];
    }
}
