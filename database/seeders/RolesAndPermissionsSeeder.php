<?php

namespace Database\Seeders;

use App\Enums\AccessScope;
use App\Enums\ScopeArea;
use App\Models\RoleScope;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 1 — seeds the roles & permissions system ACCESS-IDENTICALLY to the
 * current enum-based enforcement, then backfills existing users.
 *
 * INERT: nothing reads these tables for authorization in phase 1. The app
 * still gates exclusively on the users.role enum (isStaff/isSuperAdmin).
 * Idempotent (findOrCreate / syncPermissions / updateOrCreate / assignRole)
 * so it is safe to re-run on every deploy.
 *
 * Source of truth for the lists below: the approved matrix mock
 * (design/Roles & Permissions.html) + inventory Part 6.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    private const GUARD = 'web';

    /**
     * staff's day-1 boolean grant — EXACTLY what a staff user can do today.
     *
     * DELIBERATE CARVE-OUT: `settings.integrations` is intentionally absent.
     * It now subsumes webhook-delivery retry, which becomes super-admin-only
     * — the single intentional day-1 access change (staff loses webhook
     * retry). Everything else is access-identical to today.
     *
     * @var list<string>
     */
    private const STAFF_PERMISSIONS = [
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

    /**
     * Super-admin-only boolean permissions — created but WITHHELD from staff
     * (the high-stakes set + the settings.* editors, incl. the carved-out
     * `settings.integrations`). super_admin holds these (and everything).
     *
     * @var list<string>
     */
    private const SUPER_ADMIN_ONLY = [
        'companies.referral.manage', 'companies.exemption',
        'gdpr.export', 'gdpr.erase',
        'people.delete',
        'invoices.void',
        'forms.custom_css',
        'products.custom_css',
        'wordpress.bulk_update',
        'expenses.approve',
        'referrers.manage', 'commission.approve', 'commission.config',
        'maavelus.statements',
        'staff.manage', 'impersonate',
        'billing_entities.manage', 'products.manage',
        'settings.integrations', 'settings.notifications',
        'settings.billing_automation', 'settings.reminder_templates',
        'settings.audit_log', 'settings.danger',
        'deployment.run',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 1. Permissions (idempotent), all under the web (staff) guard.
        foreach ([...self::STAFF_PERMISSIONS, ...self::SUPER_ADMIN_ONLY] as $name) {
            Permission::findOrCreate($name, self::GUARD);
        }

        // 2. Roles (idempotent). `referrer` is intentionally NOT a Spatie
        //    role (design §5.1) — referrers stay enum-only and untouched.
        $staff = Role::findOrCreate('staff', self::GUARD);
        $superAdmin = Role::findOrCreate('super_admin', self::GUARD);

        // 3. Grants. staff = exactly its day-1 set (sync = idempotent +
        //    converges on re-run). super_admin holds EVERY permission — the
        //    seeder half of the two-part bypass, so Spatie's permission
        //    middleware admits it once enforcement swaps (the Gate::before
        //    half lands in the later enforcement phase, not here).
        $staff->syncPermissions(self::STAFF_PERMISSIONS);
        $superAdmin->syncPermissions(Permission::where('guard_name', self::GUARD)->get());

        // 4. Scopes (tri-state). Absent (role, area) row = None by default,
        //    so we only write the non-None rows: both seeded roles see ALL.
        foreach (ScopeArea::cases() as $area) {
            foreach ([$staff, $superAdmin] as $role) {
                RoleScope::updateOrCreate(
                    ['role_id' => $role->id, 'area' => $area->value],
                    ['scope' => AccessScope::All->value],
                );
            }
        }

        // 5. Backfill existing users — mirror the role enum into Spatie's
        //    assignment tables. Enforcement still reads the enum this phase;
        //    this only makes the new tables reflect reality. assignRole is
        //    idempotent. referrer users get no Spatie role (design §5.1).
        //    Eager-load `roles`: Spatie's assignRole reads $user->roles to
        //    diff existing assignments, which would lazy-load (and trip
        //    Model::preventLazyLoading() outside production) on a backfill
        //    over pre-existing users — exactly the cutover scenario.
        foreach (User::where('role', 'super_admin')->with('roles')->get() as $user) {
            $user->assignRole($superAdmin);
        }
        foreach (User::where('role', 'staff')->with('roles')->get() as $user) {
            $user->assignRole($staff);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
