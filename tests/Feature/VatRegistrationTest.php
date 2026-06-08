<?php

namespace Tests\Feature;

use App\Models\BillingEntity;
use App\Models\Customer;
use App\Models\CustomerProduct;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\ProductPlanPrice;
use App\Models\User;
use App\Support\InvoiceVat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VAT must follow the issuing BillingEntity's registration status. A
 * non-registered entity yields zero VAT everywhere (generators + manual
 * creation); a registered entity applies its rate. BillingEntity is the single
 * source of truth — no hardcoded 20% survives in the generators.
 */
class VatRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function entity(bool $registered): BillingEntity
    {
        return BillingEntity::create([
            'name' => 'E'.uniqid(),
            'legal_name' => 'Entity Ltd',
            'vat_number' => $registered ? 'GB123456789' : null,
            'default_vat_rate' => 20.00,
            'vat_registered' => $registered,
            'postmark_sender_email' => 'b@e.test',
            'postmark_sender_name' => 'E',
        ]);
    }

    private function dueSubscription(BillingEntity $entity, float $price = 50.0): Customer
    {
        $product = Product::create(['slug' => 'p-'.uniqid(), 'name' => 'Svc', 'billing_entity_id' => $entity->id]);
        $plan = ProductPlan::create(['product_id' => $product->id, 'name' => 'Pro', 'is_active' => true, 'is_public' => true]);
        $planPrice = ProductPlanPrice::create([
            'plan_id' => $plan->id, 'price' => $price, 'interval_count' => 1,
            'interval_unit' => 'month', 'is_default' => true, 'is_active' => true,
        ]);
        $customer = Customer::create(['name' => 'Acme '.uniqid()]);
        CustomerProduct::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'plan_price_id' => $planPrice->id,
            'status' => 'active',
            'auto_invoice' => true,
            'auto_invoice_entity_id' => $entity->id,
            'next_billing_date' => now()->subDay()->toDateString(),
        ]);

        return $customer;
    }

    // ---- the single source of truth ------------------------------------

    public function test_breakdown_is_zero_for_a_non_registered_entity(): void
    {
        $b = InvoiceVat::breakdown(100.0, $this->entity(false));

        $this->assertSame(0.0, $b['vat_rate']);
        $this->assertSame(0.0, $b['vat_amount']);
        $this->assertSame(100.0, $b['total']);
    }

    public function test_breakdown_forces_zero_even_when_a_rate_is_requested(): void
    {
        // A staff-requested 20% must not override a non-registered entity.
        $b = InvoiceVat::breakdown(100.0, $this->entity(false), 20.0);

        $this->assertSame(0.0, $b['vat_amount']);
        $this->assertSame(100.0, $b['total']);
    }

    public function test_breakdown_applies_the_rate_for_a_registered_entity(): void
    {
        $b = InvoiceVat::breakdown(100.0, $this->entity(true));

        $this->assertSame(20.0, $b['vat_rate']);
        $this->assertSame(20.0, $b['vat_amount']);
        $this->assertSame(120.0, $b['total']);
    }

    // ---- generators -----------------------------------------------------

    public function test_subscription_generator_charges_no_vat_for_non_registered_entity(): void
    {
        User::factory()->create(['role' => 'super_admin']);
        $entity = $this->entity(false);
        $this->dueSubscription($entity, 50.0);

        $this->artisan('invoices:generate-subscriptions')->assertExitCode(0);

        $invoice = Invoice::sole();
        $this->assertEqualsWithDelta(50.0, (float) $invoice->subtotal, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $invoice->vat_rate, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $invoice->vat_amount, 0.001);
        // total == subtotal, no VAT applied.
        $this->assertEqualsWithDelta((float) $invoice->subtotal, (float) $invoice->total, 0.001);
    }

    public function test_subscription_generator_applies_vat_for_registered_entity(): void
    {
        User::factory()->create(['role' => 'super_admin']);
        $entity = $this->entity(true);
        $this->dueSubscription($entity, 50.0);

        $this->artisan('invoices:generate-subscriptions')->assertExitCode(0);

        $invoice = Invoice::sole();
        $this->assertEqualsWithDelta(20.0, (float) $invoice->vat_rate, 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $invoice->vat_amount, 0.001);
        $this->assertEqualsWithDelta(60.0, (float) $invoice->total, 0.001);
    }

    // ---- manual creation -----------------------------------------------

    public function test_manual_creation_charges_no_vat_for_non_registered_entity(): void
    {
        $staff = User::factory()->create(['role' => 'super_admin']);
        $entity = $this->entity(false);
        $customer = Customer::create(['name' => 'Acme']);

        $this->actingAs($staff)
            ->post('/invoices', [
                'customer_id' => $customer->id,
                'billing_entity_id' => $entity->id,
                'type' => 'service',
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(14)->toDateString(),
                // Staff "requests" 20% — the non-registered entity must win.
                'vat_rate' => 20,
                'lines' => [[
                    'description' => 'Consulting',
                    'quantity' => 1,
                    'unit_price' => 200,
                ]],
            ])
            ->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $this->assertEqualsWithDelta(0.0, (float) $invoice->vat_amount, 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $invoice->subtotal, 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $invoice->total, 0.001);
    }

    public function test_manual_creation_applies_requested_vat_for_registered_entity(): void
    {
        $staff = User::factory()->create(['role' => 'super_admin']);
        $entity = $this->entity(true);
        $customer = Customer::create(['name' => 'Acme']);

        $this->actingAs($staff)
            ->post('/invoices', [
                'customer_id' => $customer->id,
                'billing_entity_id' => $entity->id,
                'type' => 'service',
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(14)->toDateString(),
                'vat_rate' => 20,
                'lines' => [[
                    'description' => 'Consulting',
                    'quantity' => 1,
                    'unit_price' => 200,
                ]],
            ])
            ->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $this->assertEqualsWithDelta(40.0, (float) $invoice->vat_amount, 0.001);
        $this->assertEqualsWithDelta(240.0, (float) $invoice->total, 0.001);
    }

    // ---- no hardcoded rate ---------------------------------------------

    public function test_no_hardcoded_vat_rate_remains_in_the_generators(): void
    {
        $generators = [
            'GenerateSubscriptionInvoices',
            'GenerateHostingInvoices',
            'GenerateDomainRenewalInvoices',
            'GenerateRecurringInvoices',
        ];

        foreach ($generators as $cmd) {
            $src = file_get_contents(app_path("Console/Commands/{$cmd}.php"));
            $this->assertStringNotContainsString('DEFAULT_VAT_RATE', $src, "{$cmd} still has a hardcoded VAT const");
            $this->assertStringContainsString('InvoiceVat', $src, "{$cmd} should route VAT through InvoiceVat");
        }
    }
}
