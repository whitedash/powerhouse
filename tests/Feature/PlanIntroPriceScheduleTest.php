<?php

namespace Tests\Feature;

use App\Http\Controllers\Webhooks\StripeWebhookController;
use App\Models\BillingEntity;
use App\Models\CustomerProduct;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\ProductPlanPrice;
use App\Models\User;
use App\Services\StripeService;
use App\Services\WebhookIdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Stripe\Event;
use Tests\TestCase;

/**
 * Plans widget "intro price then full price" (feat/plans-intro-price-schedule).
 * An intro price (often £0) is charged now and the subscription swaps to the
 * full price after N days via plans:apply-intro-price-swaps, after which the
 * EXISTING invoices:generate-subscriptions sweep bills the full price with zero
 * changes. The swap-then-sweep seam is the highest-risk part and is covered
 * end-to-end here, with the same rigor as the setup-fee branch's sweep test.
 */
class PlanIntroPriceScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Fixed, mid-month "now" so the +14-day / +1-month arithmetic is
        // deterministic (no month-boundary or DST flakiness).
        Carbon::setTestNow(Carbon::parse('2026-08-03 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function entity(): BillingEntity
    {
        return BillingEntity::create([
            'name' => 'WD', 'legal_name' => 'Whitedash Ltd',
            'postmark_sender_email' => 'b@wd.test', 'postmark_sender_name' => 'WD',
            'is_active' => true, 'vat_registered' => true, 'default_vat_rate' => 20,
        ]);
    }

    /**
     * Product + public plan with a full recurring price (£49/month) and a £0
     * intro price that swaps to the full price after $introDays days.
     *
     * @return array{0: Product, 1: ProductPlan, 2: ProductPlanPrice, 3: ProductPlanPrice}
     */
    private function introCatalog(float $introPrice = 0.00, int $introDays = 14): array
    {
        $this->entity();

        // 'comnicube' is the non-externally-provisioned product the plan-widget
        // suite uses — provisioning stays pure-DB (no live MyOrderPad API call).
        $product = Product::create(['slug' => 'comnicube', 'name' => 'ComniCube', 'is_active' => true]);
        $plan = ProductPlan::create([
            'product_id' => $product->id, 'name' => 'Pro', 'description' => 'The pro plan',
            'features' => ['Everything'], 'is_active' => true, 'is_public' => true,
        ]);

        $full = ProductPlanPrice::create([
            'plan_id' => $plan->id, 'price' => 49.00,
            'interval_count' => 1, 'interval_unit' => 'month',
            'is_active' => true, 'is_default' => false, 'label' => 'Pro monthly',
        ]);
        $intro = ProductPlanPrice::create([
            'plan_id' => $plan->id, 'price' => $introPrice,
            'interval_count' => 1, 'interval_unit' => 'month',
            'is_active' => true, 'is_default' => true, 'label' => 'Free trial',
            'intro_swap_price_id' => $full->id,
            'intro_duration_days' => $introDays,
        ]);

        return [$product, $plan, $intro, $full];
    }

    private function fireIntroPurchase(ProductPlanPrice $price, string $sessionId, int $amountTotalPence): void
    {
        Mail::fake();
        $event = Event::constructFrom([
            'id' => 'evt_'.$sessionId,
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => $sessionId,
                'payment_intent' => 'pi_'.$sessionId,
                'amount_total' => $amountTotalPence,
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
            $request,
            app(StripeService::class),
            app(WebhookIdempotencyService::class),
        );
    }

    public function test_intro_purchase_provisions_with_the_swap_date_computed_from_the_day_count(): void
    {
        [, , $intro, $full] = $this->introCatalog(introPrice: 0.00, introDays: 14);

        $this->fireIntroPurchase($intro, 'cs_intro', amountTotalPence: 0);

        $cp = CustomerProduct::sole();
        // Provisioned ON the intro price, recurring-enabled, with the swap
        // scheduled exactly $introDays out — the same date drives billing.
        $this->assertSame($intro->id, $cp->plan_price_id, 'starts on the intro price');
        $this->assertTrue((bool) $cp->auto_invoice, 'auto_invoice on even for a £0 intro (card is vaulted)');
        $this->assertSame(now()->addDays(14)->toDateString(), $cp->intro_swap_at->toDateString());
        $this->assertSame(now()->addDays(14)->toDateString(), $cp->next_billing_date->toDateString());
        $this->assertSame($full->id, $cp->intro_swap_price_id, 'target full price snapshotted');
        $this->assertSame('active', $cp->status);
    }

    public function test_the_swap_job_does_not_fire_before_the_swap_date(): void
    {
        [, , $intro, $full] = $this->introCatalog(introDays: 14);
        $this->fireIntroPurchase($intro, 'cs_intro', amountTotalPence: 0);

        // Day 0..13: nothing due. Run at purchase time and again at day 13.
        $this->artisan('plans:apply-intro-price-swaps')->assertExitCode(0);
        Carbon::setTestNow(now()->addDays(13));
        $this->artisan('plans:apply-intro-price-swaps')->assertExitCode(0);

        $cp = CustomerProduct::sole();
        $this->assertSame($intro->id, $cp->plan_price_id, 'still on the intro price before the swap date');
        $this->assertNotNull($cp->intro_swap_at, 'swap remains scheduled');
    }

    public function test_the_swap_job_flips_the_price_on_the_swap_date(): void
    {
        [, , $intro, $full] = $this->introCatalog(introDays: 14);
        $this->fireIntroPurchase($intro, 'cs_intro', amountTotalPence: 0);

        // Day 14: due.
        Carbon::setTestNow(now()->addDays(14));
        $this->artisan('plans:apply-intro-price-swaps')->assertExitCode(0);

        $cp = CustomerProduct::sole();
        $this->assertSame($full->id, $cp->plan_price_id, 'flipped onto the full price');
        $this->assertNull($cp->intro_swap_at, 'schedule cleared — one-shot');
        $this->assertNull($cp->intro_swap_price_id);
        // Cadence synced to the full price so the CP row is self-consistent.
        $this->assertSame('month', $cp->interval_unit);
        $this->assertSame(1, $cp->interval_count);

        $this->assertDatabaseHas('activity_log', [
            'action' => 'customer_product.intro_price_swapped',
            'entity_type' => 'customer_product',
            'entity_id' => $cp->id,
        ]);
    }

    public function test_the_swapped_full_price_then_bills_via_the_existing_subscription_sweep(): void
    {
        // The highest-risk seam: after the swap, the UNCHANGED subscription
        // sweep must bill the full £49 — not the £0 intro — on the swap date.
        User::factory()->create(['role' => 'super_admin']); // sweep attributes invoices to a super_admin
        [, , $intro, $full] = $this->introCatalog(introPrice: 0.00, introDays: 14);
        $this->fireIntroPurchase($intro, 'cs_intro', amountTotalPence: 0);
        $cp = CustomerProduct::sole();

        Carbon::setTestNow(now()->addDays(14));

        // Ordering rationale: run the sweep BEFORE the swap — the CP is still
        // on the £0 intro, which the sweep skips (price <= 0). No invoice yet.
        $this->artisan('invoices:generate-subscriptions')->assertExitCode(0);
        $this->assertSame(0, Invoice::where('customer_id', $cp->customer_id)->where('type', 'subscription')->where('status', 'draft')->count(),
            'the £0 intro is not billed by the sweep');

        // Swap (scheduled 07:15, before the 07:30 sweep), then sweep again.
        $this->artisan('plans:apply-intro-price-swaps')->assertExitCode(0);
        $this->artisan('invoices:generate-subscriptions')->assertExitCode(0);

        // A draft £49 subscription invoice now exists for this customer, and
        // next_billing_date rolled forward one full month from the swap date.
        $invoice = Invoice::where('customer_id', $cp->customer_id)
            ->where('type', 'subscription')->where('status', 'draft')->sole();
        $this->assertEqualsWithDelta(49.00, (float) $invoice->subtotal, 0.001, 'billed the full price, not the intro');
        $this->assertDatabaseHas('invoice_lines', ['invoice_id' => $invoice->id, 'amount' => 49.00]);

        $this->assertSame(now()->addMonthNoOverflow()->toDateString(), $cp->fresh()->next_billing_date->toDateString());
    }

    public function test_a_zero_amount_intro_creates_a_paid_zero_invoice_for_audit_consistency(): void
    {
        // Decision (item 5): a £0 intro DOES generate a £0 invoice at purchase,
        // marked paid, so every purchase has a uniform money-layer trail — not
        // a provisioned subscription with no invoice.
        [, , $intro] = $this->introCatalog(introPrice: 0.00, introDays: 14);

        $this->fireIntroPurchase($intro, 'cs_intro', amountTotalPence: 0);

        $invoice = Invoice::sole();
        $this->assertSame('paid', $invoice->status);
        $this->assertEqualsWithDelta(0.0, (float) $invoice->total, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $invoice->amount_paid, 0.001);
        $this->assertDatabaseHas('invoice_lines', [
            'invoice_id' => $invoice->id,
            'description' => 'ComniCube — Pro (intro price)',
            'amount' => 0.00,
        ]);
        // The settlement ledger row is written too (audit-uniform with paid purchases).
        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->where('status', 'succeeded')->count());
    }

    public function test_a_reduced_non_zero_intro_charges_the_intro_amount_now(): void
    {
        // "£0 OR reduced": a £9 intro charges £9 now (+VAT) and still schedules
        // the swap to the full price — the £0 path is not special-cased.
        [, , $intro, $full] = $this->introCatalog(introPrice: 9.00, introDays: 30);

        // £9 + 20% VAT = £10.80 → 1080 pence.
        $this->fireIntroPurchase($intro, 'cs_intro', amountTotalPence: 1080);

        $invoice = Invoice::sole();
        $this->assertSame('paid', $invoice->status);
        $this->assertEqualsWithDelta(9.00, (float) $invoice->subtotal, 0.001);

        $cp = CustomerProduct::sole();
        $this->assertSame($intro->id, $cp->plan_price_id);
        $this->assertSame(now()->addDays(30)->toDateString(), $cp->intro_swap_at->toDateString());
    }
}
