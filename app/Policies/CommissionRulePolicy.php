<?php

namespace App\Policies;

use App\Models\User;

/**
 * Commission rules drive payouts, so management is super_admin-only —
 * matching the referrer payout actions (approveCommission / markPaid) and
 * the Settings route group's role:super_admin gate.
 */
class CommissionRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
