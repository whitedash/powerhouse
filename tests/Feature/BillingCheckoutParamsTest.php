<?php

namespace Tests\Feature;

use App\Models\BillingEntity;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\StripeCustomer;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Structural regression cover for the P1 live 503 on invoice Checkout.
 *
 * Root cause: the checkout params carried `customer_email` and P1 added
 * `customer` alongside it. Stripe Checkout rejects the two together
 * ("you may only specify one of these parameters: customer, customer_email"),
 * so session-create threw → 503. A second latent failure: the Stripe-Customer
 * resolver wasn't race-safe (concurrent calls could mint two customers and
 * trip the UNIQUE(customer_id) mapping).
 *
 * The fix is structural: one idempotent, race-safe resolver shared by every
 * path, and a deterministic builder that emits EITHER `customer` OR
 * `customer_email` (XOR) — with a designed, observable email-only fallback if
 * resolution fails so a customer can always pay.
 */
class BillingCheckoutParamsTest extends TestCase
{
    use RefreshDatabase;

    private function customerWithEmail(string $email = 'pay@acme.test'): Customer
    {
        $customer = Customer::create(['name' => 'Acme '.uniqid()]);
        Contact::create([
            'customer_id' => $customer->id,
            'name' => 'Pat Payer',
            'email' => $email,
            'is_primary' => true,
        ]);

        return $customer;
    }

    private function invoiceFor(Customer $customer): Invoice
    {
        $entity = BillingEntity::create([
            'name' => 'WD', 'legal_name' => 'Whitedash Ltd',
            'postmark_sender_email' => 'b@wd.test', 'postmark_sender_name' => 'WD',
        ]);
        $user = User::factory()->create(['role' => 'super_admin']);

        return Invoice::create([
            'number' => 'INV-'.random_int(1000, 9999),
            'customer_id' => $customer->id,
            'billing_entity_id' => $entity->id,
            'type' => 'subscription',
            'status' => 'sent',
            'subtotal' => 100.0,
            'vat_rate' => 0,
            'vat_amount' => 0,
            'total' => 100.0,
            'amount_paid' => 0,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'created_by' => $user->id,
        ]);
    }

    /**
     * Invoke the private baseSessionParams() on a (possibly mocked) service.
     *
     * @return array<string, mixed>
     */
    private function buildParams(StripeService $service, Invoice $invoice): array
    {
        $method = new ReflectionMethod(StripeService::class, 'baseSessionParams');
        $method->setAccessible(true);

        return $method->invoke($service, $invoice);
    }

    private function assertCustomerKeysAreExclusive(array $params): void
    {
        // The conflict that caused the 503: both keys present at once.
        $this->assertFalse(
            array_key_exists('customer', $params) && array_key_exists('customer_email', $params),
            'Checkout params must never set both customer and customer_email.'
        );
        $this->assertTrue(
            array_key_exists('customer', $params) xor array_key_exists('customer_email', $params),
            'Checkout params must set exactly one of customer / customer_email.'
        );
    }

    public function test_builder_emits_customer_xor_customer_email_on_the_vault_path(): void
    {
        $invoice = $this->invoiceFor($this->customerWithEmail());

        // Stub only the Stripe network seam so the vault path "succeeds".
        $service = $this->partialMock(StripeService::class, function ($m) {
            $m->shouldAllowMockingProtectedMethods();
            $m->shouldReceive('createStripeCustomer')->andReturn('cus_vaulted');
        });

        $params = $this->buildParams($service, $invoice);

        $this->assertSame('cus_vaulted', $params['customer']);
        $this->assertSame(['setup_future_usage' => 'off_session'], $params['payment_intent_data']);
        $this->assertCustomerKeysAreExclusive($params);
    }

    public function test_resolution_failure_degrades_to_a_working_observable_email_only_session(): void
    {
        $invoice = $this->invoiceFor($this->customerWithEmail('fallback@acme.test'));

        // The Stripe customer-create blows up (restricted key / transient error).
        $service = $this->partialMock(StripeService::class, function ($m) {
            $m->shouldAllowMockingProtectedMethods();
            $m->shouldReceive('createStripeCustomer')
                ->andThrow(new \RuntimeException('stripe down'));
        });

        // The fallback must be observable, not a silent swallow.
        Log::shouldReceive('warning')
            ->once()
            ->with('stripe.customer_resolve_failed', Mockery::type('array'));

        $params = $this->buildParams($service, $invoice);

        // Taking payment is essential: still a usable session...
        $this->assertSame('payment', $params['mode']);
        $this->assertNotEmpty($params['line_items']);
        // ...degraded to email-only, no vault params left, and XOR holds.
        $this->assertArrayNotHasKey('customer', $params);
        $this->assertArrayNotHasKey('payment_intent_data', $params);
        $this->assertSame('fallback@acme.test', $params['customer_email']);
        $this->assertCustomerKeysAreExclusive($params);
    }

    public function test_resolver_is_idempotent_one_stripe_customer_across_calls(): void
    {
        $customer = $this->customerWithEmail();

        // The Stripe create seam must run at most once: the second resolve hits
        // the persisted mapping and never touches Stripe.
        $service = $this->partialMock(StripeService::class, function ($m) {
            $m->shouldAllowMockingProtectedMethods();
            $m->shouldReceive('createStripeCustomer')->once()->andReturn('cus_one');
        });

        $first = $service->resolveStripeCustomer($customer);
        $second = $service->resolveStripeCustomer($customer);

        $this->assertSame('cus_one', $first);
        $this->assertSame('cus_one', $second);
        $this->assertSame(1, StripeCustomer::where('customer_id', $customer->id)->count());
    }

    public function test_resolver_handles_the_unique_constraint_race(): void
    {
        $customer = $this->customerWithEmail();

        // Simulate a concurrent winner: while THIS resolve is creating the
        // Stripe customer, another request persists the mapping first. The
        // resolver must catch the UNIQUE(customer_id) violation on its own
        // insert and converge on the winner's id — not throw, not duplicate.
        $service = $this->partialMock(StripeService::class, function ($m) use ($customer) {
            $m->shouldAllowMockingProtectedMethods();
            $m->shouldReceive('createStripeCustomer')
                ->once()
                ->andReturnUsing(function () use ($customer) {
                    StripeCustomer::create([
                        'customer_id' => $customer->id,
                        'stripe_customer_id' => 'cus_winner',
                    ]);

                    return 'cus_loser';
                });
        });

        $resolved = $service->resolveStripeCustomer($customer);

        $this->assertSame('cus_winner', $resolved);
        $this->assertSame(1, StripeCustomer::where('customer_id', $customer->id)->count());
    }

    public function test_existing_mapping_short_circuits_with_no_stripe_call(): void
    {
        $customer = $this->customerWithEmail();
        StripeCustomer::create(['customer_id' => $customer->id, 'stripe_customer_id' => 'cus_existing']);
        $invoice = $this->invoiceFor($customer);

        // No mock: with a mapping present the real resolver never reaches the
        // Stripe network, so this passes offline and proves the early return.
        $params = $this->buildParams(app(StripeService::class), $invoice);

        $this->assertSame('cus_existing', $params['customer']);
        $this->assertCustomerKeysAreExclusive($params);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
