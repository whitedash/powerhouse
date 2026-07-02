<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Customer -> Company rename: rename the four customers.* permission strings
 * to companies.* IN PLACE, mirroring drop_phantom_permissions.
 *
 * Renaming (UPDATE name) rather than re-seeding is deliberate: a re-seed would
 * CREATE new companies.* rows and leave the old customers.* rows orphaned with
 * their grants stranded (the phantom-permission incident). An in-place UPDATE
 * keeps the SAME permission row id, so every role_has_permissions /
 * model_has_permissions pivot keeps pointing at it — a staff user who held
 * customers.manage holds companies.manage after, with no window of lost access.
 * (Enforcement is still enum-based and super_admin bypasses via Gate::before,
 * so the practical blast radius is staff on the two referral/exemption routes +
 * the two policy abilities regardless.)
 *
 * Runs on the live DB via the deploy panel's migrate step; busts Spatie's cache.
 */
return new class() extends Migration
{
    /** @var array<string, string> old => new */
    private array $renames = [
        'customers.access' => 'companies.access',
        'customers.manage' => 'companies.manage',
        'customers.referral.manage' => 'companies.referral.manage',
        'customers.exemption' => 'companies.exemption',
    ];

    public function up(): void
    {
        foreach ($this->renames as $old => $new) {
            DB::table('permissions')
                ->where('guard_name', 'web')
                ->where('name', $old)
                ->update(['name' => $new, 'updated_at' => now()]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach ($this->renames as $old => $new) {
            DB::table('permissions')
                ->where('guard_name', 'web')
                ->where('name', $new)
                ->update(['name' => $old, 'updated_at' => now()]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
