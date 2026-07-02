<?php

namespace Database\Seeders;

use App\Models\CommissionLedger;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\CustomerReferral;
use App\Models\Product;
use App\Models\Referrer;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * A fully-populated referrer/partner account with KNOWN credentials so the
 * partner portal can be logged into and visually audited end-to-end.
 *
 *   email:    referrer.demo@whitedash.test
 *   password: password
 *
 * Idempotent: re-running refreshes this one referrer's demo data without
 * touching real referrers. NOT wired into DatabaseSeeder — run explicitly:
 *   php artisan db:seed --class=ReferrerAuditSeeder
 */
class ReferrerAuditSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'referrer.demo@whitedash.test'],
            [
                'name' => 'Jordan Mercer',
                'password' => Hash::make('password'),
                'role' => 'referrer',
            ],
        );

        $referrer = Referrer::updateOrCreate(
            ['user_id' => $user->id],
            [
                'referral_code' => 'DEMO2026',
                'is_active' => true,
                'payment_details' => [
                    'bank_name' => 'Monzo Bank',
                    'account_name' => 'Jordan Mercer',
                    'sort_code' => '04-00-04',
                    'account_number' => '12345678',
                ],
            ],
        );

        // Lifetime click history (drives the dashboard click counter).
        if ($referrer->clicks()->count() < 14) {
            for ($i = 0; $i < 14; $i++) {
                $referrer->clicks()->create([
                    'referral_code' => $referrer->referral_code,
                    'product' => 'maavelus',
                    'campaign' => 'spring-demo',
                    'utm_source' => 'newsletter',
                    'landing_url' => 'https://maavelus.com/?ref='.$referrer->referral_code,
                    'ip_address' => '127.0.0.1',
                    'user_agent' => 'SeederAgent/1.0',
                ]);
            }
        }

        $products = Product::orderBy('id')->take(4)->get();
        $productId = fn (int $i) => $products[$i % max($products->count(), 1)]->id ?? null;

        // Ledger rows require a rule_id (NOT NULL). One flat 10% rule covers
        // every demo entry.
        $rule = CommissionRule::updateOrCreate(
            ['referrer_id' => $referrer->id, 'product_id' => $products->first()?->id],
            [
                'type' => 'one_off_pct',
                'config' => ['rate' => 10],
                'is_active' => true,
                'valid_from' => now()->subYear(),
            ],
        );

        $customerSpecs = [
            ['name' => 'The Copper Kettle', 'type' => 'cafe', 'city' => 'Bristol'],
            ['name' => 'Northfield Brasserie', 'type' => 'restaurant', 'city' => 'Leeds'],
            ['name' => 'Harbour Lights Bar', 'type' => 'bar', 'city' => 'Brighton'],
            ['name' => 'Stonebaked Bakehouse', 'type' => 'bakery', 'city' => 'Manchester'],
        ];

        // Reset this referrer's demo ledger so re-runs stay deterministic.
        CommissionLedger::where('referrer_id', $referrer->id)->delete();

        foreach ($customerSpecs as $idx => $spec) {
            $customer = Company::updateOrCreate(
                ['name' => $spec['name']],
                [
                    'type' => $spec['type'],
                    'city' => $spec['city'],
                    'country' => 'GB',
                    'pipeline_stage' => 'active',
                ],
            );

            CustomerReferral::updateOrCreate(
                ['customer_id' => $customer->id, 'referrer_id' => $referrer->id],
                [
                    'product' => 'maavelus',
                    'source' => 'cookie',
                    'campaign' => 'spring-demo',
                    'attributed_at' => now()->subMonths($idx + 1),
                    'converted_at' => now()->subMonths($idx + 1)->addDays(3),
                ],
            );

            // A spread of commission rows per customer across all statuses
            // so totals, badges and the table all show real content.
            $rows = [
                ['status' => 'paid',     'trigger_type' => 'onboarding',        'gross' => 480, 'rate' => 0.15, 'monthsAgo' => $idx + 4],
                ['status' => 'approved', 'trigger_type' => 'monthly_recurring', 'gross' => 220, 'rate' => 0.10, 'monthsAgo' => $idx + 1],
                ['status' => 'pending',  'trigger_type' => 'invoice_paid',      'gross' => 360, 'rate' => 0.10, 'monthsAgo' => 0],
            ];

            foreach ($rows as $j => $row) {
                $periodStart = now()->subMonths($row['monthsAgo'])->startOfMonth();
                $commission = round($row['gross'] * $row['rate'], 2);

                CommissionLedger::create([
                    'referrer_id' => $referrer->id,
                    'customer_id' => $customer->id,
                    'rule_id' => $rule->id,
                    'product_id' => $productId($idx + $j),
                    'trigger_type' => $row['trigger_type'],
                    'gross_amount' => $row['gross'],
                    'commission_amount' => $commission,
                    'status' => $row['status'],
                    'period_start' => $periodStart,
                    'period_end' => $periodStart->copy()->endOfMonth(),
                    'approved_at' => in_array($row['status'], ['approved', 'paid'], true)
                        ? $periodStart->copy()->addDays(5)
                        : null,
                    'paid_at' => $row['status'] === 'paid'
                        ? Carbon::create(now()->year, max(1, now()->month - $idx), 12)
                        : null,
                ]);
            }
        }

        $this->command?->info('ReferrerAuditSeeder: referrer.demo@whitedash.test / password ready (referrer #'.$referrer->id.').');
    }
}
