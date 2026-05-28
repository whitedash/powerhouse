<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, ?Customer $customer = null): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, ?Customer $customer = null): bool
    {
        return $user->isStaff();
    }

    public function delete(User $user, ?Customer $customer = null): bool
    {
        return $user->isSuperAdmin();
    }
}
