<?php

namespace Tests\Feature;

use App\Http\Controllers\Webhooks\StripeWebhookController;
use App\Mail\PlanCheckoutAbandonedStaff;
use App\Models\BillingEntity;
use App\Models\PlanCheckoutAttempt;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\ProductPlanPrice;
use App\Models\User;
use App\Services\StripeService;
use App\Services\WebhookIdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Stripe\Event;
use Tests\TestCase;

/**
 * Abandoned-checkout tracking: the plan_checkout_attempts lifecycle
 * (pending at init → completed on webhook settle / abandoned by the
 * reconciler after the 24h window = Stripe's own session expiry), and
 * the staff alert firing exactly once per attempt across repeated
 * reconciler runs. The tracking table is the ONLY checkout-init write —
 * Company/Contact/Person/Invoice stay webhook-only (pinned in
 * PlanWidgetPurchaseTest's checkout test).
 */
class PlanAbandonedCheckoutTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: ProductPlanPrice} */
    private function catalog(): array
    {
        BillingEntity::create([
            'name' => 'WD', 'legal_name' => 'Whitedash Ltd',
            'postmark_sender_email' => 'b@wd.test', 'postmark_sender_name' => 'WD',
            'is_active' => true, 'vat_registered' => true, 'default_vat_rate' => 20,
        ]);
        $product = Product::create(['slug' => 'comnicube', 'name' => 'ComniCube', 'is_active' => true]);
        $plan = ProductPlan::create([
            'product_id' => $product->id, 'name' => 'Starter',
            'is_active' => true, 'is_public' => true,
        ]);
        $price = ProductPlanPrice::create([
            'plan_id' => $plan->id, 'price' => 100,
            'interval_count' => 1, 'interval_unit' => 'one_time', 'is_active' => true,
        ]);

        return [$price];
    }

    private function attempt(ProductPlanPrice $price, string $sessionId, ?int $ageHours = null): PlanCheckoutAttempt
    {
        return PlanCheckoutAttempt::create([
            'plan_price_id' => $price->id,
            'purchaser_name' => 'Pat Purchaser',
            'purchaser_email' => 'pat.purchaser@gmail.com',
            'stripe_checkout_session_id' => $sessionId,
            'status' => 'pending',
            'started_at' => $ageHours !== null ? now()->subHours($ageHours) : now(),
        ]);
    }

    private function fireCheckoutCompleted(ProductPlanPrice $price, string $sessionId): void
    {
        $event = Event::constructFrom([
            'id' => 'evt_'.uniqid(),
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => $sessionId,
                'payment_intent' => 'pi_'.$sessionId,
                'amount_total' => 12000,
                'metadata' => [
                    'plan_price_id' => (string) $price->id,
                    'purchaser_name' => 'Pat Purchaser',
                    'purchaser_email' => 'pat.purchaser@gmail.com',
                ],
            ]],
        ]);
        $request = Request::create('/webhooks/stripe', 'POST');
        $request->attributes->set('stripeEvent', $event);
        app(StripeWebhookController::class)->receive(
            $request, app(StripeService::class), app(WebhookIdempotencyService::class),
        );
    }

    public function test_webhook_settlement_marks_the_attempt_completed(): void
    {
        Mail::fake();
        [$price] = $this->catalog();
        $attempt = $this->attempt($price, 'cs_settles');

        $this->fireCheckoutCompleted($price, 'cs_settles');

        $attempt->refresh();
        $this->assertSame('completed', $attempt->status);
        $this->assertNotNull($attempt->completed_at);
    }

    public function test_reconciler_abandons_only_stale_pending_attempts(): void
    {
        Mail::fake();
        User::factory()->create(['role' => 'super_admin', 'email' => 'admin@wd.test']);
        [$price] = $this->catalog();

        $stale = $this->attempt($price, 'cs_stale', ageHours: 25);
        $fresh = $this->attempt($price, 'cs_fresh', ageHours: 2);
        $done = $this->attempt($price, 'cs_done', ageHours: 30);
        $done->update(['status' => 'completed', 'completed_at' => now()]);

        $this->artisan('plans:reconcile-abandoned-checkouts')->assertSuccessful();

        $this->assertSame('abandoned', $stale->fresh()->status);
        $this->assertNotNull($stale->fresh()->abandoned_at);
        // Inside the 24h window (session still payable) — untouched.
        $this->assertSame('pending', $fresh->fresh()->status);
        // Completed attempts are never re-marked.
        $this->assertSame('completed', $done->fresh()->status);

        Mail::assertSent(PlanCheckoutAbandonedStaff::class, 1);
        Mail::assertSent(PlanCheckoutAbandonedStaff::class, fn (PlanCheckoutAbandonedStaff $mail): bool => $mail->hasTo('admin@wd.test')
            && $mail->attempt->is($stale));
    }

    public function test_staff_alert_fires_exactly_once_across_repeated_runs(): void
    {
        Mail::fake();
        User::factory()->create(['role' => 'super_admin', 'email' => 'admin@wd.test']);
        [$price] = $this->catalog();
        $this->attempt($price, 'cs_stale', ageHours: 26);

        $this->artisan('plans:reconcile-abandoned-checkouts')->assertSuccessful();
        $this->artisan('plans:reconcile-abandoned-checkouts')->assertSuccessful();
        $this->artisan('plans:reconcile-abandoned-checkouts')->assertSuccessful();

        Mail::assertSent(PlanCheckoutAbandonedStaff::class, 1);
    }

    public function test_late_settlement_overrides_an_abandoned_mark(): void
    {
        Mail::fake();
        User::factory()->create(['role' => 'super_admin', 'email' => 'admin@wd.test']);
        [$price] = $this->catalog();
        $attempt = $this->attempt($price, 'cs_late', ageHours: 25);

        $this->artisan('plans:reconcile-abandoned-checkouts')->assertSuccessful();
        $this->assertSame('abandoned', $attempt->fresh()->status);

        // The visitor pays within Stripe's session lifetime after the
        // sweep already flagged it — the truth wins.
        $this->fireCheckoutCompleted($price, 'cs_late');
        $this->assertSame('completed', $attempt->fresh()->status);
    }

    public function test_abandoned_list_shows_flagged_attempts(): void
    {
        Mail::fake();
        User::factory()->create(['role' => 'super_admin', 'email' => 'admin@wd.test']);
        [$price] = $this->catalog();
        $this->attempt($price, 'cs_stale', ageHours: 25);
        $this->attempt($price, 'cs_fresh', ageHours: 1);
        $this->artisan('plans:reconcile-abandoned-checkouts')->assertSuccessful();

        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)
            ->get('/settings/abandoned-checkouts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('attempts.total', 1)
                ->where('attempts.data.0.purchaser_email', 'pat.purchaser@gmail.com')
                ->where('attempts.data.0.plan', 'Starter'));
    }
}
