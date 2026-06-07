<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProduct;
use App\Models\Domain;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Support\DomainCustomerProductConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 1a: the converter that removes domain-as-subscription. It must NEVER
 * delete an is_domain CustomerProduct without first ensuring its Domain exists.
 */
class DomainCustomerProductConverterTest extends TestCase
{
    use RefreshDatabase;

    private function domainPlan(string $tld): ProductPlan
    {
        $product = Product::create(['slug' => 'dom-'.uniqid(), 'name' => 'Domains']);

        return ProductPlan::create([
            'product_id' => $product->id,
            'name' => $tld.' renewal',
            'is_active' => true,
            'is_public' => true,
            'is_domain' => true,
            'tld' => $tld,
        ]);
    }

    private function domainCp(Customer $customer, ProductPlan $plan, ?string $label = null): CustomerProduct
    {
        return CustomerProduct::create([
            'customer_id' => $customer->id,
            'product_id' => $plan->product_id,
            'plan_id' => $plan->id,
            'label' => $label,
            'status' => 'active',
            'started_at' => now(),
        ]);
    }

    public function test_creates_a_domain_then_deletes_the_cp_when_none_exists(): void
    {
        $customer = Customer::create(['name' => 'WhiteDash']);
        $plan = $this->domainPlan('.co.uk');
        $this->domainCp($customer, $plan, 'whitedash.co.uk');

        $result = DomainCustomerProductConverter::run();

        // CP gone, exactly one Domain created carrying the asset.
        $this->assertSame(0, CustomerProduct::count());
        $this->assertSame(1, Domain::count());

        $domain = Domain::sole();
        $this->assertSame($customer->id, $domain->customer_id);
        $this->assertSame('.co.uk', $domain->tld);
        $this->assertSame($plan->id, $domain->product_plan_id);
        $this->assertTrue((bool) $domain->auto_renew);
        $this->assertSame(['converted' => 1, 'domains_created' => 1, 'domains_updated' => 0], $result);
    }

    public function test_backfills_an_existing_domain_then_deletes_the_cp(): void
    {
        $customer = Customer::create(['name' => 'WhiteDash']);
        $plan = $this->domainPlan('.co.uk');
        $this->domainCp($customer, $plan);

        // Pre-existing asset that can't self-bill yet (no tld/plan, auto off).
        $domain = Domain::create([
            'customer_id' => $customer->id,
            'domain' => 'whitedash.co.uk',
            'status' => 'active',
            'ssl_status' => 'none',
            'auto_renew' => false,
        ]);

        $result = DomainCustomerProductConverter::run();

        $this->assertSame(0, CustomerProduct::count());
        $this->assertSame(1, Domain::count()); // no duplicate created

        $fresh = $domain->fresh();
        $this->assertSame('.co.uk', $fresh->tld);
        $this->assertSame($plan->id, $fresh->product_plan_id);
        $this->assertTrue((bool) $fresh->auto_renew);
        $this->assertSame(['converted' => 1, 'domains_created' => 0, 'domains_updated' => 1], $result);
    }

    public function test_is_idempotent_and_a_no_op_without_domain_cps(): void
    {
        Customer::create(['name' => 'NoDomains']);

        $result = DomainCustomerProductConverter::run();

        $this->assertSame(['converted' => 0, 'domains_created' => 0, 'domains_updated' => 0], $result);
        $this->assertSame(0, Domain::count());
    }
}
