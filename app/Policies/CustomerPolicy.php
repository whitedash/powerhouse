<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

/**
 * Phase 3a: role checks swapped to permission checks. super_admin never
 * reaches these bodies (Gate::before bypass); staff/custom roles are checked
 * against their held permissions.
 *
 * NOTE: contacts, contracts, customer-groups, projects and the leads CRUD
 * authorize against THIS policy (the parent-customer pattern), so they ride
 * customers.access / customers.manage. Access-identical today (staff holds
 * both); finer per-area splitting is a later refinement, not phase 3a.
 */
class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('customers.access');
    }

    public function view(User $user, ?Customer $customer = null): bool
    {
        return $user->hasPermissionTo('customers.access');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('customers.manage');
    }

    public function update(User $user, ?Customer $customer = null): bool
    {
        return $user->hasPermissionTo('customers.manage');
    }

    // NOTE (sprint step 12): the delete() policy method + customers.delete
    // permission were removed — they gated a hard-delete that never existed
    // (only soft-archive, via customers.manage). No 'delete' ability is invoked
    // on a Customer anywhere, so the method was dead code.
}
