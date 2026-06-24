<?php

namespace App\Policies;

use App\Models\User;

/**
 * Leads carry two distinct actor lanes:
 *  - register: a referrer (role=referrer) with an active referrer record
 *    submitting a deal from the partner portal. UNCHANGED in phase 3a —
 *    this is a referrer check, not an internal capability, and referrer is
 *    not part of the permission system.
 *  - review:   an operator approving/rejecting a registered deal — phase 3a
 *    gates this on leads.manage (super_admin via Gate::before).
 *
 * The pipeline CRUD on LeadController still rides CustomerPolicy (viewAny).
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
        return $user->hasPermissionTo('leads.manage');
    }
}
