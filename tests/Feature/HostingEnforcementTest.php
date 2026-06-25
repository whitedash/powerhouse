<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Domain;
use App\Models\User;
use App\Models\Website;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Section-enforcement sprint, step 6 — hosting (access + manage).
 *
 * Domain READS (index/whois/dns) now require permission:hosting.access; domain
 * and website WRITES require permission:hosting.manage (route middleware). The
 * four domain writes were previously gated only by the in-method customers.access
 * (viewAny) — a WRITE behind a READ perm; hosting.manage closes that. Website
 * writes had no section gate at all, only the per-item CustomerPolicy::update.
 *
 * Composition (both layers must pass) is preserved:
 *  - domains: hosting.* (route) + customers.access (in-method viewAny).
 *  - websites: hosting.manage (route) + customers.manage on the website's
 *    customer (in-method Gate::authorize('update', $website->customer) — IDOR).
 * super_admin bypasses via Gate::before. wordpress.bulk_update is untouched.
 */
class HostingEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** @param array<int,string> $permissions */
    private function userWith(array $permissions): User
    {
        $role = Role::create(['name' => 'host_'.uniqid(), 'guard_name' => 'web']);
        if ($permissions !== []) {
            $role->givePermissionTo($permissions);
        }
        $user = User::factory()->create(); // role = staff (not super_admin)
        $user->syncRoles([$role->name]);

        return $user->fresh();
    }

    private function bareSuperAdmin(): User
    {
        $admin = User::factory()->superAdmin()->create();
        $admin->syncRoles([]);
        $admin->syncPermissions([]);

        return $admin;
    }

    private function makeCustomer(): Customer
    {
        return Customer::create(['name' => 'Acme '.uniqid()]);
    }

    private function makeDomain(Customer $customer): Domain
    {
        return Domain::create([
            'customer_id' => $customer->id,
            'domain' => 'd'.uniqid().'.com',
            'status' => 'active',
            'ssl_status' => 'none',
        ]);
    }

    private function makeWebsite(Customer $customer): Website
    {
        return Website::create([
            'customer_id' => $customer->id,
            'name' => 'Site '.uniqid(),
            'url' => 'https://'.uniqid().'.test',
            'hosting_status' => 'none',
            'created_by' => User::factory()->create()->id,
        ]);
    }

    // ─── Domain READS → hosting.access ───

    public function test_domain_index_403_without_hosting_access(): void
    {
        // Holds the OLD gate (customers.access) but not hosting.access.
        $user = $this->userWith(['customers.access']);
        $this->actingAs($user)->get('/domains')->assertForbidden();
    }

    public function test_domain_dns_403_without_hosting_access(): void
    {
        $user = $this->userWith(['customers.access']);
        $domain = $this->makeDomain($this->makeCustomer());
        $this->actingAs($user)->getJson("/domains/{$domain->id}/dns")->assertForbidden();
    }

    public function test_domain_read_succeeds_with_hosting_access(): void
    {
        $user = $this->userWith(['hosting.access', 'customers.access']);
        $domain = $this->makeDomain($this->makeCustomer());
        // dnsRecords short-circuits to JSON when no cloudflare zone — no external call.
        $this->actingAs($user)->getJson("/domains/{$domain->id}/dns")
            ->assertOk()->assertJson(['connected' => false]);
    }

    // ─── Domain WRITES → hosting.manage (the write-behind-read fix) ───

    public function test_domain_writes_403_without_hosting_manage(): void
    {
        // hosting.access + customers.access (the OLD read gate) but NOT hosting.manage.
        // Previously customers.access alone let these writes through.
        $user = $this->userWith(['hosting.access', 'customers.access']);
        foreach ([['post', '/domains'], ['put', '/domains/1'], ['delete', '/domains/1'], ['post', '/domains/1/check']] as [$verb, $url]) {
            $this->actingAs($user)->{$verb}($url)->assertForbidden();
        }
    }

    public function test_domain_store_succeeds_with_hosting_manage(): void
    {
        $user = $this->userWith(['hosting.manage', 'hosting.access', 'customers.access']);
        $customer = $this->makeCustomer();

        $this->actingAs($user)->post('/domains', [
            'customer_id' => $customer->id,
            'domain' => 'verifyhost'.uniqid().'.com',
        ])->assertRedirect();

        $this->assertDatabaseHas('domains', ['customer_id' => $customer->id]);
    }

    public function test_domain_write_composition_blocked_without_customers_access(): void
    {
        // hosting.manage passes the route middleware, but the in-method
        // Gate::authorize('viewAny', Customer) (customers.access) still 403s.
        $user = $this->userWith(['hosting.manage', 'hosting.access']); // no customers.access
        $customer = $this->makeCustomer();

        $this->actingAs($user)->post('/domains', [
            'customer_id' => $customer->id,
            'domain' => 'blocked'.uniqid().'.com',
        ])->assertForbidden();

        $this->assertDatabaseMissing('domains', ['customer_id' => $customer->id]);
    }

    // ─── Website WRITES → hosting.manage + per-item customers.manage ───

    public function test_website_writes_403_without_hosting_manage(): void
    {
        // customers.manage (would satisfy the per-item gate) but NOT hosting.manage
        // → the new route gate blocks before the controller runs.
        $user = $this->userWith(['customers.manage', 'customers.access']);
        $routes = [
            ['post', '/websites'],
            ['put', '/websites/1'],
            ['delete', '/websites/1'],
            ['post', '/websites/1/sync-hosting'],
            ['post', '/websites/1/check-pagespeed'],
            ['post', '/websites/1/sync-wordpress'],
            ['post', '/websites/1/suspend-hosting'],
            ['post', '/websites/1/reinstate-hosting'],
        ];
        foreach ($routes as [$verb, $url]) {
            $this->actingAs($user)->{$verb}($url)->assertForbidden();
        }
    }

    public function test_website_store_succeeds_with_hosting_manage_and_customers_manage(): void
    {
        $user = $this->userWith(['hosting.manage', 'customers.manage']);
        $customer = $this->makeCustomer();

        $this->actingAs($user)->post('/websites', [
            'customer_id' => $customer->id,
            'name' => 'Verify Site',
            'url' => 'https://verify-site.test',
        ])->assertRedirect();

        $this->assertDatabaseHas('websites', ['customer_id' => $customer->id, 'name' => 'Verify Site']);
    }

    public function test_website_composition_blocked_without_customers_manage(): void
    {
        // Holds hosting.manage (passes the route gate) but NOT customers.manage →
        // the per-item Gate::authorize('update', $website->customer) IDOR guard 403s.
        $user = $this->userWith(['hosting.manage', 'customers.access']);
        $website = $this->makeWebsite($this->makeCustomer());

        $this->actingAs($user)->put("/websites/{$website->id}", [
            'customer_id' => $website->customer_id,
            'name' => 'Hacked',
            'url' => 'https://hacked.test',
        ])->assertForbidden();

        $this->assertDatabaseMissing('websites', ['name' => 'Hacked']);
    }

    // ─── super_admin bypass + wordpress unchanged ───

    public function test_superadmin_bypasses_via_gate_before(): void
    {
        $admin = $this->bareSuperAdmin();
        $customer = $this->makeCustomer();

        $this->actingAs($admin)->post('/domains', [
            'customer_id' => $customer->id,
            'domain' => 'admindomain'.uniqid().'.com',
        ])->assertRedirect();

        $this->actingAs($admin)->post('/websites', [
            'customer_id' => $customer->id,
            'name' => 'Admin Site',
            'url' => 'https://admin-site.test',
        ])->assertRedirect();
    }

    public function test_wordpress_bulk_update_still_enforced_and_unaffected(): void
    {
        // hosting.* does NOT grant wordpress.bulk_update — the WP page stays blocked.
        $hostingUser = $this->userWith(['hosting.manage', 'hosting.access', 'customers.manage', 'customers.access']);
        $this->actingAs($hostingUser)->get('/wordpress/updates')->assertForbidden();

        // super_admin (who holds it via Gate::before) still reaches it — unchanged.
        $this->assertNotSame(403, $this->actingAs($this->bareSuperAdmin())->get('/wordpress/updates')->getStatusCode());
    }
}
