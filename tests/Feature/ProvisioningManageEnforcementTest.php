<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CustomerProduct;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Section-enforcement sprint, step 1 — provisioning.manage.
 *
 * Every provisioning mutation (toggle, subscription update/cancel, customer-
 * product suspend/reinstate/update, and the enableProduct/suspendProduct
 * side-doors on CompanyController) now requires provisioning.manage via route
 * middleware, COMPOSING with the existing per-customer companies.manage check.
 * These guards prove BOTH are required (and super_admin bypasses via Gate::before).
 */
class ProvisioningManageEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** Staff-enum user holding exactly $permissions (custom Spatie role replaces the auto-assigned staff role). */
    private function userWith(array $permissions): User
    {
        $role = Role::create(['name' => 'prov_'.uniqid(), 'guard_name' => 'web']);
        if ($permissions !== []) {
            $role->givePermissionTo($permissions);
        }
        $user = User::factory()->create();
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

    private function activeCustomerProduct(): CustomerProduct
    {
        $customer = Company::create(['name' => 'Acme '.uniqid()]);
        $product = Product::create(['name' => 'Service '.uniqid(), 'slug' => 'svc-'.uniqid()]);

        return CustomerProduct::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'status' => 'active',
            'started_at' => now(),
        ]);
    }

    /** The 8 internal provisioning-mutation routes, [verb, url]. */
    private function mutationRoutes(): array
    {
        return [
            ['post', '/provisioning/toggle'],
            ['put', '/subscriptions/1'],
            ['post', '/subscriptions/1/cancel'],
            ['post', '/customer-products/1/suspend'],
            ['post', '/customer-products/1/reinstate'],
            ['put', '/customer-products/1'],
            ['post', '/companies/1/products'],                 // enableProduct side-door
            ['post', '/companies/1/products/1/suspend'],       // suspendProduct side-door
        ];
    }

    public function test_all_provisioning_mutations_403_without_provisioning_manage(): void
    {
        // Has companies.manage (the old, too-permissive gate) but NOT provisioning.manage.
        $user = $this->userWith(['companies.access', 'companies.manage']);

        foreach ($this->mutationRoutes() as [$verb, $url]) {
            $this->actingAs($user)->{$verb}($url)
                ->assertForbidden(); // route middleware blocks before the controller
        }
    }

    public function test_customer_product_suspend_succeeds_with_both(): void
    {
        $user = $this->userWith(['companies.access', 'companies.manage', 'provisioning.manage']);
        $cp = $this->activeCustomerProduct();

        $this->actingAs($user)
            ->post("/customer-products/{$cp->id}/suspend", ['reason' => 'manual'])
            ->assertRedirect();

        $this->assertSame('suspended', $cp->fresh()->status);
    }

    public function test_provisioning_toggle_succeeds_with_both(): void
    {
        $user = $this->userWith(['companies.access', 'companies.manage', 'provisioning.manage']);
        $customer = Company::create(['name' => 'Acme '.uniqid()]);
        $product = Product::create(['name' => 'Service '.uniqid(), 'slug' => 'svc-'.uniqid()]);

        // Past provisioning.manage (middleware) + companies.manage (in-method):
        // the request reaches the toggle body (not a 403). Its full create logic
        // is the toggle feature's own concern; here we prove authz composes.
        $resp = $this->actingAs($user)
            ->post('/provisioning/toggle', ['customer_id' => $customer->id, 'product_id' => $product->id]);
        $this->assertNotSame(403, $resp->getStatusCode(), 'toggle must pass authz for a both-permission user');
    }

    public function test_enable_product_side_door_succeeds_with_both(): void
    {
        $user = $this->userWith(['companies.access', 'companies.manage', 'provisioning.manage']);
        $customer = Company::create(['name' => 'Acme '.uniqid()]);
        $product = Product::create(['name' => 'Service '.uniqid(), 'slug' => 'svc-'.uniqid()]);

        // The enableProduct side-door: past both gates it should NOT 403.
        $resp = $this->actingAs($user)->post("/companies/{$customer->id}/products", [
            'product_id' => $product->id,
        ]);
        $this->assertNotSame(403, $resp->getStatusCode(), 'enableProduct must pass authz for a both-permission user');
    }

    public function test_suspend_superadmin_bypasses_via_gate_before(): void
    {
        $cp = $this->activeCustomerProduct();

        $this->actingAs($this->bareSuperAdmin())
            ->post("/customer-products/{$cp->id}/suspend", ['reason' => 'manual'])
            ->assertRedirect();

        $this->assertSame('suspended', $cp->fresh()->status);
    }

    public function test_composition_provisioning_manage_without_customers_manage_is_blocked(): void
    {
        // Holds provisioning.manage (passes the route middleware) but NOT
        // companies.manage → the in-method Gate::authorize('update', $customer)
        // must still 403. Proves BOTH checks are required (composition).
        $user = $this->userWith(['provisioning.manage']);
        $cp = $this->activeCustomerProduct();

        $this->actingAs($user)
            ->post("/customer-products/{$cp->id}/suspend", ['reason' => 'manual'])
            ->assertForbidden();
        $this->assertSame('active', $cp->fresh()->status); // unchanged

        // Same composition on a second action (subscription update) for confidence.
        $this->actingAs($user)
            ->put("/customer-products/{$cp->id}", ['label' => 'x'])
            ->assertForbidden();
    }
}
