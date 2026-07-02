<?php

namespace Database\Seeders;

use App\Enums\AttributionSource;
use App\Models\Company;
use App\Models\CustomerReferral;
use App\Models\Referrer;
use App\Services\ReferralCodeGenerator;
use Illuminate\Database\Seeder;

/**
 * LOCAL TESTING ONLY — not registered in DatabaseSeeder. Run with:
 *   php artisan db:seed --class=ReferralAttributionSeeder
 *
 * Ensures the first referrer has a code, logs a couple of clicks against
 * it, and attributes the first un-attributed customer so the ledger +
 * portal stats have something to render. Idempotent-ish (guards on
 * existing attribution).
 */
class ReferralAttributionSeeder extends Seeder
{
    public function run(): void
    {
        $referrer = Referrer::first();
        if ($referrer === null) {
            $this->command?->warn('ReferralAttributionSeeder: no referrers — skipping.');

            return;
        }

        if (empty($referrer->referral_code)) {
            $referrer->update(['referral_code' => app(ReferralCodeGenerator::class)->generate()]);
        }

        $click = $referrer->clicks()->create([
            'referral_code' => $referrer->referral_code,
            'product' => 'maavelus',
            'campaign' => 'demo',
            'utm_source' => 'newsletter',
            'landing_url' => 'https://maavelus.com/?ref='.$referrer->referral_code,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'SeederAgent/1.0',
        ]);

        $customer = Company::whereDoesntHave('referral')->first();
        if ($customer !== null) {
            CustomerReferral::create([
                'customer_id' => $customer->id,
                'referrer_id' => $referrer->id,
                'click_id' => $click->id,
                'product' => 'maavelus',
                'source' => AttributionSource::Cookie,
                'campaign' => 'demo',
                'attributed_at' => now(),
                'converted_at' => now(),
            ]);
            $this->command?->info("ReferralAttributionSeeder: attributed '{$customer->name}' to referrer #{$referrer->id}.");
        }
    }
}
