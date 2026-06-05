<?php

namespace App\Policies;

use App\Models\User;

/**
 * Leads carry two distinct actor lanes:
 *  - register: a referrer (role=referrer) with an active referrer record
 *    submitting a deal from the partner portal.
 *  - review:   staff approving/rejecting a registered deal.
 *
 * The existing pipeline CRUD on LeadController still rides CustomerPolicy
 * (viewAny) — this policy only governs the deal-registration abilities.
 */
class LeadPolicy
{
    public function register(User $user): bool
    {
        return $user->role === 'referrer'
            && $user->referrer !== null
            && (bool) $user->referrer->is_active;
    }

    public function review(User $user): bool
    {
        return $user->isStaff();
    }
}
