<?php

namespace App\Policies;

use App\Models\CommissionLedger;
use App\Models\User;

class CommissionLedgerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, ?CommissionLedger $entry = null): bool
    {
        return $user->isSuperAdmin();
    }

    public function approve(User $user, ?CommissionLedger $entry = null): bool
    {
        return $user->isSuperAdmin();
    }
}
