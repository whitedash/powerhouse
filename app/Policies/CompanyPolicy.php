<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

/**
 * Phase 3a: role checks swapped to permission checks. super_admin never
 * reaches these bodies (Gate::before bypass); staff/custom roles are checked
 * against their held permissions.
 *
 * NOTE: contacts, contracts, customer-groups, projects and the leads CRUD
 * authorize against THIS policy (the parent-customer pattern), so they ride
 * companies.access / companies.manage. Access-identical today (staff holds
 * both); finer per-area splitting is a later refinement, not phase 3a.
 */
class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('companies.access');
    }

    public function view(User $user, ?Company $customer = null): bool
    {
        return $user->hasPermissionTo('companies.access');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('companies.manage');
    }

    public function update(User $user, ?Company $customer = null): bool
    {
        return $user->hasPermissionTo('companies.manage');
    }

    // NOTE (sprint step 12): the delete() policy method + customers.delete
    // permission were removed — they gated a hard-delete that never existed
    // (only soft-archive, via companies.manage). No 'delete' ability is invoked
    // on a Company anywhere, so the method was dead code.
}
