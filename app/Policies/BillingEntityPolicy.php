<?php

namespace App\Policies;

use App\Models\BillingEntity;
use App\Models\User;

/**
 * Phase 3a: billing entities ride a single capability — billing_entities.manage
 * (no separate access perm; super_admin-only today, super_admin via Gate::before).
 */
class BillingEntityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('billing_entities.manage');
    }

    public function view(User $user, ?BillingEntity $entity = null): bool
    {
        return $user->hasPermissionTo('billing_entities.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('billing_entities.manage');
    }

    public function update(User $user, ?BillingEntity $entity = null): bool
    {
        return $user->hasPermissionTo('billing_entities.manage');
    }

    public function delete(User $user, ?BillingEntity $entity = null): bool
    {
        return $user->hasPermissionTo('billing_entities.manage');
    }
}
