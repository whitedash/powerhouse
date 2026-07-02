<?php

namespace Tests\Feature;

use App\Models\BillingEntity;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\ProductPlanPrice;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Stage 1b: per-website hosting billing (invoices:generate-hosting). The money
 * path — price/entity/VAT correctness, cadence advance, and idempotency.
 */
class HostingInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BillingEntity $entity;

    protected function setUp(): void
    {
        parent::setUp();
        // invoices.created_by is NOT NULL — system invoices attribute to a super_admin.
        $this->user = User::factory()->create(['role' => 'super_admin']);
        $this->entity = BillingEntity::create([
            'name' => 'WD',
            'legal_name' => 'Whitedash Ltd',
            'postmark_sender_email' => 'billing@wd.test',
            'postmark_sender_name' => 'Whitedash',
        ]);
    }

    private function hostingPlan(int $count = 1, string $unit = 'month', float $price = 20.00): ProductPlanPrice
    {
        $product = Product::create([
            'slug' => 'host-'.uniqid(),
            'name' => 'Hosting',
            'billing_entity_id' => $this->entity->id,
            'is_active' => true,
        ]);
        $plan = ProductPlan::create([
            'product_id' => $product->id,
            'name' => 'Pro',
            'is_active' => true,
            'is_public' => true,
            'is_hosting' => true,
        ]);

        return ProductPlanPrice::create([
            'plan_id' => $plan->id,
            'price' => $price,
            'interval_count' => $count,
            'interval_unit' => $unit,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function website(ProductPlanPrice $tier, array $overrides = []): Website
    {
        $customer = Company::create(['name' => 'Acme '.uniqid()]);

        return Website::create(array_merge([
            'customer_id' => $customer->id,
            'name' => 'Main site',
            'url' => 'https://acme-'.uniqid().'.test',
            'status' => 'active',
            'created_by' => $this->user->id,
            'plan_id' => $tier->plan_id,
            'plan_price_id' => $tier->id,
            'hosting_status' => 'active',
            'hosting_started_at' => now(),
            'hosting_auto_invoice' => true,
            'hosting_next_billing_date' => Carbon::today()->toDateString(),
        ], $overrides));
    }

    public function test_bills_an_active_auto_due_website_with_correct_price_entity_vat_and_line(): void
    {
        $tier = $this->hostingPlan(price: 20.00);
        $website = $this->website($tier);

        $this->artisan('invoices:generate-hosting')->assertExitCode(0);

        $invoice = Invoice::sole();
        $this->assertSame($website->customer_id, $invoice->customer_id);
        $this->assertSame($this->entity->id, $invoice->billing_entity_id);
        $this->assertSame('subscription', $invoice->type);
        $this->assertSame('draft', $invoice->status);
        $this->assertSame($this->user->id, $invoice->created_by);
        $this->assertEqualsWithDelta(20.00, (float) $invoice->subtotal, 0.001);
        $this->assertEqualsWithDelta(4.00, (float) $invoice->vat_amount, 0.001);
        $this->assertEqualsWithDelta(24.00, (float) $invoice->total, 0.001);

        $line = InvoiceLine::where('invoice_id', $invoice->id)->sole();
        $this->assertSame($tier->plan_id, $line->plan_id);
        $this->assertEqualsWithDelta(20.00, (float) $line->unit_price, 0.001);
    }

    public function test_advances_next_billing_date_by_a_month_and_stamps_last_invoiced(): void
    {
        $tier = $this->hostingPlan(1, 'month');
        $today = Carbon::today();
        $website = $this->website($tier, ['hosting_next_billing_date' => $today->toDateString()]);

        $this->artisan('invoices:generate-hosting')->assertExitCode(0);

        $fresh = $website->fresh();
        $this->assertSame($today->copy()->addMonthNoOverflow()->toDateString(), $fresh->hosting_next_billing_date->toDateString());
        $this->assertSame($today->toDateString(), $fresh->hosting_last_invoiced_at->toDateString());
    }

    public function test_annual_tier_advances_one_year(): void
    {
        $tier = $this->hostingPlan(1, 'year', 120.00);
        $today = Carbon::today();
        $website = $this->website($tier, ['hosting_next_billing_date' => $today->toDateString()]);

        $this->artisan('invoices:generate-hosting')->assertExitCode(0);

        $this->assertSame(
            $today->copy()->addYearNoOverflow()->toDateString(),
            $website->fresh()->hosting_next_billing_date->toDateString()
        );
        $this->assertEqualsWithDelta(120.00, (float) Invoice::sole()->subtotal, 0.001);
    }

    public function test_does_not_bill_when_not_due(): void
    {
        $tier = $this->hostingPlan();
        $this->website($tier, ['hosting_next_billing_date' => Carbon::today()->addDays(5)->toDateString()]);

        $this->artisan('invoices:generate-hosting')->assertExitCode(0);
        $this->assertSame(0, Invoice::count());
    }

    public function test_does_not_bill_when_auto_invoice_off(): void
    {
        $tier = $this->hostingPlan();
        $this->website($tier, ['hosting_auto_invoice' => false]);

        $this->artisan('invoices:generate-hosting')->assertExitCode(0);
        $this->assertSame(0, Invoice::count());
    }

    public function test_does_not_bill_when_hosting_suspended(): void
    {
        $tier = $this->hostingPlan();
        $this->website($tier, ['hosting_status' => 'suspended']);

        $this->artisan('invoices:generate-hosting')->assertExitCode(0);
        $this->assertSame(0, Invoice::count());
    }

    public function test_does_not_bill_when_no_plan(): void
    {
        $tier = $this->hostingPlan();
        // Auto + due but no plan/tier attached → never billed.
        $this->website($tier, ['plan_id' => null, 'plan_price_id' => null, 'hosting_status' => 'none']);

        $this->artisan('invoices:generate-hosting')->assertExitCode(0);
        $this->assertSame(0, Invoice::count());
    }

    public function test_is_idempotent_running_twice_bills_once(): void
    {
        $tier = $this->hostingPlan();
        $this->website($tier);

        $this->artisan('invoices:generate-hosting')->assertExitCode(0);
        $this->artisan('invoices:generate-hosting')->assertExitCode(0);

        $this->assertSame(1, Invoice::count());
    }
}
