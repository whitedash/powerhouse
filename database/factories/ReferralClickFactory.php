<?php

namespace Database\Factories;

use App\Enums\ProductKey;
use App\Models\ReferralClick;
use App\Models\Referrer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReferralClick>
 */
class ReferralClickFactory extends Factory
{
    protected $model = ReferralClick::class;

    public function definition(): array
    {
        return [
            'referrer_id' => Referrer::factory(),
            'referral_code' => strtoupper(fake()->bothify('????####')),
            'product' => fake()->randomElement(ProductKey::values()),
            'campaign' => fake()->optional()->slug(2),
            'utm_source' => fake()->optional()->randomElement(['google', 'facebook', 'newsletter']),
            'utm_medium' => fake()->optional()->randomElement(['cpc', 'social', 'email']),
            'utm_campaign' => fake()->optional()->slug(2),
            'landing_url' => fake()->url(),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ];
    }
}
