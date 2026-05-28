<?php

namespace App\Policies;

use App\Models\BillingEntity;
use App\Models\User;

class BillingEntityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, ?BillingEntity $entity = null): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, ?BillingEntity $entity = null): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, ?BillingEntity $entity = null): bool
    {
        return $user->isSuperAdmin();
    }
}
