<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, ?Invoice $invoice = null): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, ?Invoice $invoice = null): bool
    {
        if (! $user->isStaff()) {
            return false;
        }

        // No invoice instance (e.g. checking the ability on the class for
        // a Create flow) → allowed; staff can always create-then-edit.
        // Otherwise: draft / sent / overdue are editable so corrections
        // can land before payment is recorded. Paid and void are locked.
        return $invoice === null
            || in_array($invoice->status, ['draft', 'sent', 'overdue'], true);
    }

    public function delete(User $user, ?Invoice $invoice = null): bool
    {
        return $user->isSuperAdmin();
    }

    public function void(User $user, ?Invoice $invoice = null): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Transition draft → sent. Any staff member can send an invoice they
     * have view rights to; only draft invoices can be sent (controller
     * enforces the status check; the policy stays simple).
     */
    public function send(User $user, ?Invoice $invoice = null): bool
    {
        return $user->isStaff();
    }

    /**
     * Record a payment against an existing invoice. Distinct from
     * `update` (which is the draft-editing ability) because marking
     * paid is a status transition on a sent/overdue invoice.
     */
    public function markPaid(User $user, ?Invoice $invoice = null): bool
    {
        return $user->isStaff();
    }
}
