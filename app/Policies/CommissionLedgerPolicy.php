<?php

namespace App\Policies;

use App\Models\CommissionLedger;
use App\Models\User;

/**
 * Phase 3a: the commission ledger rides commission.approve
 * (super_admin-only today, super_admin via Gate::before).
 */
class CommissionLedgerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('commission.approve');
    }

    public function view(User $user, ?CommissionLedger $entry = null): bool
    {
        return $user->hasPermissionTo('commission.approve');
    }

    public function approve(User $user, ?CommissionLedger $entry = null): bool
    {
        return $user->hasPermissionTo('commission.approve');
    }
}
