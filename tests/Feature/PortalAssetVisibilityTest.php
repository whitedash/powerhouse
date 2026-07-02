<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CustomerProduct;
use App\Models\Domain;
use App\Models\PortalUser;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\ProductPlanPrice;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 4: customer portal asset visibility — read-only, strictly scoped to the
 * portal user's own customer, leaking NO internal fields.
 */
class PortalAssetVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create(['role' => 'super_admin']);
    }

    private function portalUser(Company $customer): PortalUser
    {
        return PortalUser::create([
            'customer_id' => $customer->id,
            'name' => 'Portal '.uniqid(),
            'email' => 'p'.uniqid().'@test.test',
            'password' => bcrypt('secret-pass-123'),
        ]);
    }

    private function hostingPlanPrice(): ProductPlanPrice
    {
        $product = Product::create(['slug' => 'host-'.uniqid(), 'name' => 'Hosting', 'is_active' => true]);
        $plan = ProductPlan::create(['product_id' => $product->id, 'name' => 'Pro', 'is_active' => true, 'is_public' => true, 'is_hosting' => true]);

        return ProductPlanPrice::create(['plan_id' => $plan->id, 'price' => 20.00, 'interval_count' => 1, 'interval_unit' => 'month', 'is_default' => true, 'is_active' => true, 'sort_order' => 0]);
    }

    private function website(Company $c, ProductPlanPrice $tier): Website
    {
        return Website::create([
            'customer_id' => $c->id,
            'name' => 'Site '.uniqid(),
            'url' => 'https://'.uniqid().'.test',
            'status' => 'active',
            'created_by' => $this->staff->id,
            'plan_id' => $tier->plan_id,
            'plan_price_id' => $tier->id,
            'hosting_status' => 'active',
            'hosting_started_at' => now(),
            // Internal fields that MUST NOT leak:
            'cpanel_username' => 'cpuser_secret',
            'cpanel_token' => 'tok_SECRET_VALUE',
            'cpanel_server' => 'whm.internal.test',
            'ga4_property_id' => 'G-INTERNAL123',
            'notes' => 'internal staff note',
        ]);
    }

    private function domain(Company $c): Domain
    {
        return Domain::create([
            'customer_id' => $c->id,
            'domain' => 'd-'.uniqid().'.com',
            'status' => 'active',
            'ssl_status' => 'active',
            'auto_renew' => true,
            'cloudflare_zone_id' => 'cf_zone_SECRET',
            'nameservers' => 'ns1.internal.test',
            'notes' => 'internal domain note',
        ]);
    }

    private function serviceCp(Company $c, string $slug = 'maavelus'): CustomerProduct
    {
        $product = Product::create(['slug' => $slug, 'name' => ucfirst($slug), 'is_active' => true]);
        $plan = ProductPlan::create(['product_id' => $product->id, 'name' => 'Standard', 'is_active' => true, 'is_public' => true]);
        $price = ProductPlanPrice::create(['plan_id' => $plan->id, 'price' => 49.00, 'interval_count' => 1, 'interval_unit' => 'month', 'is_default' => true, 'is_active' => true, 'sort_order' => 0]);

        return CustomerProduct::create([
            'customer_id' => $c->id,
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'plan_price_id' => $price->id,
            'status' => 'active',
            'started_at' => now(),
            // Internal provisioning fields that MUST NOT leak:
            'external_user_id' => 'ext-SECRET',
            'oauth_client_id' => 999,
            'wp_user_id' => 777,
            'config' => ['secret' => 'do-not-leak'],
        ]);
    }

    public function test_portal_user_sees_only_their_own_assets(): void
    {
        $mine = Company::create(['name' => 'Mine']);
        $other = Company::create(['name' => 'Other']);

        $myWebsite = $this->website($mine, $this->hostingPlanPrice());
        $myDomain = $this->domain($mine);
        // Another customer's assets — must never appear.
        $this->website($other, $this->hostingPlanPrice());
        $this->domain($other);

        $this->actingAs($this->portalUser($mine), 'portal')
            ->get('/portal/assets')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/Assets')
                ->has('websites', 1)
                ->has('domains', 1)
                ->where('websites.0.id', $myWebsite->id)
                ->where('domains.0.id', $myDomain->id)
            );
    }

    public function test_payload_contains_no_internal_fields(): void
    {
        $mine = Company::create(['name' => 'Mine']);
        $this->website($mine, $this->hostingPlanPrice());
        $this->domain($mine);
        $this->serviceCp($mine);

        $res = $this->actingAs($this->portalUser($mine), 'portal')->get('/portal/assets');
        $json = json_encode($res->viewData('page')['props']);

        foreach ([
            'cpuser_secret', 'tok_SECRET_VALUE', 'whm.internal.test', 'G-INTERNAL123',
            'internal staff note', 'cf_zone_SECRET', 'ns1.internal.test', 'internal domain note',
            'ext-SECRET', 'do-not-leak',
            'cpanel_token', 'cpanel_username', 'oauth_client_id', 'external_user_id', 'wp_user_id',
            'billing_entity_id', 'cloudflare_zone_id',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $json, "Internal value/field leaked: {$needle}");
        }
    }

    public function test_assets_route_is_read_only(): void
    {
        $mine = Company::create(['name' => 'Mine']);

        // No customer-facing mutation route was added for assets.
        $this->actingAs($this->portalUser($mine), 'portal')
            ->post('/portal/assets')
            ->assertStatus(405);
    }

    public function test_saas_sso_launch_is_preserved_for_a_service(): void
    {
        $mine = Company::create(['name' => 'Mine']);
        $this->serviceCp($mine, 'maavelus'); // SSO-capable product

        $this->actingAs($this->portalUser($mine), 'portal')
            ->get('/portal/assets')
            ->assertInertia(fn ($page) => $page
                ->has('services', 1)
                ->where('services.0.sso_enabled', true)
                ->where('services.0.product_slug', 'maavelus')
            );
    }

    public function test_hosting_and_domain_plans_are_not_listed_as_services(): void
    {
        $mine = Company::create(['name' => 'Mine']);
        // A stray is_hosting CP must not appear under Services.
        $hostTier = $this->hostingPlanPrice();
        CustomerProduct::create([
            'customer_id' => $mine->id,
            'product_id' => $hostTier->plan->product_id,
            'plan_id' => $hostTier->plan_id,
            'plan_price_id' => $hostTier->id,
            'status' => 'active',
            'started_at' => now(),
        ]);

        $this->actingAs($this->portalUser($mine), 'portal')
            ->get('/portal/assets')
            ->assertInertia(fn ($page) => $page->has('services', 0));
    }
}
