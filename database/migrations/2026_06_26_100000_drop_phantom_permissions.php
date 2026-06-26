<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sprint step 12 — drop the two PHANTOM permissions that gated nothing:
 *   - customers.delete : a hard-delete that never existed (only soft-archive,
 *     via customers.manage). CustomerPolicy::delete was dead code, now removed.
 *   - analytics.manage : unreferenced by any Gate / middleware / policy / can().
 *
 * Removing them from PermissionMatrix + the seeder stops a FRESH seed recreating
 * them — but PRODUCTION already holds these rows (seeded at the original cutover),
 * and a re-seed only SKIPS creating new perms; it never DELETES existing rows. So
 * without this migration the phantoms would linger in the prod permissions table
 * (and thus the matrix admin UI). This runs on the live DB via the deploy panel's
 * migrate step. Purely subtractive — they enforced nothing.
 */
return new class() extends Migration
{
    /** @var list<string> */
    private array $phantoms = ['customers.delete', 'analytics.manage'];

    public function up(): void
    {
        $ids = DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', $this->phantoms)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return; // fresh DB (tests) or already cleaned — nothing to remove.
        }

        // Drop pivot grants first: role grants + any direct user/model grants.
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        // Spatie caches the permission map — bust it so the live app re-reads.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Reversible: recreate the (orphan) permission rows. They granted nothing,
        // so no role/user grants are restored; a later seed won't re-add them.
        foreach ($this->phantoms as $name) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name, 'guard_name' => 'web'],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
