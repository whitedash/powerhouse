<?php

namespace Tests\Feature;

use App\Models\BillingEntity;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\ProductPlanPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainRenewalInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private ?BillingEntity $entity = null;

    protected function setUp(): void
    {
        parent::setUp();
        // invoices.created_by is NOT NULL — system invoices attribute to a super_admin.
        User::factory()->create(['role' => 'super_admin']);
    }

    /**
     * A domain plan with exactly ONE active price tier — its duration is the
     * renewal term, its price the renewal price.
     */
    private function domainPlan(string $tld, float $price = 12.00, int $intervalCount = 1, string $intervalUnit = 'year'): ProductPlan
    {
        $this->entity ??= BillingEntity::create([
            'name' => 'WD',
            'legal_name' => 'Whitedash Ltd',
            'postmark_sender_email' => 'billing@wd.test',
            'postmark_sender_name' => 'Whitedash',
        ]);
        $product = Product::create([
            'slug' => 'dom-'.uniqid(),
            'name' => 'Domains',
            'billing_entity_id' => $this->entity->id,
        ]);
        $plan = ProductPlan::create([
            'product_id' => $product->id,
            'name' => $tld.' renewal',
            'is_domain' => true,
            'is_active' => true,
            'is_public' => true,
            'tld' => $tld,
        ]);
        ProductPlanPrice::create([
            'plan_id' => $plan->id,
            'price' => $price,
            'interval_count' => $intervalCount,
            'interval_unit' => $intervalUnit,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        return $plan;
    }

    private function domain(string $tld, array $overrides = []): Domain
    {
        $customer = Customer::create(['name' => 'Acme '.uniqid()]);

        return Domain::create(array_merge([
            'customer_id' => $customer->id,
            'domain' => 'acme-'.uniqid().$tld,
            'auto_renew' => true,
            'tld' => $tld,
            'expiry_date' => now()->addDays(10)->toDateString(),
            'status' => 'active',
            'ssl_status' => 'none',
        ], $overrides));
    }

    public function test_is_domain_flag_and_tld(): void
    {
        $plan = $this->domainPlan('.com');
        $this->assertTrue($plan->is_domain);
        $this->assertSame('.com', $plan->tld);
    }

    public function test_bills_one_year_tld_with_plan_price_entity_and_vat(): void
    {
        $plan = $this->domainPlan('.com'); // single 1-year tier @ £12
        $domain = $this->domain('.com');

        $this->artisan('invoices:generate-domain-renewals')->assertExitCode(0);

        $invoice = Invoice::sole();
        $this->assertSame($domain->customer_id, $invoice->customer_id);
        $this->assertSame($this->entity->id, $invoice->billing_entity_id);
        $this->assertSame('subscription', $invoice->type);
        $this->assertSame('draft', $invoice->status);
        $this->assertEqualsWithDelta(12.00, (float) $invoice->subtotal, 0.001);
        $this->assertEqualsWithDelta(2.40, (float) $invoice->vat_amount, 0.001);
        $this->assertEqualsWithDelta(14.40, (float) $invoice->total, 0.001);

        $line = InvoiceLine::where('invoice_id', $invoice->id)->sole();
        $this->assertSame($plan->id, $line->plan_id);
        $this->assertSame($plan->product_id, $line->product_id);

        $fresh = $domain->fresh();
        $this->assertSame($domain->expiry_date->toDateString(), $fresh->renewal_invoiced_for->toDateString());
        $this->assertSame($plan->id, $fresh->product_plan_id); // derived link synced
    }

    public function test_bills_the_single_tier_for_its_duration(): void
    {
        // .gr plan: a single 2-year tier @ £20 — its duration IS the renewal
        // term, its price the renewal price.
        $this->domainPlan('.gr', 20.00, 2, 'year');
        $this->domain('.gr');

        $this->artisan('invoices:generate-domain-renewals')->assertExitCode(0);

        $invoice = Invoice::sole();
        $this->assertEqualsWithDelta(20.00, (float) $invoice->subtotal, 0.001);
        $this->assertEqualsWithDelta(24.00, (float) $invoice->total, 0.001);
    }

    public function test_idempotent_within_a_cycle_and_rebills_when_expiry_advances(): void
    {
        $this->domainPlan('.com');
        $domain = $this->domain('.com');

        $this->artisan('invoices:generate-domain-renewals')->assertExitCode(0);
        $this->artisan('invoices:generate-domain-renewals')->assertExitCode(0);
        $this->assertSame(1, Invoice::count());

        $domain->update(['expiry_date' => now()->addDays(8)->toDateString()]);
        $this->artisan('invoices:generate-domain-renewals')->assertExitCode(0);
        $this->assertSame(2, Invoice::count());
    }

    public function test_skips_non_auto_renew_outside_window_and_unmatched_tld(): void
    {
        $this->domainPlan('.com');

        $this->domain('.com', ['auto_renew' => false]);
        $this->domain('.com', ['expiry_date' => now()->addDays(30)->toDateString()]);
        $this->domain('.xyz'); // no plan for this TLD

        $this->artisan('invoices:generate-domain-renewals')->assertExitCode(0);

        $this->assertSame(0, Invoice::count());
    }

    public function test_skips_when_plan_has_no_active_price_tier(): void
    {
        // Edge: the plan's only tier was deactivated. The command skips +
        // reports rather than billing nothing.
        $plan = $this->domainPlan('.gr');
        $plan->prices()->update(['is_active' => false]);
        $this->domain('.gr');

        $this->artisan('invoices:generate-domain-renewals')->assertExitCode(0);

        $this->assertSame(0, Invoice::count());
    }

    public function test_subscription_cron_does_not_pick_up_domains(): void
    {
        $this->domainPlan('.com');
        $this->domain('.com');

        $this->artisan('invoices:generate-subscriptions')->assertExitCode(0);

        $this->assertSame(0, Invoice::count());
    }
}
