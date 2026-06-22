<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3a: proves the enum→permission enforcement swap.
 *
 *  - super_admin bypasses every gate via Gate::before (independent of held
 *    permissions);
 *  - the staff role (via its seeded permissions) reaches its surface and is
 *    denied the super-admin-only actions;
 *  - a custom role can do exactly what its permissions allow and no more;
 *  - the four scoped areas still behave as today (NO scope filtering — 3b).
 */
class RoleEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_super_admin_bypasses_policies_via_gate_before_even_with_no_permissions(): void
    {
        $admin = User::factory()->superAdmin()->create();
        // Strip every role/permission — the bypass must NOT depend on them.
        $admin->syncRoles([]);
        $admin->syncPermissions([]);

        $this->assertTrue(Gate::forUser($admin)->allows('delete', Customer::class));
        $this->assertTrue(Gate::forUser($admin)->allows('void', Invoice::class));
        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', Customer::class));
    }

    public function test_super_admin_reaches_a_super_admin_only_route(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->get('/settings/team')->assertOk();
    }

    public function test_staff_reaches_its_surface(): void
    {
        $staff = User::factory()->create(); // enum staff → staff Spatie role

        $this->actingAs($staff)->get('/customers')->assertOk();
        $this->actingAs($staff)->get('/invoices')->assertOk();
        $this->assertTrue(Gate::forUser($staff)->allows('viewAny', Customer::class));
    }

    public function test_staff_is_denied_super_admin_only_routes_and_actions(): void
    {
        $staff = User::factory()->create();

        // Route-gated super-admin areas → 403 (staff lacks the permission).
        $this->actingAs($staff)->get('/settings/team')->assertForbidden();
        $this->actingAs($staff)->get('/settings/deployment')->assertForbidden();

        // Policy-gated super-admin actions → denied.
        $this->assertFalse(Gate::forUser($staff)->allows('void', Invoice::class));
        $this->assertFalse(Gate::forUser($staff)->allows('delete', Customer::class));
    }

    public function test_custom_role_is_allowed_its_held_permission_route_and_denied_others(): void
    {
        // Custom role holding ONLY staff.manage (enum stays staff for the
        // outer operator gate; Spatie role replaced with the custom one).
        $role = Role::create(['name' => 'Team Admin', 'guard_name' => 'web']);
        $role->givePermissionTo('staff.manage');

        $user = User::factory()->create();
        $user->syncRoles(['Team Admin']);

        // Holds staff.manage → reaches /settings/team…
        $this->actingAs($user)->get('/settings/team')->assertOk();
        // …but not deployment (lacks deployment.run).
        $this->actingAs($user)->get('/settings/deployment')->assertForbidden();
    }

    public function test_custom_role_void_permission_gates_the_void_policy(): void
    {
        $canVoid = Role::create(['name' => 'Voider', 'guard_name' => 'web']);
        $canVoid->givePermissionTo(['invoices.access', 'invoices.void']);
        $voider = User::factory()->create();
        $voider->syncRoles(['Voider']);

        $noVoid = Role::create(['name' => 'Viewer', 'guard_name' => 'web']);
        $noVoid->givePermissionTo('invoices.access');
        $viewer = User::factory()->create();
        $viewer->syncRoles(['Viewer']);

        $this->assertTrue(Gate::forUser($voider)->allows('void', Invoice::class));
        $this->assertFalse(Gate::forUser($viewer)->allows('void', Invoice::class));
        // Both can view (both hold invoices.access).
        $this->assertTrue(Gate::forUser($voider)->allows('viewAny', Invoice::class));
        $this->assertTrue(Gate::forUser($viewer)->allows('viewAny', Invoice::class));
    }

    public function test_scoped_areas_are_not_filtered_yet_staff_sees_an_unassigned_project(): void
    {
        // A project the staff user is NOT a member of. Phase 3b would filter
        // this under an Assigned scope; phase 3a adds NO scope filtering, so
        // it must remain reachable exactly as today.
        $owner = User::factory()->create();
        $project = Project::create([
            'title' => 'Unassigned project '.uniqid(),
            'created_by' => $owner->id,
        ]);

        $staff = User::factory()->create();

        $this->actingAs($staff)->get("/projects/{$project->id}")->assertOk();
    }

    public function test_permission_catalogue_is_seeded(): void
    {
        // Guards the bridge: all 55 boolean permissions exist on the web guard.
        $this->assertSame(55, Permission::where('guard_name', 'web')->count());
    }
}
