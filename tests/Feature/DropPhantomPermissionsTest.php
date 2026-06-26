<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Support\PermissionMatrix;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Section-enforcement sprint, step 12 (LAST) — drop the two PHANTOM permissions
 * that gated nothing: customers.delete (dead CustomerPolicy::delete; no hard-delete
 * exists) and analytics.manage (unreferenced by any enforcement). They are removed
 * from the matrix + seeder, and a migration deletes the rows lingering on prod.
 */
class DropPhantomPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private const PHANTOMS = ['customers.delete', 'analytics.manage'];

    public function test_phantoms_are_not_in_a_fresh_seed(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        foreach (self::PHANTOMS as $name) {
            $this->assertFalse(
                Permission::where('guard_name', 'web')->where('name', $name)->exists(),
                "Phantom permission {$name} should not be seeded.",
            );
        }
    }

    public function test_phantoms_are_not_in_the_permission_matrix_ui(): void
    {
        // booleanPermissionKeys() is the flat set the matrix admin UI offers.
        $keys = PermissionMatrix::booleanPermissionKeys();
        foreach (self::PHANTOMS as $name) {
            $this->assertNotContains($name, $keys, "Matrix UI must not offer {$name}.");
        }

        // Belt-and-suspenders: flatten groups() rows too.
        $rowKeys = collect(PermissionMatrix::groups())
            ->flatMap(fn (array $g): array => $g['rows'])
            ->pluck('key')
            ->all();
        foreach (self::PHANTOMS as $name) {
            $this->assertNotContains($name, $rowKeys);
        }
    }

    public function test_cleanup_migration_deletes_existing_prod_rows(): void
    {
        // Seed the real role/perm set (creates the 'staff' role the user factory
        // assigns), THEN re-introduce the phantom rows + grants to simulate the
        // prod state a fresh seed leaves behind (it skips creating them, but the
        // old rows persist).
        $this->seed(RolesAndPermissionsSeeder::class);
        $del = Permission::create(['name' => 'customers.delete', 'guard_name' => 'web']);
        $mng = Permission::create(['name' => 'analytics.manage', 'guard_name' => 'web']);

        $role = Role::create(['name' => 'legacy_'.uniqid(), 'guard_name' => 'web']);
        $role->givePermissionTo([$del, $mng]);
        $user = User::factory()->create();
        $user->givePermissionTo($mng); // a direct model grant

        $this->assertSame(2, DB::table('role_has_permissions')->whereIn('permission_id', [$del->id, $mng->id])->count());
        $this->assertSame(1, DB::table('model_has_permissions')->whereIn('permission_id', [$del->id, $mng->id])->count());

        // Run the cleanup migration's up() against this seeded-prod-like state.
        $migration = require base_path('database/migrations/2026_06_26_100000_drop_phantom_permissions.php');
        $migration->up();

        // Permission rows gone + all pivot grants cascaded away.
        foreach (self::PHANTOMS as $name) {
            $this->assertFalse(Permission::where('name', $name)->exists());
        }
        $this->assertSame(0, DB::table('role_has_permissions')->whereIn('permission_id', [$del->id, $mng->id])->count());
        $this->assertSame(0, DB::table('model_has_permissions')->whereIn('permission_id', [$del->id, $mng->id])->count());
    }

    public function test_archiving_a_customer_still_works_on_customers_manage(): void
    {
        // The removed customers.delete gated nothing; soft-archive stays on
        // customers.manage — confirm no functional regression.
        $this->seed(RolesAndPermissionsSeeder::class);
        $role = Role::create(['name' => 'arch_'.uniqid(), 'guard_name' => 'web']);
        $role->givePermissionTo('customers.manage');
        $user = User::factory()->create();
        $user->syncRoles([$role->name]);
        $customer = Customer::create(['name' => 'Acme '.uniqid()]);

        // Soft-archive is DELETE /customers/{id}/archive → archive() →
        // Gate::authorize('update') (customers.manage). No hard-delete route exists.
        $this->actingAs($user->fresh())->delete("/customers/{$customer->id}/archive")->assertRedirect();
        $this->assertNotNull($customer->fresh()->archived_at); // soft-archived, not hard-deleted
    }
}
