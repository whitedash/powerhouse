<?php

namespace Tests\Feature;

use App\Http\Controllers\Webhooks\StripeWebhookController;
use App\Mail\PlanPurchaseReceipt;
use App\Models\BillingEntity;
use App\Models\Company;
use App\Models\CustomerProduct;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Person;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\ProductPlanPrice;
use App\Models\User;
use App\Services\StripeService;
use App\Services\WebhookIdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Stripe\Checkout\Session;
use Stripe\Event;
use Tests\TestCase;

/**
 * Plans widget public surface (PLANS-WIDGET-DESIGN.md §3–4): the embed
 * script's public-only catalog, the anonymous checkout-initiation guards
 * (catalog re-verification, Turnstile, honeypot), and the webhook-only
 * provisioning branch — active + pending-review paths, replay safety, and
 * the untouched invoice_id settlement path.
 *
 * The webhook branch is exercised end-to-end with REAL services (no
 * Stripe API calls exist on the plan-purchase path — settlement is pure
 * DB); only checkout-initiation mocks StripeService, since creating a
 * session is a live API call.
 */
class PlanWidgetPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private function entity(): BillingEntity
    {
        // VAT-registered at 20% so the £100 price grosses to £120 — the
        // effective_vat_rate accessor forces 0 for a non-registered entity.
        return BillingEntity::create([
            'name' => 'WD', 'legal_name' => 'Whitedash Ltd',
            'postmark_sender_email' => 'b@wd.test', 'postmark_sender_name' => 'WD',
            'is_active' => true,
            'vat_registered' => true,
            'default_vat_rate' => 20,
        ]);
    }

    /**
     * Product + one public/active plan with one active £100 one-time price.
     *
     * @return array{0: Product, 1: ProductPlan, 2: ProductPlanPrice}
     */
    private function catalog(array $planOverrides = [], array $priceOverrides = []): array
    {
        $this->entity();

        $product = Product::create([
            'slug' => 'comnicube', 'name' => 'ComniCube', 'is_active' => true,
        ]);
        $plan = ProductPlan::create(array_merge([
            'product_id' => $product->id,
            'name' => 'Starter',
            'description' => 'Everything to get going',
            'features' => ['One venue', 'Email support'],
            'is_active' => true,
            'is_public' => true,
        ], $planOverrides));
        $price = ProductPlanPrice::create(array_merge([
            'plan_id' => $plan->id,
            'price' => 100.00,
            'interval_count' => 1,
            'interval_unit' => 'one_time',
            'is_active' => true,
            'is_default' => true,
        ], $priceOverrides));

        return [$product, $plan, $price];
    }

    /**
     * Drive the webhook controller exactly like BillingLedgerTest: a
     * trusted Event stashed on the request (signature verification is
     * middleware, not under test here).
     */
    private function firePlanCheckoutCompleted(ProductPlanPrice $price, string $sessionId = 'cs_plan_1', ?string $eventId = null): void
    {
        $event = Event::constructFrom([
            'id' => $eventId ?? 'evt_'.uniqid(),
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => $sessionId,
                'payment_intent' => 'pi_plan_1',
                'amount_total' => 12000, // £100 + 20% VAT in pence
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

    // ── embed.js ─────────────────────────────────────────────────────────

    public function test_embed_js_serves_only_public_active_plans_with_open_cors(): void
    {
        [$product] = $this->catalog();
        ProductPlan::create([
            'product_id' => $product->id, 'name' => 'Hidden internal plan',
            'is_active' => true, 'is_public' => false,
        ]);
        ProductPlan::create([
            'product_id' => $product->id, 'name' => 'Retired plan',
            'is_active' => false, 'is_public' => true,
        ]);

        $response = $this->get('/plans/comnicube/embed.js');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeader('Cache-Control', 'max-age=300, public');
        $body = $response->getContent();
        $this->assertStringContainsString('Starter', $body);
        // The mount id ships in the config since the single-plan embed
        // parameterised it (root_id replaces the old slug concatenation).
        $this->assertStringContainsString('"root_id":"pw-plans-comnicube"', $body);
        $this->assertStringContainsString('"slug":"comnicube"', $body);
        $this->assertStringNotContainsString('Hidden internal plan', $body);
        $this->assertStringNotContainsString('Retired plan', $body);
    }

    public function test_embed_js_404s_for_an_inactive_product(): void
    {
        $this->catalog();
        Product::where('slug', 'comnicube')->update(['is_active' => false]);

        $this->get('/plans/comnicube/embed.js')->assertNotFound();
    }

    // ── checkout initiation ──────────────────────────────────────────────

    public function test_checkout_returns_a_client_secret_for_a_live_public_price(): void
    {
        [, , $price] = $this->catalog();

        $this->mock(StripeService::class, function ($m) use ($price) {
            $m->shouldReceive('createPlanCheckoutSession')
                ->once()
                ->withArgs(fn (ProductPlanPrice $p, string $name, string $email, float $total): bool => $p->is($price)
                    && $name === 'Pat Purchaser'
                    && $email === 'pat.purchaser@gmail.com'
                    // £100 + 20% VAT computed server-side; the client never sent an amount.
                    && abs($total - 120.0) < 0.001)
                ->andReturn(Session::constructFrom([
                    'id' => 'cs_init_1',
                    'client_secret' => 'cs_secret_test',
                ]));
        });

        $this->postJson('/plans/comnicube/checkout', [
            'plan_price_id' => $price->id,
            'name' => 'Pat Purchaser',
            'email' => 'pat.purchaser@gmail.com',
        ])->assertOk()->assertJson(['client_secret' => 'cs_secret_test']);

        // Checkout-init's ONE deliberate DB write: the tracking row.
        $this->assertDatabaseHas('plan_checkout_attempts', [
            'stripe_checkout_session_id' => 'cs_init_1',
            'plan_price_id' => $price->id,
            'purchaser_email' => 'pat.purchaser@gmail.com',
            'status' => 'pending',
        ]);
        // And nothing else was provisioned.
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_checkout_rejects_a_non_public_plan_even_when_requested_directly(): void
    {
        [, , $price] = $this->catalog(['is_public' => false]);

        $this->mock(StripeService::class, function ($m) {
            $m->shouldReceive('createPlanCheckoutSession')->never();
        });

        $this->postJson('/plans/comnicube/checkout', [
            'plan_price_id' => $price->id,
            'name' => 'Pat', 'email' => 'pat@gmail.com',
        ])->assertStatus(422)->assertJsonValidationErrors(['plan_price_id']);
    }

    public function test_checkout_rejects_an_inactive_price(): void
    {
        [, , $price] = $this->catalog(priceOverrides: ['is_active' => false]);

        $this->mock(StripeService::class, function ($m) {
            $m->shouldReceive('createPlanCheckoutSession')->never();
        });

        $this->postJson('/plans/comnicube/checkout', [
            'plan_price_id' => $price->id,
            'name' => 'Pat', 'email' => 'pat@gmail.com',
        ])->assertStatus(422)->assertJsonValidationErrors(['plan_price_id']);
    }

    public function test_checkout_fails_closed_when_turnstile_fails(): void
    {
        [, , $price] = $this->catalog();
        // phpunit.xml bypasses Turnstile globally; un-bypass to prove the
        // gate. No cf-turnstile-response token → immediate refusal, before
        // any Stripe call.
        config(['services.turnstile.bypass' => false]);

        $this->mock(StripeService::class, function ($m) {
            $m->shouldReceive('createPlanCheckoutSession')->never();
        });

        $this->postJson('/plans/comnicube/checkout', [
            'plan_price_id' => $price->id,
            'name' => 'Pat', 'email' => 'pat@gmail.com',
        ])->assertStatus(422)->assertJsonValidationErrors(['captcha']);
    }

    public function test_checkout_honeypot_gets_a_shaped_success_without_a_secret(): void
    {
        [, , $price] = $this->catalog();

        $this->mock(StripeService::class, function ($m) {
            $m->shouldReceive('createPlanCheckoutSession')->never();
        });

        $this->postJson('/plans/comnicube/checkout', [
            'plan_price_id' => $price->id,
            'name' => 'Bot', 'email' => 'bot@gmail.com',
            '_hp' => 'filled-by-a-bot',
        ])->assertOk()->assertExactJson(['received' => true]);
    }

    // ── webhook provisioning: active path ────────────────────────────────

    public function test_webhook_plan_purchase_provisions_and_settles_live(): void
    {
        Mail::fake();
        [$product, $plan, $price] = $this->catalog();

        $this->firePlanCheckoutCompleted($price);

        // Company + Contact + Person via the system-actor funnel.
        $this->assertDatabaseHas('customers', [
            'name' => 'Pat Purchaser',
            'acquisition_channel' => 'landing_page',
            'channel_detail' => 'plans-widget:comnicube',
            'pipeline_stage' => 'active',
        ]);
        $person = Person::where('email', 'pat.purchaser@gmail.com')->firstOrFail();
        $this->assertNull($person->created_by);

        // Live subscription record.
        $cp = CustomerProduct::sole();
        $this->assertSame('active', $cp->status);
        $this->assertSame($product->id, $cp->product_id);
        $this->assertSame($plan->id, $cp->plan_id);
        $this->assertSame($price->id, $cp->plan_price_id);

        // Invoice created, catalog-linked, and settled through the shared
        // markInvoicePaid path (paid + ledger row + system audit).
        $invoice = Invoice::sole();
        $this->assertSame('paid', $invoice->status);
        $this->assertNull($invoice->created_by);
        $this->assertSame('cs_plan_1', $invoice->stripe_checkout_session_id);
        $this->assertSame('pi_plan_1', $invoice->stripe_payment_intent_id);
        $this->assertEqualsWithDelta(120.0, (float) $invoice->total, 0.001);
        $this->assertDatabaseHas('invoice_lines', [
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'plan_id' => $plan->id,
        ]);
        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'rail' => 'stripe',
            'status' => 'succeeded',
            'stripe_payment_intent_id' => 'pi_plan_1',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'action' => 'invoice.paid',
            'entity_id' => $invoice->id,
            'user_id' => null,
            'user_role' => 'system',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'action' => 'customer_product.purchased',
            'entity_id' => $cp->id,
            'user_role' => 'system',
        ]);

        Mail::assertSent(PlanPurchaseReceipt::class, function (PlanPurchaseReceipt $mail): bool {
            return $mail->hasTo('pat.purchaser@gmail.com');
        });
    }

    // ── webhook provisioning: pending-review path ────────────────────────

    public function test_webhook_pending_review_plan_holds_subscription_and_withholds_receipt(): void
    {
        Mail::fake();
        [, , $price] = $this->catalog(['requires_manual_review' => true]);

        $this->firePlanCheckoutCompleted($price);

        // Held for the internal Provisioning page's approval queue…
        $this->assertSame('pending', CustomerProduct::sole()->status);
        // …but the money settled, so the invoice/ledger must say paid.
        $this->assertSame('paid', Invoice::sole()->status);
        $this->assertDatabaseHas('payments', ['status' => 'succeeded']);

        Mail::assertNotSent(PlanPurchaseReceipt::class);
    }

    // ── dedup + replay ───────────────────────────────────────────────────

    public function test_webhook_purchase_links_to_an_existing_person_by_email(): void
    {
        Mail::fake();
        [, , $price] = $this->catalog();
        $existing = Person::factory()->create(['email' => 'pat.purchaser@gmail.com', 'name' => 'Existing Pat']);

        $this->firePlanCheckoutCompleted($price);

        $this->assertSame(1, Person::count());
        $this->assertDatabaseHas('customer_person', ['person_id' => $existing->id]);
    }

    public function test_webhook_replay_of_the_same_session_never_double_provisions(): void
    {
        Mail::fake();
        [, , $price] = $this->catalog();

        // Two distinct deliveries (different event ids — the event-level
        // dedupe doesn't apply) for the SAME Checkout session.
        $this->firePlanCheckoutCompleted($price, 'cs_plan_1', 'evt_first');
        $this->firePlanCheckoutCompleted($price, 'cs_plan_1', 'evt_second');

        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, CustomerProduct::count());
        $this->assertSame(1, Company::count());
        $this->assertSame(1, Payment::count());
        Mail::assertSent(PlanPurchaseReceipt::class, 1);
    }

    // ── regression: the invoice_id path is untouched ─────────────────────

    public function test_webhook_invoice_settlement_path_still_works_unchanged(): void
    {
        $entity = $this->entity();
        $company = Company::create(['name' => 'Legacy Co']);
        $staff = User::factory()->create(['role' => 'super_admin']);
        $invoice = Invoice::create([
            'number' => 'INV-9001',
            'customer_id' => $company->id,
            'billing_entity_id' => $entity->id,
            'type' => 'subscription',
            'status' => 'sent',
            'subtotal' => 50, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 50,
            'amount_paid' => 0,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'created_by' => $staff->id,
        ]);

        // The old-style session: metadata.invoice_id, no plan_price_id.
        $this->partialMock(StripeService::class, function ($m) {
            $m->shouldReceive('vaultCardFromSession')->once();
        });

        $event = Event::constructFrom([
            'id' => 'evt_legacy',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_legacy', 'payment_intent' => 'pi_legacy', 'customer' => 'cus_1',
                'metadata' => ['invoice_id' => (string) $invoice->id],
            ]],
        ]);
        $request = Request::create('/webhooks/stripe', 'POST');
        $request->attributes->set('stripeEvent', $event);
        app(StripeWebhookController::class)->receive(
            $request, app(StripeService::class), app(WebhookIdempotencyService::class),
        );

        $this->assertSame('paid', $invoice->fresh()->status);
        // No plan-purchase side effects on the legacy path.
        $this->assertSame(0, CustomerProduct::count());
        $this->assertSame(1, Company::count());
    }
}
