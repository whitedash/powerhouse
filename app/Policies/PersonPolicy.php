<?php

namespace App\Policies;

use App\Models\Person;
use App\Models\User;

/**
 * Phase 3a: view/manage ride people.access / people.manage; destructive
 * delete rides people.delete. super_admin bypasses via Gate::before.
 */
class PersonPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('people.access');
    }

    public function view(User $user, ?Person $person = null): bool
    {
        return $user->hasPermissionTo('people.access');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('people.manage');
    }

    public function update(User $user, ?Person $person = null): bool
    {
        return $user->hasPermissionTo('people.manage');
    }

    public function delete(User $user, ?Person $person = null): bool
    {
        return $user->hasPermissionTo('people.delete');
    }
}
