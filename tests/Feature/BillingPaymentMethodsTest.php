<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\PortalUser;
use App\Models\StripeCustomer;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Billing P1: saved-card storage + the portal management surface. Scoped to the
 * portal user's own customer; only safe card metadata is ever exposed; nothing
 * charges.
 */
class BillingPaymentMethodsTest extends TestCase
{
    use RefreshDatabase;

    private function portalUser(Customer $customer): PortalUser
    {
        return PortalUser::create([
            'customer_id' => $customer->id,
            'name' => 'Portal '.uniqid(),
            'email' => 'p'.uniqid().'@test.test',
            'password' => bcrypt('secret-pass-123'),
        ]);
    }

    private function card(Customer $c, array $overrides = []): PaymentMethod
    {
        return PaymentMethod::create(array_merge([
            'customer_id' => $c->id,
            'stripe_customer_id' => 'cus_'.uniqid(),
            'stripe_payment_method_id' => 'pm_'.uniqid(),
            'brand' => 'visa',
            'last4' => '4242',
            'exp_month' => 4,
            'exp_year' => 2030,
            'is_default' => false,
            'status' => 'active',
        ], $overrides));
    }

    public function test_record_payment_method_vaults_safe_meta_and_first_is_default(): void
    {
        $c = Customer::create(['name' => 'Acme']);
        $svc = app(StripeService::class);

        $first = $svc->recordPaymentMethod($c, 'cus_123', 'pm_a', ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 4, 'exp_year' => 2030]);
        $second = $svc->recordPaymentMethod($c, 'cus_123', 'pm_b', ['brand' => 'mastercard', 'last4' => '1111', 'exp_month' => 1, 'exp_year' => 2031]);

        $this->assertTrue($first->fresh()->is_default);   // first card → default
        $this->assertFalse($second->fresh()->is_default);
        $this->assertSame('4242', $first->last4);
        // Safe meta only — the table has no PAN/CVC column at all.
        $this->assertFalse(\Schema::hasColumn('payment_methods', 'number'));
        $this->assertFalse(\Schema::hasColumn('payment_methods', 'cvc'));
    }

    public function test_portal_lists_only_own_cards_with_safe_meta_only(): void
    {
        $mine = Customer::create(['name' => 'Mine']);
        $other = Customer::create(['name' => 'Other']);
        $myCard = $this->card($mine, ['is_default' => true]);
        $this->card($other); // must never appear

        $res = $this->actingAs($this->portalUser($mine), 'portal')->get('/portal/payment-methods');
        $res->assertOk()->assertInertia(fn ($page) => $page
            ->component('Portal/PaymentMethods')
            ->has('payment_methods', 1)
            ->where('payment_methods.0.id', $myCard->id)
            ->where('payment_methods.0.last4', '4242')
        );

        // The serialised payload exposes no Stripe ids / secrets.
        $json = json_encode($res->viewData('page')['props']['payment_methods']);
        foreach (['stripe_payment_method_id', 'stripe_customer_id', $myCard->stripe_payment_method_id, $myCard->stripe_customer_id] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
    }

    public function test_set_default_flips_and_is_scoped(): void
    {
        $mine = Customer::create(['name' => 'Mine']);
        $a = $this->card($mine, ['is_default' => true]);
        $b = $this->card($mine);
        $otherCard = $this->card(Customer::create(['name' => 'Other']));

        $pu = $this->portalUser($mine);
        $this->actingAs($pu, 'portal')->post("/portal/payment-methods/{$b->id}/default")->assertSessionHasNoErrors();
        $this->assertTrue($b->fresh()->is_default);
        $this->assertFalse($a->fresh()->is_default);

        // Another customer's card can't be defaulted.
        $this->actingAs($pu, 'portal')->post("/portal/payment-methods/{$otherCard->id}/default")->assertNotFound();
        $this->assertFalse($otherCard->fresh()->is_default);
    }

    public function test_remove_marks_removed_and_is_scoped(): void
    {
        $mine = Customer::create(['name' => 'Mine']);
        $card = $this->card($mine, ['is_default' => true]);
        $otherCard = $this->card(Customer::create(['name' => 'Other']));

        // Stripe detach is best-effort; stub it so no real call happens.
        $this->mock(StripeService::class, fn ($m) => $m->shouldReceive('detachPaymentMethod')->andReturnNull());

        $pu = $this->portalUser($mine);
        $this->actingAs($pu, 'portal')->delete("/portal/payment-methods/{$card->id}")->assertSessionHasNoErrors();
        $this->assertSame('removed', $card->fresh()->status);

        $this->actingAs($pu, 'portal')->delete("/portal/payment-methods/{$otherCard->id}")->assertNotFound();
        $this->assertSame('active', $otherCard->fresh()->status);
    }

    public function test_store_saves_a_card_via_the_service(): void
    {
        $mine = Customer::create(['name' => 'Mine']);

        $this->mock(StripeService::class, function ($m) use ($mine) {
            $m->shouldReceive('recordPaymentMethodFromStripe')
                ->once()
                ->andReturnUsing(fn () => PaymentMethod::create([
                    'customer_id' => $mine->id,
                    'stripe_customer_id' => 'cus_x',
                    'stripe_payment_method_id' => 'pm_new',
                    'brand' => 'visa', 'last4' => '0005', 'exp_month' => 6, 'exp_year' => 2032,
                    'is_default' => true, 'status' => 'active',
                ]));
        });

        $this->actingAs($this->portalUser($mine), 'portal')
            ->post('/portal/payment-methods', ['payment_method_id' => 'pm_new'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('payment_methods', ['customer_id' => $mine->id, 'last4' => '0005']);
    }

    public function test_ensure_stripe_customer_reuses_existing_mapping(): void
    {
        $c = Customer::create(['name' => 'Acme']);
        StripeCustomer::create(['customer_id' => $c->id, 'stripe_customer_id' => 'cus_existing']);

        // No Stripe call needed when a mapping exists.
        $this->assertSame('cus_existing', app(StripeService::class)->ensureStripeCustomer($c));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
