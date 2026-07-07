<?php

namespace Tests\Feature;

use App\Enums\AccessScope;
use App\Enums\ScopeArea;
use App\Models\RoleScope;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolesPermissionsSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * staff's expected day-1 grant — kept in the test as the independent
     * assertion of the access-identical mapping (incl. the deliberate
     * settings.integrations / webhook-retry carve-out being absent).
     *
     * @var list<string>
     */
    private const EXPECTED_STAFF = [
        'companies.access', 'companies.manage',
        'people.access', 'people.manage',
        'invoices.access', 'invoices.manage',
        'leads.manage',
        'proposals.access', 'proposals.manage',
        'projects.manage',
        'tasks.manage',
        'support.manage', 'support.view_unassigned',
        'forms.access', 'forms.manage', 'forms.view_submissions',
        'hosting.access', 'hosting.manage',
        'expenses.access', 'expenses.manage',
        'workflows.access', 'workflows.manage',
        'knowledge_base.access', 'knowledge_base.manage',
        'provisioning.access', 'provisioning.manage',
        'analytics.access',
        'referrers.access',
        'settings.access',
    ];

    public function test_staff_role_holds_exactly_its_day_one_permissions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $staff = Role::findByName('staff', 'web');
        $actual = $staff->permissions->pluck('name')->sort()->values()->all();
        $expected = collect(self::EXPECTED_STAFF)->sort()->values()->all();

        $this->assertSame($expected, $actual);
        // Step 12: analytics.manage dropped (phantom) → staff holds 29 (was 30).
        $this->assertCount(29, $staff->permissions);
    }

    public function test_webhook_retry_carveout_staff_lacks_settings_integrations(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $staff = Role::findByName('staff', 'web');

        // The one deliberate day-1 access change.
        $this->assertFalse($staff->hasPermissionTo('settings.integrations'));
        // Sanity: the permission exists (it's created, just withheld) and a
        // routine staff capability is still granted.
        $this->assertTrue(Permission::where('name', 'settings.integrations')->where('guard_name', 'web')->exists());
        $this->assertTrue($staff->hasPermissionTo('companies.manage'));
        $this->assertTrue($staff->hasPermissionTo('support.view_unassigned'));
    }

    public function test_staff_has_all_scope_on_the_four_scoped_areas(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $staff = Role::findByName('staff', 'web');

        foreach (ScopeArea::cases() as $area) {
            $row = RoleScope::where('role_id', $staff->id)->where('area', $area->value)->first();
            $this->assertNotNull($row, "missing scope row for {$area->value}");
            $this->assertSame(AccessScope::All, $row->scope);
        }

        $this->assertSame(4, RoleScope::where('role_id', $staff->id)->count());
    }

    public function test_super_admin_holds_every_permission_and_all_scopes(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $superAdmin = Role::findByName('super_admin', 'web');

        $this->assertSame(
            Permission::where('guard_name', 'web')->count(),
            $superAdmin->permissions->count(),
        );
        // Step 12: customers.delete + analytics.manage dropped (phantoms) → 53 (was 55).
        // Plans theming: + products.custom_css (raw CSS into the Plans
        // widget's shadow root, separate from forms.custom_css) → 54.
        $this->assertSame(54, Permission::where('guard_name', 'web')->count());
        $this->assertSame(4, RoleScope::where('role_id', $superAdmin->id)->count());
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $perms = Permission::count();
        $roles = Role::count();
        $scopes = RoleScope::count();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame($perms, Permission::count());
        $this->assertSame($roles, Role::count());
        $this->assertSame($scopes, RoleScope::count());
        $this->assertCount(29, Role::findByName('staff', 'web')->permissions);
    }

    public function test_backfill_mirrors_role_enum_into_spatie(): void
    {
        $superAdminUser = User::factory()->superAdmin()->create();
        $staffUser = User::factory()->create();            // default role = staff
        $referrerUser = User::factory()->referrer()->create();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertTrue($superAdminUser->fresh()->hasRole('super_admin'));
        $this->assertTrue($staffUser->fresh()->hasRole('staff'));
        // referrer is intentionally not a Spatie role (design §5.1).
        $this->assertCount(0, $referrerUser->fresh()->roles);
    }
}
