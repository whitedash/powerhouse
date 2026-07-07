<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\ProductPlanPrice;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Checkout\Session;
use Tests\TestCase;

/**
 * LIVE-API smoke test: creates a REAL Checkout session against Stripe's
 * test mode. Exists because the font_family bug (raw CSS stack sent to an
 * enum field) shipped past every unit test — nothing exercised the actual
 * API surface, so branding_settings params that documentation-checking
 * had "verified" still 500'd every purchase in practice.
 *
 * Gated: runs ONLY when a test-mode secret (sk_test_) is configured —
 * skipped keyless and NEVER run against a live key. phpunit.xml does not
 * override STRIPE_*, so locally this inherits .env's test keys and runs
 * as part of the normal suite; a keyless CI simply skips it.
 */
class PlanCheckoutLiveStripeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! str_starts_with((string) config('services.stripe.secret'), 'sk_test_')) {
            $this->markTestSkipped('No Stripe TEST-mode key configured — live-API smoke skipped.');
        }
    }

    public function test_default_theme_tokens_create_a_real_checkout_session(): void
    {
        // UNTHEMED product = the default token set — the exact shape that
        // shipped broken. If Stripe rejects any params field (enum drift
        // on a future API bump included), this fails loudly.
        $product = Product::create(['slug' => 'live-smoke', 'name' => 'Live Smoke', 'is_active' => true]);
        $plan = ProductPlan::create(['product_id' => $product->id, 'name' => 'Smoke', 'is_active' => true, 'is_public' => true]);
        $price = ProductPlanPrice::create(['plan_id' => $plan->id, 'price' => 1.00, 'interval_count' => 1, 'interval_unit' => 'one_time', 'is_active' => true]);

        $session = app(StripeService::class)->createPlanCheckoutSession(
            $price, 'Live Smoke', 'live-smoke@wd.test', 1.20, 'live-smoke',
        );

        $this->assertInstanceOf(Session::class, $session);
        $this->assertStringStartsWith('cs_test_', (string) $session->id);
        $this->assertNotSame('', (string) $session->client_secret);
    }
}
