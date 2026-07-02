<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CustomerProduct;
use App\Models\Domain;
use App\Models\Product;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integrity guard: a website's domain / hosting plan / project links must all
 * belong to the SAME customer as the website. Covers store + update; null
 * links stay allowed. Mirrors guardContactBelongsToCustomer's error style.
 */
class WebsiteCustomerConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($this->user);
    }

    private function customer(): Company
    {
        return Company::create(['name' => 'Acme '.uniqid()]);
    }

    private function domainFor(Company $customer): Domain
    {
        return Domain::create([
            'customer_id' => $customer->id,
            'domain' => 'd-'.uniqid().'.com',
            'status' => 'active',
            'ssl_status' => 'none',
        ]);
    }

    private function hostingPlanFor(Company $customer): CustomerProduct
    {
        $product = Product::create(['slug' => 'p-'.uniqid(), 'name' => 'Hosting']);

        return CustomerProduct::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'status' => 'active',
        ]);
    }

    private function projectFor(Company $customer): Project
    {
        return Project::create([
            'customer_id' => $customer->id,
            'title' => 'Site rebuild',
            'created_by' => $this->user->id,
        ]);
    }

    private function websiteFor(Company $customer, array $overrides = []): Website
    {
        return Website::create(array_merge([
            'customer_id' => $customer->id,
            'name' => 'Existing site',
            'url' => 'https://existing.test',
            'status' => 'active',
            'created_by' => $this->user->id,
        ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Company $customer, array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $customer->id,
            'name' => 'New site',
            'url' => 'https://new.test',
        ], $overrides);
    }

    public function test_store_rejects_domain_owned_by_another_customer(): void
    {
        $owner = $this->customer();
        $other = $this->customer();
        $domain = $this->domainFor($other);

        $res = $this->post('/websites', $this->payload($owner, ['domain_id' => $domain->id]));

        $res->assertSessionHasErrors(['domain_id']);
        $this->assertSame(0, Website::count());
    }

    public function test_store_rejects_hosting_plan_owned_by_another_customer(): void
    {
        $owner = $this->customer();
        $other = $this->customer();
        $plan = $this->hostingPlanFor($other);

        $res = $this->post('/websites', $this->payload($owner, ['customer_product_id' => $plan->id]));

        $res->assertSessionHasErrors(['customer_product_id']);
        $this->assertSame(0, Website::count());
    }

    public function test_store_rejects_project_owned_by_another_customer(): void
    {
        $owner = $this->customer();
        $other = $this->customer();
        $project = $this->projectFor($other);

        $res = $this->post('/websites', $this->payload($owner, ['project_id' => $project->id]));

        $res->assertSessionHasErrors(['project_id']);
        $this->assertSame(0, Website::count());
    }

    public function test_store_accepts_same_customer_links(): void
    {
        $owner = $this->customer();
        $domain = $this->domainFor($owner);
        $plan = $this->hostingPlanFor($owner);
        $project = $this->projectFor($owner);

        $res = $this->post('/websites', $this->payload($owner, [
            'domain_id' => $domain->id,
            'customer_product_id' => $plan->id,
            'project_id' => $project->id,
        ]));

        $res->assertSessionHasNoErrors();
        $this->assertDatabaseHas('websites', [
            'customer_id' => $owner->id,
            'domain_id' => $domain->id,
            'customer_product_id' => $plan->id,
            'project_id' => $project->id,
        ]);
    }

    public function test_store_accepts_null_links(): void
    {
        $owner = $this->customer();

        $res = $this->post('/websites', $this->payload($owner, [
            'domain_id' => null,
            'customer_product_id' => null,
            'project_id' => null,
        ]));

        $res->assertSessionHasNoErrors();
        $this->assertDatabaseHas('websites', [
            'customer_id' => $owner->id,
            'name' => 'New site',
            'domain_id' => null,
        ]);
    }

    public function test_update_rejects_link_owned_by_another_customer(): void
    {
        $owner = $this->customer();
        $other = $this->customer();
        $website = $this->websiteFor($owner);
        $domain = $this->domainFor($other);

        $res = $this->put("/websites/{$website->id}", $this->payload($owner, [
            'domain_id' => $domain->id,
        ]));

        $res->assertSessionHasErrors(['domain_id']);
        $this->assertNull($website->fresh()->domain_id);
    }

    public function test_update_accepts_same_customer_link(): void
    {
        $owner = $this->customer();
        $website = $this->websiteFor($owner);
        $domain = $this->domainFor($owner);

        $res = $this->put("/websites/{$website->id}", $this->payload($owner, [
            'domain_id' => $domain->id,
        ]));

        $res->assertSessionHasNoErrors();
        $this->assertSame($domain->id, $website->fresh()->domain_id);
    }
}
