<?php

namespace Tests\Feature;

use App\Models\RoleScope;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->superAdmin()->create();
    }

    private function customRole(string $name = 'Support Agent'): Role
    {
        return Role::create(['name' => $name, 'guard_name' => 'web']);
    }

    public function test_screen_requires_super_admin(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $staff = User::factory()->create(); // enum role = staff

        $this->actingAs($staff)->get('/settings/roles')->assertForbidden();
    }

    public function test_index_lists_configurable_roles_excluding_super_admin(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/settings/roles')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Internal/Settings/Roles')
                ->has('groups')
                ->where('roles', fn ($roles) => collect($roles)->pluck('name')->contains('staff')
                    && ! collect($roles)->pluck('name')->contains('super_admin')));
    }

    public function test_create_blank_role_has_no_permissions_or_scopes(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/settings/roles', ['name' => 'Read Only', 'mode' => 'blank'])
            ->assertRedirect();

        $role = Role::where('name', 'Read Only')->where('guard_name', 'web')->firstOrFail();
        $this->assertCount(0, $role->permissions);
        $this->assertSame(0, RoleScope::where('role_id', $role->id)->count());
    }

    public function test_copy_from_duplicates_complete_state(): void
    {
        $admin = $this->admin();
        $staff = Role::findByName('staff', 'web');

        $this->actingAs($admin)->post('/settings/roles', [
            'name' => 'Staff Clone',
            'mode' => 'copy',
            'copy_from_id' => $staff->id,
        ])->assertRedirect();

        $clone = Role::where('name', 'Staff Clone')->where('guard_name', 'web')->firstOrFail();

        // Identical permission set...
        $this->assertEqualsCanonicalizing(
            $staff->permissions->pluck('name')->all(),
            $clone->permissions->pluck('name')->all(),
        );
        // ...and identical scopes (staff = All x4).
        $this->assertSame(
            RoleScope::where('role_id', $staff->id)->pluck('scope', 'area')->toArray(),
            RoleScope::where('role_id', $clone->id)->pluck('scope', 'area')->toArray(),
        );
        $this->assertSame(4, RoleScope::where('role_id', $clone->id)->count());
    }

    public function test_toggle_permission_grants_then_revokes(): void
    {
        $admin = $this->admin();
        $role = $this->customRole();

        $this->actingAs($admin)->put("/settings/roles/{$role->id}/permissions", [
            'permission' => 'invoices.void', 'granted' => true,
        ])->assertRedirect();
        $this->assertTrue($role->fresh()->hasPermissionTo('invoices.void'));

        $this->actingAs($admin)->put("/settings/roles/{$role->id}/permissions", [
            'permission' => 'invoices.void', 'granted' => false,
        ])->assertRedirect();
        $this->assertFalse($role->fresh()->hasPermissionTo('invoices.void'));
    }

    public function test_set_scope_upserts_one_row(): void
    {
        $admin = $this->admin();
        $role = $this->customRole();

        $this->actingAs($admin)->put("/settings/roles/{$role->id}/scope", ['area' => 'projects', 'scope' => 'assigned'])->assertRedirect();
        $this->actingAs($admin)->put("/settings/roles/{$role->id}/scope", ['area' => 'projects', 'scope' => 'all'])->assertRedirect();

        $rows = RoleScope::where('role_id', $role->id)->where('area', 'projects')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('all', $rows->first()->scope->value);
    }

    public function test_invalid_scope_and_area_are_rejected(): void
    {
        $admin = $this->admin();
        $role = $this->customRole();

        $this->actingAs($admin)->put("/settings/roles/{$role->id}/scope", ['area' => 'projects', 'scope' => 'everything'])
            ->assertSessionHasErrors('scope');
        $this->actingAs($admin)->put("/settings/roles/{$role->id}/scope", ['area' => 'galaxy', 'scope' => 'all'])
            ->assertSessionHasErrors('area');
        $this->assertSame(0, RoleScope::where('role_id', $role->id)->count());
    }

    public function test_unregistered_permission_is_rejected(): void
    {
        $admin = $this->admin();
        $role = $this->customRole();

        $this->actingAs($admin)->put("/settings/roles/{$role->id}/permissions", [
            'permission' => 'not.a.real.permission', 'granted' => true,
        ])->assertSessionHasErrors('permission');
        $this->assertCount(0, $role->fresh()->permissions);
    }

    public function test_staff_role_cannot_be_deleted_or_renamed(): void
    {
        $admin = $this->admin();
        $staff = Role::findByName('staff', 'web');

        $this->actingAs($admin)->delete("/settings/roles/{$staff->id}")->assertRedirect();
        $this->assertNotNull(Role::find($staff->id), 'staff must survive delete attempt');

        $this->actingAs($admin)->put("/settings/roles/{$staff->id}", ['name' => 'renamed'])->assertRedirect();
        $this->assertSame('staff', $staff->fresh()->name);
    }

    public function test_role_with_assigned_users_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $role = $this->customRole('Has Users');
        $u = User::factory()->create();
        $u->assignRole($role);

        $this->actingAs($admin)->delete("/settings/roles/{$role->id}")
            ->assertSessionHas('error');
        $this->assertNotNull(Role::find($role->id));
    }

    public function test_custom_role_without_users_can_be_deleted(): void
    {
        $admin = $this->admin();
        $role = $this->customRole('Disposable');

        $this->actingAs($admin)->delete("/settings/roles/{$role->id}")->assertRedirect();
        $this->assertNull(Role::find($role->id));
    }

    public function test_super_admin_role_is_not_editable(): void
    {
        $admin = $this->admin();
        $superAdmin = Role::findByName('super_admin', 'web');

        $this->actingAs($admin)->put("/settings/roles/{$superAdmin->id}/permissions", [
            'permission' => 'invoices.void', 'granted' => false,
        ])->assertForbidden();

        $this->actingAs($admin)->delete("/settings/roles/{$superAdmin->id}")->assertForbidden();
    }
}
