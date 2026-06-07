<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProduct;
use App\Models\Domain;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\ProductPlanPrice;
use App\Models\User;
use App\Models\Website;
use App\Support\RecurringRevenue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 2: the canonical recurring-revenue aggregator. Restores a WHOLE MRR
 * from the three post-1a/1b sources (services + hosting + domains).
 */
class RecurringRevenueTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'super_admin']);
    }

    /** Build a plan + one active price tier, flagging the kind. */
    private function planPrice(float $price, string $unit, string $kind): ProductPlanPrice
    {
        $product = Product::create(['slug' => $kind.'-'.uniqid(), 'name' => ucfirst($kind), 'is_active' => true]);
        $plan = ProductPlan::create([
            'product_id' => $product->id,
            'name' => ucfirst($kind).' plan',
            'is_active' => true,
            'is_public' => true,
            'is_hosting' => $kind === 'hosting',
            'is_domain' => $kind === 'domain',
            'tld' => $kind === 'domain' ? '.com' : null,
        ]);

        return ProductPlanPrice::create([
            'plan_id' => $plan->id,
            'price' => $price,
            'interval_count' => 1,
            'interval_unit' => $unit,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function serviceCp(Customer $c, ProductPlanPrice $tier, string $status = 'active'): CustomerProduct
    {
        return CustomerProduct::create([
            'customer_id' => $c->id,
            'product_id' => $tier->plan->product_id,
            'plan_id' => $tier->plan_id,
            'plan_price_id' => $tier->id,
            'status' => $status,
            'started_at' => now(),
        ]);
    }

    private function hostingSite(Customer $c, ProductPlanPrice $tier, string $hostingStatus = 'active'): Website
    {
        return Website::create([
            'customer_id' => $c->id,
            'name' => 'Site '.uniqid(),
            'url' => 'https://'.uniqid().'.test',
            'status' => 'active',
            'created_by' => $this->user->id,
            'plan_id' => $tier->plan_id,
            'plan_price_id' => $tier->id,
            'hosting_status' => $hostingStatus,
            'hosting_started_at' => now(),
        ]);
    }

    private function domain(Customer $c, ProductPlan $plan, bool $autoRenew = true): Domain
    {
        return Domain::create([
            'customer_id' => $c->id,
            'domain' => 'd-'.uniqid().'.com',
            'status' => 'active',
            'ssl_status' => 'none',
            'auto_renew' => $autoRenew,
            'tld' => '.com',
            'product_plan_id' => $plan->id,
        ]);
    }

    public function test_total_is_the_sum_of_services_hosting_and_domains(): void
    {
        $c = Customer::create(['name' => 'Acme']);
        $this->serviceCp($c, $this->planPrice(30.00, 'month', 'service'));   // £30
        $this->hostingSite($c, $this->planPrice(20.00, 'month', 'hosting')); // £20
        $domTier = $this->planPrice(120.00, 'year', 'domain');              // £120/yr → £10/mo
        $this->domain($c, $domTier->plan);

        $rr = RecurringRevenue::compute();

        $this->assertEqualsWithDelta(30.00, $rr->bySource['services'], 0.001);
        $this->assertEqualsWithDelta(20.00, $rr->bySource['hosting'], 0.001);
        $this->assertEqualsWithDelta(10.00, $rr->bySource['domains'], 0.001); // annual ÷12
        $this->assertEqualsWithDelta(60.00, $rr->total, 0.001);
        $this->assertEqualsWithDelta(720.00, $rr->arr(), 0.001);
    }

    public function test_per_customer_sums_all_three_sources_for_one_customer(): void
    {
        $c = Customer::create(['name' => 'Acme']);
        $this->serviceCp($c, $this->planPrice(30.00, 'month', 'service'));
        $this->hostingSite($c, $this->planPrice(20.00, 'month', 'hosting'));
        $this->domain($c, $this->planPrice(120.00, 'year', 'domain')->plan);

        $rr = RecurringRevenue::compute();

        $this->assertEqualsWithDelta(60.00, $rr->forCustomer($c->id), 0.001);
    }

    public function test_suspended_hosting_and_auto_renew_off_domain_are_excluded(): void
    {
        $c = Customer::create(['name' => 'Acme']);
        $this->hostingSite($c, $this->planPrice(20.00, 'month', 'hosting'), hostingStatus: 'suspended');
        $this->domain($c, $this->planPrice(120.00, 'year', 'domain')->plan, autoRenew: false);

        $rr = RecurringRevenue::compute();

        $this->assertEqualsWithDelta(0.00, $rr->total, 0.001);
    }

    public function test_cancelled_and_suspended_customer_products_are_excluded(): void
    {
        $c = Customer::create(['name' => 'Acme']);
        $tier = $this->planPrice(30.00, 'month', 'service');
        $this->serviceCp($c, $tier, status: 'cancelled');
        $this->serviceCp($c, $tier, status: 'suspended');

        $this->assertEqualsWithDelta(0.00, RecurringRevenue::compute()->total, 0.001);
    }

    public function test_a_stray_hosting_or_domain_customer_product_is_not_double_counted(): void
    {
        $c = Customer::create(['name' => 'Acme']);
        // A real hosting website (£20) + a STRAY active CP whose plan is
        // is_hosting — the stray must NOT add to MRR.
        $hostTier = $this->planPrice(20.00, 'month', 'hosting');
        $this->hostingSite($c, $hostTier);
        $this->serviceCp($c, $hostTier); // stray is_hosting CP

        $domStrayTier = $this->planPrice(120.00, 'year', 'domain');
        $this->serviceCp($c, $domStrayTier); // stray is_domain CP

        $rr = RecurringRevenue::compute();

        // Only the hosting website counts; both stray CPs are excluded.
        $this->assertEqualsWithDelta(20.00, $rr->total, 0.001);
        $this->assertEqualsWithDelta(0.00, $rr->bySource['services'], 0.001);
    }

    public function test_per_plan_and_per_product_breakdowns_are_correct(): void
    {
        $c = Customer::create(['name' => 'Acme']);
        $svc = $this->planPrice(30.00, 'month', 'service');
        $host = $this->planPrice(20.00, 'month', 'hosting');
        $this->serviceCp($c, $svc);
        $this->serviceCp($c, $svc); // two of the same service plan
        $this->hostingSite($c, $host);

        $rr = RecurringRevenue::compute();

        $this->assertEqualsWithDelta(60.00, $rr->planMonthly($svc->plan_id), 0.001);
        $this->assertSame(2, $rr->planCount($svc->plan_id));
        $this->assertEqualsWithDelta(20.00, $rr->planMonthly($host->plan_id), 0.001);
        $this->assertSame(1, $rr->planCount($host->plan_id));

        $this->assertEqualsWithDelta(60.00, $rr->productMonthly($svc->plan->product_id), 0.001);
        $this->assertEqualsWithDelta(20.00, $rr->productMonthly($host->plan->product_id), 0.001);
        $this->assertSame(1, $rr->productCount($host->plan->product_id));
    }
}
