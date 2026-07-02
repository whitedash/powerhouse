<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The Customer->Company permission rename migration must preserve grants: an
 * in-place UPDATE keeps the permission row id, so role_has_permissions pivots
 * stay valid. A staff user holding companies.manage before must hold
 * companies.manage after — no lost access, no orphaned old rows.
 */
class PermissionRenameMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runRenameMigration(): void
    {
        $migration = require database_path('migrations/2026_07_03_100000_rename_customers_permissions_to_companies.php');
        $migration->up();
    }

    public function test_rename_preserves_the_row_id_and_role_grant(): void
    {
        // Simulate the pre-migration production state: the OLD permission name,
        // granted to a staff role, held by a user.
        // UserFactory::afterCreating assigns the Spatie role matching the role
        // column ('staff' by default), so that role must exist.
        Role::create(['name' => 'staff', 'guard_name' => 'web']);

        $perm = Permission::create(['name' => 'customers.manage', 'guard_name' => 'web']);
        $originalId = $perm->id;
        $role = Role::create(['name' => 'ops', 'guard_name' => 'web']);
        $role->givePermissionTo($perm);
        $user = User::factory()->create();
        $user->assignRole($role);

        // Sanity: the user has the old permission before the migration.
        $this->assertTrue($user->fresh()->hasPermissionTo('customers.manage'));

        $this->runRenameMigration();
        app()->forgetInstance(PermissionRegistrar::class);

        // Same row id, renamed; the pivot never moved, so the grant survives.
        $renamed = Permission::where('name', 'companies.manage')->firstOrFail();
        $this->assertSame($originalId, $renamed->id, 'the permission row id is unchanged');
        $this->assertDatabaseMissing('permissions', ['name' => 'customers.manage', 'guard_name' => 'web']);
        $this->assertDatabaseHas('role_has_permissions', ['permission_id' => $originalId, 'role_id' => $role->id]);
        $this->assertTrue($user->fresh()->hasPermissionTo('companies.manage'), 'access preserved under the new name');
    }

    public function test_all_four_strings_rename_and_none_are_orphaned(): void
    {
        foreach (['customers.access', 'customers.manage', 'customers.referral.manage', 'customers.exemption'] as $old) {
            Permission::create(['name' => $old, 'guard_name' => 'web']);
        }

        $this->runRenameMigration();

        foreach (['companies.access', 'companies.manage', 'companies.referral.manage', 'companies.exemption'] as $new) {
            $this->assertDatabaseHas('permissions', ['name' => $new, 'guard_name' => 'web']);
        }
        // No old rows left orphaned.
        $orphans = DB::table('permissions')->where('name', 'like', 'customers.%')->count();
        $this->assertSame(0, $orphans, 'no orphaned customers.* rows remain');
    }

    public function test_seed_roles_after_the_rename_creates_companies_not_customers(): void
    {
        // The "Seed roles" deploy action re-runs the seeder — it must create the
        // new companies.* permissions and never resurrect customers.*.
        $this->seed(RolesAndPermissionsSeeder::class);

        foreach (['companies.access', 'companies.manage', 'companies.referral.manage', 'companies.exemption'] as $new) {
            $this->assertDatabaseHas('permissions', ['name' => $new, 'guard_name' => 'web']);
        }
        $this->assertSame(0, DB::table('permissions')->where('name', 'like', 'customers.%')->count());
    }
}
