<?php

namespace App\Policies;

use App\Models\User;

/**
 * Plan themes are part of the Products area: everything rides
 * products.manage — the only products permission that exists (the whole
 * Products settings area is manage-gated, unlike forms' access/manage
 * split). Raw custom_css deliberately reuses the
 * EXISTING forms.custom_css permission — "may inject raw CSS into public
 * embeds" is one capability, not one per widget, and it avoids a
 * permission-seeder migration. super_admin bypasses via Gate::before.
 */
class PlanThemePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('products.manage');
    }

    public function view(User $user): bool
    {
        return $user->hasPermissionTo('products.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('products.manage');
    }

    public function update(User $user): bool
    {
        return $user->hasPermissionTo('products.manage');
    }

    public function delete(User $user): bool
    {
        return $user->hasPermissionTo('products.manage');
    }

    public function manageCustomCss(User $user): bool
    {
        return $user->hasPermissionTo('forms.custom_css');
    }
}
