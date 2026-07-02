<?php

namespace Tests\Feature;

use App\Models\BillingEntity;
use App\Models\Company;
use App\Models\CustomerProduct;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\ProductPlanPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_due_auto_invoice_subscription_bills_with_created_by_set(): void
    {
        // invoices.created_by is NOT NULL; in a CLI run there's no auth user.
        $admin = User::factory()->create(['role' => 'super_admin']);

        $entity = BillingEntity::create([
            'name' => 'WD', 'legal_name' => 'Whitedash Ltd',
            'postmark_sender_email' => 'billing@wd.test', 'postmark_sender_name' => 'Whitedash',
        ]);
        $product = Product::create(['slug' => 'hosting-'.uniqid(), 'name' => 'Hosting', 'billing_entity_id' => $entity->id]);
        $plan = ProductPlan::create([
            'product_id' => $product->id, 'name' => 'Pro', 'is_active' => true, 'is_public' => true,
        ]);
        $price = ProductPlanPrice::create([
            'plan_id' => $plan->id, 'price' => 30.00, 'interval_count' => 1,
            'interval_unit' => 'month', 'is_default' => true, 'is_active' => true,
        ]);
        $customer = Company::create(['name' => 'Acme']);

        CustomerProduct::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'plan_price_id' => $price->id,
            'status' => 'active',
            'auto_invoice' => true,
            'auto_invoice_entity_id' => $entity->id,
            'next_billing_date' => now()->subDay()->toDateString(), // due
        ]);

        $this->artisan('invoices:generate-subscriptions')->assertExitCode(0);

        $invoice = Invoice::sole();
        $this->assertSame('subscription', $invoice->type);
        $this->assertSame($customer->id, $invoice->customer_id);
        $this->assertEqualsWithDelta(30.00, (float) $invoice->subtotal, 0.001);
        // The regression: created_by is set (was NULL → NOT-NULL violation).
        $this->assertSame($admin->id, $invoice->created_by);
        $this->assertNotNull($invoice->created_by);
    }
}
