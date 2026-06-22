<?php

namespace App\Policies;

use App\Models\User;

/**
 * Commission rules drive payouts. Phase 3a: gated by commission.config
 * (super_admin-only today, super_admin via Gate::before).
 */
class CommissionRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('commission.config');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('commission.config');
    }

    public function update(User $user): bool
    {
        return $user->hasPermissionTo('commission.config');
    }

    public function delete(User $user): bool
    {
        return $user->hasPermissionTo('commission.config');
    }
}
