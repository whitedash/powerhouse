<?php

namespace Tests\Feature;

use App\Http\Controllers\Webhooks\StripeWebhookController;
use App\Mail\PlanPurchaseReceipt;
use App\Models\BillingEntity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerProduct;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Person;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\ProductPlanPrice;
use App\Models\StripeCustomer;
use App\Models\User;
use App\Services\OffSessionCollector;
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

    /** Fire a completed session with an explicit amount_total + session id. */
    private function firePlanCheckoutCompletedWithAmount(ProductPlanPrice $price, string $sessionId, int $amountTotal): void
    {
        $event = Event::constructFrom([
            'id' => 'evt_'.uniqid(),
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => $sessionId, 'payment_intent' => 'pi_'.$sessionId, 'amount_total' => $amountTotal,
                'metadata' => ['plan_price_id' => (string) $price->id, 'purchaser_name' => 'Pat Purchaser', 'purchaser_email' => 'pat.purchaser@gmail.com'],
            ]],
        ]);
        $request = Request::create('/webhooks/stripe', 'POST');
        $request->attributes->set('stripeEvent', $event);
        app(StripeWebhookController::class)->receive($request, app(StripeService::class), app(WebhookIdempotencyService::class));
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

    public function test_embed_renders_both_amounts_for_a_setup_fee_price_and_one_for_a_plain_price(): void
    {
        [$product, $plan] = $this->catalog(priceOverrides: [
            'price' => 49.00, 'setup_fee' => 10.00, 'interval_unit' => 'month',
        ]);

        $feeBody = $this->get('/plans/comnicube/embed.js')->assertOk()->getContent();
        // The fee price ships BOTH amounts in the config — the recurring
        // `amount` and the one-off `setup_fee` — so the widget's fee-aware
        // branch can render "£X now, then £Y / interval". The card-vaulting
        // consent line stays distinct and present. (£ is unicode-escaped by
        // json_encode, so build each needle with the same encoder, not the
        // raw glyph. The "now, then" copy is STATIC JS in the IIFE — present
        // in every response — so it can't discriminate; the setup_fee VALUE
        // does.)
        $this->assertStringContainsString('"amount":'.json_encode('£49.00'), $feeBody);
        $this->assertStringContainsString('"setup_fee":'.json_encode('£10.00'), $feeBody);
        $this->assertStringContainsString('securely stored', $feeBody);

        // A plain price ships setup_fee:null → the widget shows the single
        // recurring amount and the fee branch never fires client-side.
        ProductPlanPrice::query()->update(['setup_fee' => null]);
        $plainBody = $this->get('/plans/comnicube/embed.js')->assertOk()->getContent();
        $this->assertStringContainsString('"amount":'.json_encode('£49.00'), $plainBody);
        $this->assertStringContainsString('"setup_fee":null', $plainBody);
    }

    public function test_embed_ships_the_animated_step_swap(): void
    {
        $this->catalog();

        $body = $this->get('/plans/comnicube/embed.js')->assertOk()->getContent();

        // Static pins for the modal's animated step transition — the
        // visual smoothness itself is browser behaviour (no JS test
        // runner; manually verified): the step wrapper exists, the swap
        // animates height, and the height is RELEASED to auto on
        // transitionend so Stripe's self-resizing iframe isn't fought.
        $this->assertStringContainsString('function swapStep(wrap, nodes)', $body);
        $this->assertStringContainsString('height .3s ease', $body);
        $this->assertStringContainsString('wrap.style.height = "auto"', $body);
        $this->assertStringContainsString('mountCheckout(stepWrap,', $body);
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

    public function test_plan_checkout_session_excludes_stripe_link(): void
    {
        [, , $price] = $this->catalog();

        // Params inspected directly (no live Stripe call): every plans-
        // widget purchaser is a brand-new anonymous visitor heading into
        // auto-provisioning, so Link's cross-merchant recognition must not
        // appear. card-only types + the wallet-level Link off-switch.
        $params = app(StripeService::class)->planCheckoutSessionParams(
            $price, 'Pat Purchaser', 'pat.purchaser@gmail.com', 120.0, 'comnicube',
        );

        $this->assertSame(['card'], $params['payment_method_types']);
        $this->assertSame(['link' => ['display' => 'never']], $params['wallet_options']);
        $this->assertSame(12000, $params['line_items'][0]['price_data']['unit_amount']);
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

    // ── optional company + phone (step-1 extras) ─────────────────────────

    public function test_checkout_captures_optional_company_and_phone_on_the_attempt_row(): void
    {
        [, , $price] = $this->catalog();

        $this->mock(StripeService::class, function ($m) {
            $m->shouldReceive('createPlanCheckoutSession')
                ->once()
                ->withArgs(function (ProductPlanPrice $p, string $name, string $email, float $total, string $slug, ?string $company, ?string $phone): bool {
                    // The extras thread into the session-creation call…
                    return $company === 'Acme Ltd' && $phone === '+44 7700 900123';
                })
                ->andReturn(Session::constructFrom(['id' => 'cs_extras_1', 'client_secret' => 'cs_secret_x']));
        });

        $this->postJson('/plans/comnicube/checkout', [
            'plan_price_id' => $price->id,
            'name' => 'Pat Purchaser',
            'email' => 'pat.purchaser@gmail.com',
            'company' => 'Acme Ltd',
            'phone' => '+44 7700 900123',
        ])->assertOk();

        // …and onto the tracking row for abandoned-checkout follow-up.
        $this->assertDatabaseHas('plan_checkout_attempts', [
            'stripe_checkout_session_id' => 'cs_extras_1',
            'purchaser_company' => 'Acme Ltd',
            'purchaser_phone' => '+44 7700 900123',
        ]);
    }

    public function test_webhook_uses_company_name_and_contact_phone_when_provided(): void
    {
        Mail::fake();
        [, , $price] = $this->catalog();

        $event = Event::constructFrom([
            'id' => 'evt_extras',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_extras_wh',
                'payment_intent' => 'pi_extras',
                'amount_total' => 12000,
                'metadata' => [
                    'plan_price_id' => (string) $price->id,
                    'purchaser_name' => 'Pat Purchaser',
                    'purchaser_email' => 'pat.purchaser@gmail.com',
                    'purchaser_company' => 'Acme Ltd',
                    'purchaser_phone' => '+44 7700 900123',
                ],
            ]],
        ]);
        $request = Request::create('/webhooks/stripe', 'POST');
        $request->attributes->set('stripeEvent', $event);
        app(StripeWebhookController::class)->receive(
            $request, app(StripeService::class), app(WebhookIdempotencyService::class),
        );

        // Company named after the org, not the person…
        $this->assertDatabaseHas('customers', ['name' => 'Acme Ltd']);
        $this->assertDatabaseMissing('customers', ['name' => 'Pat Purchaser']);
        // …the person keeps their own name, and the phone lands on the
        // primary Contact (the field internal flows populate).
        $this->assertDatabaseHas('contacts', [
            'name' => 'Pat Purchaser',
            'email' => 'pat.purchaser@gmail.com',
            'phone' => '+44 7700 900123',
            'is_primary' => true,
        ]);
    }

    // ── setup fee + recurring (feat/plans-setup-fee-recurring) ───────────

    public function test_setup_fee_purchase_charges_the_fee_and_provisions_a_recurring_subscription(): void
    {
        Mail::fake();
        // £10 setup fee, £49/month recurring.
        [, , $price] = $this->catalog(priceOverrides: [
            'price' => 49.00, 'setup_fee' => 10.00, 'interval_unit' => 'month', 'interval_count' => 1,
        ]);

        // amount_total reflects the FEE (£10 + 20% VAT = £12), what Stripe charged.
        $event = Event::constructFrom([
            'id' => 'evt_fee', 'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_fee', 'payment_intent' => 'pi_fee', 'amount_total' => 1200,
                'metadata' => ['plan_price_id' => (string) $price->id, 'purchaser_name' => 'Pat Purchaser', 'purchaser_email' => 'pat.purchaser@gmail.com'],
            ]],
        ]);
        $request = Request::create('/webhooks/stripe', 'POST');
        $request->attributes->set('stripeEvent', $event);
        app(StripeWebhookController::class)->receive($request, app(StripeService::class), app(WebhookIdempotencyService::class));

        // The immediate invoice charges the FEE, not the recurring price.
        $invoice = Invoice::sole();
        $this->assertSame('paid', $invoice->status);
        $this->assertEqualsWithDelta(10.00, (float) $invoice->subtotal, 0.001);
        $this->assertDatabaseHas('invoice_lines', [
            'invoice_id' => $invoice->id, 'amount' => 10.00, 'description' => 'ComniCube — Starter (setup fee)',
        ]);

        // The customer_product is set up for recurring: auto_invoice=true,
        // next_billing_date one interval out, plan_price_id → the SAME row.
        $cp = CustomerProduct::sole();
        $this->assertTrue((bool) $cp->auto_invoice);
        $this->assertSame($price->id, $cp->plan_price_id);
        $this->assertSame(now()->addMonthNoOverflow()->toDateString(), $cp->next_billing_date->toDateString());
    }

    public function test_a_fee_less_purchase_is_byte_identical_to_the_pre_feature_behaviour(): void
    {
        Mail::fake();
        // Explicit regression: no setup_fee → nothing recurring, invoice
        // charges the price, description unchanged.
        [, , $price] = $this->catalog(); // price 100, no setup_fee

        $this->firePlanCheckoutCompleted($price);

        $invoice = Invoice::sole();
        $this->assertEqualsWithDelta(100.00, (float) $invoice->subtotal, 0.001);
        $this->assertDatabaseHas('invoice_lines', [
            'invoice_id' => $invoice->id, 'description' => 'ComniCube — Starter',
        ]);
        $cp = CustomerProduct::sole();
        $this->assertFalse((bool) $cp->auto_invoice);
        $this->assertNull($cp->next_billing_date);
    }

    public function test_the_subscription_sweep_bills_the_recurring_price_on_a_fee_provisioned_customer_product(): void
    {
        // THE HIGH-RISK SEAM: a customer_product created by settle() for a
        // setup-fee purchase must be picked up by the REAL
        // invoices:generate-subscriptions sweep and billed at the RECURRING
        // price (not the fee) on its next_billing_date. Cross-system, not a
        // settle() unit test.
        Mail::fake();
        // The sweep attributes system invoices' created_by to a super_admin
        // (the plan path itself creates none — it uses created_by=null).
        User::factory()->create(['role' => 'super_admin']);
        [, , $price] = $this->catalog(priceOverrides: [
            'price' => 49.00, 'setup_fee' => 10.00, 'interval_unit' => 'month', 'interval_count' => 1,
        ]);

        $this->firePlanCheckoutCompletedWithAmount($price, 'cs_sweep', 1200);
        $cp = CustomerProduct::sole();

        // Fast-forward: make the recurring charge due.
        $cp->update(['next_billing_date' => now()->subDay()->toDateString()]);

        // The fee invoice already exists; run the sweep and confirm it drafts
        // a SECOND invoice at the recurring £49 (VAT applied by the sweep).
        $this->artisan('invoices:generate-subscriptions')->assertExitCode(0);

        $recurring = Invoice::where('customer_id', $cp->customer_id)
            ->where('id', '!=', Invoice::where('stripe_checkout_session_id', 'cs_sweep')->value('id'))
            ->sole();
        $this->assertSame('subscription', $recurring->type);
        $this->assertEqualsWithDelta(49.00, (float) $recurring->subtotal, 0.001);

        // And it advanced the cadence forward (didn't re-bill immediately).
        $this->assertTrue($cp->fresh()->next_billing_date->isFuture());
    }

    // ── card vaulting (feat/plans-checkout-card-vaulting) ────────────────

    public function test_settlement_vaults_the_card_onto_the_provisioned_company(): void
    {
        Mail::fake();
        [, , $price] = $this->catalog();

        // The vault step retrieves the session + payment method from Stripe
        // (a live call), so partial-mock ONLY that seam: have it perform the
        // real DB writes recordPaymentMethod would, proving settle() calls
        // it with the provisioned Company.
        $this->partialMock(StripeService::class, function ($m) {
            $m->shouldReceive('vaultPlanCardFromSession')
                ->once()
                ->andReturnUsing(function (Company $company, string $sessionId) {
                    app(StripeService::class)->recordPaymentMethod($company, 'cus_plan_123', 'pm_plan_123', [
                        'brand' => 'visa', 'last4' => '4242', 'exp_month' => 4, 'exp_year' => 2030,
                    ]);
                    StripeCustomer::firstOrCreate(
                        ['customer_id' => $company->id],
                        ['stripe_customer_id' => 'cus_plan_123'],
                    );

                    return true;
                });
        });

        $this->firePlanCheckoutCompleted($price);

        $company = Company::where('name', 'Pat Purchaser')->firstOrFail();
        $this->assertDatabaseHas('stripe_customers', [
            'customer_id' => $company->id, 'stripe_customer_id' => 'cus_plan_123',
        ]);
        $this->assertDatabaseHas('payment_methods', [
            'customer_id' => $company->id,
            'stripe_payment_method_id' => 'pm_plan_123',
            'is_default' => true, 'status' => 'active', 'brand' => 'visa', 'last4' => '4242',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'action' => 'customer.card_vaulted', 'entity_id' => $company->id, 'user_role' => 'system',
        ]);
    }

    public function test_a_plan_vaulted_card_is_chargeable_by_the_off_session_sweep(): void
    {
        // Cross-compatibility: a card vaulted by the plan path must be
        // indistinguishable to the collection sweep from an invoice-vaulted
        // one. Vault via the SAME write shape, then run the real
        // OffSessionCollector (Stripe charge stubbed) and confirm it selects
        // the plan-vaulted card and charges it.
        $entity = $this->entity();
        $company = Company::create(['name' => 'Vaulted Co', 'auto_collect' => true]);
        Contact::create(['customer_id' => $company->id, 'name' => 'Pat', 'email' => 'p@vaulted.test', 'is_primary' => true]);

        // Written exactly as the plan vault step writes it.
        app(StripeService::class)->recordPaymentMethod($company, 'cus_plan_999', 'pm_plan_999', [
            'brand' => 'visa', 'last4' => '4242', 'exp_month' => 4, 'exp_year' => 2030,
        ]);
        StripeCustomer::create(['customer_id' => $company->id, 'stripe_customer_id' => 'cus_plan_999']);

        $invoice = Invoice::create([
            'number' => 'INV-'.random_int(1000, 9999), 'customer_id' => $company->id,
            'billing_entity_id' => $entity->id, 'type' => 'subscription', 'status' => 'sent',
            'subtotal' => 50, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 50, 'amount_paid' => 0,
            'issue_date' => now()->toDateString(), 'due_date' => now()->toDateString(),
            'created_by' => User::factory()->create(['role' => 'super_admin'])->id,
        ]);

        // Stub the actual charge; assert it fires against the plan-vaulted PM.
        $this->partialMock(StripeService::class, function ($m) {
            $m->shouldReceive('chargeOffSession')
                ->once()
                ->withArgs(fn (string $cus, string $pm, int $pence, array $meta, string $key): bool => $cus === 'cus_plan_999' && $pm === 'pm_plan_999')
                ->andReturn(['status' => 'succeeded', 'payment_intent_id' => 'pi_ok', 'failure_reason' => null]);
        });

        app(OffSessionCollector::class)->run(false);

        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_vault_failure_does_not_block_settlement(): void
    {
        Mail::fake();
        [, , $price] = $this->catalog();

        // Vault throws (e.g. Stripe unreachable) — the paid purchase and its
        // provisioning must still stand; the customer just isn't auto-billable.
        $this->partialMock(StripeService::class, function ($m) {
            $m->shouldReceive('vaultPlanCardFromSession')
                ->once()
                ->andThrow(new \RuntimeException('stripe down'));
        });

        $this->firePlanCheckoutCompleted($price);

        $company = Company::where('name', 'Pat Purchaser')->firstOrFail();
        $this->assertSame('paid', Invoice::sole()->status);
        $this->assertSame('active', CustomerProduct::sole()->status);
        // No card, no vault-success log — graceful degradation.
        $this->assertDatabaseMissing('payment_methods', ['customer_id' => $company->id]);
        $this->assertDatabaseMissing('activity_log', ['action' => 'customer.card_vaulted']);
    }

    public function test_session_params_add_customer_and_setup_future_usage_when_vaulting(): void
    {
        [, , $price] = $this->catalog();
        $service = app(StripeService::class);

        // With a Stripe Customer id → vaulting session: customer +
        // setup_future_usage, and NO customer_email (Stripe rejects both).
        $vaulting = $service->planCheckoutSessionParams($price, 'Pat', 'pat@gmail.com', 120.0, 'comnicube', null, null, 'cus_abc');
        $this->assertSame('cus_abc', $vaulting['customer']);
        $this->assertSame(['setup_future_usage' => 'off_session'], $vaulting['payment_intent_data']);
        $this->assertArrayNotHasKey('customer_email', $vaulting);
        $this->assertSame('cus_abc', $vaulting['metadata']['stripe_customer_id']);

        // Without one (the fallback) → email-only, no customer/setup.
        $emailOnly = $service->planCheckoutSessionParams($price, 'Pat', 'pat@gmail.com', 120.0, 'comnicube');
        $this->assertArrayNotHasKey('customer', $emailOnly);
        $this->assertArrayNotHasKey('payment_intent_data', $emailOnly);
        $this->assertSame('pat@gmail.com', $emailOnly['customer_email']);
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
