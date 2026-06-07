<?php

namespace Tests\Feature;

use App\Models\BillingEntity;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\ProductPlanPrice;
use App\Models\User;
use App\Models\Website;
use App\Services\WebhookDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

/**
 * Stage 1b: hosting suspend/reinstate lifecycle — flips hosting_status (which
 * gates billing) and fires the website-hosting webhook keyed on the website.
 */
class HostingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($this->user);
    }

    private function hostingWebsite(array $overrides = []): Website
    {
        $entity = BillingEntity::create([
            'name' => 'WD', 'legal_name' => 'Whitedash Ltd',
            'postmark_sender_email' => 'b@wd.test', 'postmark_sender_name' => 'WD',
        ]);
        $product = Product::create(['slug' => 'host-'.uniqid(), 'name' => 'Hosting', 'billing_entity_id' => $entity->id, 'is_active' => true]);
        $plan = ProductPlan::create(['product_id' => $product->id, 'name' => 'Pro', 'is_active' => true, 'is_public' => true, 'is_hosting' => true]);
        $price = ProductPlanPrice::create(['plan_id' => $plan->id, 'price' => 20.00, 'interval_count' => 1, 'interval_unit' => 'month', 'is_default' => true, 'is_active' => true, 'sort_order' => 0]);
        $customer = Customer::create(['name' => 'Acme '.uniqid()]);

        return Website::create(array_merge([
            'customer_id' => $customer->id,
            'name' => 'Site',
            'url' => 'https://acme-'.uniqid().'.test',
            'status' => 'active',
            'created_by' => $this->user->id,
            'plan_id' => $plan->id,
            'plan_price_id' => $price->id,
            'hosting_status' => 'active',
            'hosting_started_at' => now(),
            'hosting_auto_invoice' => true,
            'hosting_next_billing_date' => Carbon::today()->toDateString(),
        ], $overrides));
    }

    public function test_suspend_flips_status_and_fires_the_website_webhook(): void
    {
        $website = $this->hostingWebsite();

        $this->mock(WebhookDispatcher::class, function ($m) {
            $m->shouldReceive('dispatchHostingSuspension')->once();
        });

        $this->post("/websites/{$website->id}/suspend-hosting")->assertSessionHasNoErrors();

        $this->assertSame('suspended', $website->fresh()->hosting_status);
    }

    public function test_reinstate_flips_status_and_fires_the_website_webhook(): void
    {
        $website = $this->hostingWebsite(['hosting_status' => 'suspended']);

        $this->mock(WebhookDispatcher::class, function ($m) {
            $m->shouldReceive('dispatchHostingReinstatement')->once();
        });

        $this->post("/websites/{$website->id}/reinstate-hosting")->assertSessionHasNoErrors();

        $this->assertSame('active', $website->fresh()->hosting_status);
    }

    public function test_suspend_excludes_from_billing_then_reinstate_re_includes(): void
    {
        // Avoid real webhook/WHM side-effects on the lifecycle flips.
        $this->mock(WebhookDispatcher::class, function ($m) {
            $m->shouldReceive('dispatchHostingSuspension')->zeroOrMoreTimes();
            $m->shouldReceive('dispatchHostingReinstatement')->zeroOrMoreTimes();
        });

        $website = $this->hostingWebsite();

        // Suspend → the sweep must skip it.
        $this->post("/websites/{$website->id}/suspend-hosting")->assertSessionHasNoErrors();
        $this->artisan('invoices:generate-hosting')->assertExitCode(0);
        $this->assertSame(0, Invoice::count());

        // Reinstate → due again, the sweep bills it.
        $this->post("/websites/{$website->id}/reinstate-hosting")->assertSessionHasNoErrors();
        $this->artisan('invoices:generate-hosting')->assertExitCode(0);
        $this->assertSame(1, Invoice::count());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
