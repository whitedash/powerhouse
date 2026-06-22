<?php

namespace App\Policies;

use App\Models\User;

/**
 * Form themes are part of the Forms area. Phase 3a: view rides forms.access,
 * create/update/delete ride forms.manage. The raw custom_css token stays the
 * most-restricted: forms.custom_css. super_admin bypasses via Gate::before.
 */
class FormThemePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('forms.access');
    }

    public function view(User $user): bool
    {
        return $user->hasPermissionTo('forms.access');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('forms.manage');
    }

    public function update(User $user): bool
    {
        return $user->hasPermissionTo('forms.manage');
    }

    public function delete(User $user): bool
    {
        return $user->hasPermissionTo('forms.manage');
    }

    /**
     * Read + write the raw custom_css token — gated by forms.custom_css.
     */
    public function manageCustomCss(User $user): bool
    {
        return $user->hasPermissionTo('forms.custom_css');
    }
}
