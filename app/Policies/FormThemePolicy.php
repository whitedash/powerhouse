<?php

namespace App\Policies;

use App\Models\User;

/**
 * Form themes are part of the Forms (Operations) area, so any internal
 * staff member can manage them — matching the form builder's access.
 *
 * The one exception is custom_css: it is RAW CSS injected into the embed
 * widget's shadow root, so reading AND writing it is restricted to
 * super_admin via manageCustomCss(). The controller gates the field on
 * that ability; everyone else never sees or sets it.
 */
class FormThemePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user): bool
    {
        return $user->isStaff();
    }

    public function delete(User $user): bool
    {
        return $user->isStaff();
    }

    /**
     * Read + write the raw custom_css token — super_admin only.
     */
    public function manageCustomCss(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
