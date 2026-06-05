<?php

namespace App\Policies;

use App\Models\Person;
use App\Models\User;

/**
 * Mirrors CustomerPolicy: any staff member can view/manage people;
 * destructive deletes are reserved for super admins.
 */
class PersonPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, ?Person $person = null): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, ?Person $person = null): bool
    {
        return $user->isStaff();
    }

    public function delete(User $user, ?Person $person = null): bool
    {
        return $user->isSuperAdmin();
    }
}
