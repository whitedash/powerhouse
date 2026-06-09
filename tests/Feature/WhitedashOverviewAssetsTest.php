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
 * The Whitedash product overview must fold hosting + domains (asset sources)
 * into its active-customers count + MRR, not just its service CPs. Other
 * products' overviews stay service-only.
 */
class WhitedashOverviewAssetsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'super_admin']);
    }

    private function plan(Product $product, string $kind, float $price): ProductPlanPrice
    {
        $plan = ProductPlan::create([
            'product_id' => $product->id,
            'name' => ucfirst($kind).' plan',
            'is_active' => true,
            'is_public' => true,
            'is_hosting' => $kind === 'hosting',
            'is_domain' => $kind === 'domain',
            'tld' => $kind === 'domain' ? '.co.uk' : null,
        ]);

        return ProductPlanPrice::create([
            'plan_id' => $plan->id,
            'price' => $price,
            'interval_count' => 1,
            'interval_unit' => 'month',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function hostingSite(Customer $c, ProductPlanPrice $tier): Website
    {
        return Website::create([
            'customer_id' => $c->id,
            'name' => 'Site '.uniqid(),
            'url' => 'https://'.uniqid().'.test',
            'status' => 'active',
            'created_by' => $this->user->id,
            'plan_id' => $tier->plan_id,
            'plan_price_id' => $tier->id,
            'hosting_status' => 'active',
            'hosting_started_at' => now(),
        ]);
    }

    private function domain(Customer $c, ProductPlan $plan): Domain
    {
        return Domain::create([
            'customer_id' => $c->id,
            'domain' => 'd-'.uniqid().'.co.uk',
            'status' => 'active',
            'ssl_status' => 'none',
            'auto_renew' => true,
            'tld' => '.co.uk',
            'product_plan_id' => $plan->id,
        ]);
    }

    public function test_whitedash_overview_active_customers_and_mrr_include_hosting_and_domains(): void
    {
        $wd = Product::create(['slug' => 'whitedash', 'name' => 'Whitedash', 'is_active' => true]);
        $domainsProduct = Product::create(['slug' => 'domains-cat', 'name' => 'Domains', 'is_active' => true]);

        $serviceTier = $this->plan($wd, 'service', 30.0);            // Whitedash service CP
        $hostingTier = $this->plan($wd, 'hosting', 10.0);            // hosting plan under Whitedash
        $domainTier = $this->plan($domainsProduct, 'domain', 12.0);  // domain plan under the Domains catalog product

        // C1: Whitedash service CP only.
        $c1 = Customer::create(['name' => 'C1']);
        CustomerProduct::create([
            'customer_id' => $c1->id, 'product_id' => $wd->id,
            'plan_id' => $serviceTier->plan_id, 'plan_price_id' => $serviceTier->id,
            'status' => 'active', 'started_at' => now(),
        ]);

        // C2: hosting only.
        $c2 = Customer::create(['name' => 'C2']);
        $this->hostingSite($c2, $hostingTier);

        // C3: domain only.
        $c3 = Customer::create(['name' => 'C3']);
        $this->domain($c3, $domainTier->plan);

        // C4: hosting AND a service CP — must count once (distinct).
        $c4 = Customer::create(['name' => 'C4']);
        CustomerProduct::create([
            'customer_id' => $c4->id, 'product_id' => $wd->id,
            'plan_id' => $serviceTier->plan_id, 'plan_price_id' => $serviceTier->id,
            'status' => 'active', 'started_at' => now(),
        ]);
        $this->hostingSite($c4, $hostingTier);

        $expectedMrr = 30.0 + 30.0 + 10.0 + 10.0 + 12.0; // 2 services + 2 hosting + 1 domain = 92

        $this->actingAs($this->user)
            ->get('/products/whitedash')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('kpis.active_customers', 4)       // C1..C4 distinct
                ->where('kpis.mrr', fn ($v): bool => abs((float) $v - $expectedMrr) < 0.001)
                ->where('kpis.arr', fn ($v): bool => abs((float) $v - $expectedMrr * 12) < 0.001)
            );
    }

    public function test_mrr_reconciles_with_recurring_revenue_by_source(): void
    {
        $wd = Product::create(['slug' => 'whitedash', 'name' => 'Whitedash', 'is_active' => true]);
        $domainsProduct = Product::create(['slug' => 'domains-cat', 'name' => 'Domains', 'is_active' => true]);
        $serviceTier = $this->plan($wd, 'service', 30.0);
        $hostingTier = $this->plan($wd, 'hosting', 10.0);
        $domainTier = $this->plan($domainsProduct, 'domain', 12.0);

        $c1 = Customer::create(['name' => 'C1']);
        CustomerProduct::create([
            'customer_id' => $c1->id, 'product_id' => $wd->id,
            'plan_id' => $serviceTier->plan_id, 'plan_price_id' => $serviceTier->id,
            'status' => 'active', 'started_at' => now(),
        ]);
        $this->hostingSite(Customer::create(['name' => 'C2']), $hostingTier);
        $this->domain(Customer::create(['name' => 'C3']), $domainTier->plan);

        $rr = RecurringRevenue::compute();
        $expected = round($rr->serviceMonthly($wd->id) + $rr->bySource['hosting'] + $rr->bySource['domains'], 2);

        $this->actingAs($this->user)
            ->get('/products/whitedash')
            ->assertInertia(fn ($page) => $page->where('kpis.mrr', fn ($v): bool => abs((float) $v - $expected) < 0.001));

        // Sanity: the figure genuinely spans all three sources.
        $this->assertEqualsWithDelta(30.0, $rr->serviceMonthly($wd->id), 0.001);
        $this->assertEqualsWithDelta(10.0, $rr->bySource['hosting'], 0.001);
        $this->assertEqualsWithDelta(12.0, $rr->bySource['domains'], 0.001);
    }

    public function test_other_products_overview_stays_service_only(): void
    {
        // A non-Whitedash, non-provisioning product: its overview must ignore
        // hosting/domains. (Avoid the 'orderpad'/'myorderpad' slugs — those fire
        // MyOrderPad provisioning on CP create.)
        $other = Product::create(['slug' => 'reporting', 'name' => 'Reporting', 'is_active' => true]);
        $serviceTier = $this->plan($other, 'service', 25.0);

        $c1 = Customer::create(['name' => 'C1']);
        CustomerProduct::create([
            'customer_id' => $c1->id, 'product_id' => $other->id,
            'plan_id' => $serviceTier->plan_id, 'plan_price_id' => $serviceTier->id,
            'status' => 'active', 'started_at' => now(),
        ]);

        // Hosting + domain exist in the system but belong to Whitedash's book,
        // not this product — they must not leak into its overview.
        $wd = Product::create(['slug' => 'whitedash', 'name' => 'Whitedash', 'is_active' => true]);
        $this->hostingSite(Customer::create(['name' => 'H']), $this->plan($wd, 'hosting', 10.0));

        $this->actingAs($this->user)
            ->get('/products/reporting')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('kpis.active_customers', 1)   // just the one service CP
                ->where('kpis.mrr', fn ($v): bool => abs((float) $v - 25.0) < 0.001)
            );
    }
}
