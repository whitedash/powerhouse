<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

/**
 * Phase 3a: the role portion of each gate swaps to a permission; the
 * invoice-STATUS conditions are preserved verbatim (they're not role checks).
 * super_admin bypasses via Gate::before.
 */
class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('invoices.access');
    }

    public function view(User $user, ?Invoice $invoice = null): bool
    {
        return $user->hasPermissionTo('invoices.access');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('invoices.manage');
    }

    public function update(User $user, ?Invoice $invoice = null): bool
    {
        if (! $user->hasPermissionTo('invoices.manage')) {
            return false;
        }

        // No invoice instance (e.g. checking the ability on the class for
        // a Create flow) → allowed; staff can always create-then-edit.
        // Otherwise: draft / sent / overdue are editable so corrections
        // can land before payment is recorded. Paid and void are locked.
        return $invoice === null
            || in_array($invoice->status, ['draft', 'sent', 'overdue'], true);
    }

    /**
     * Unbound by any route, and there is no invoices.delete permission in the
     * matrix — so this stays super_admin-only (super_admin via Gate::before).
     * Documented phase-3a exception: no permission to map to, none invented.
     */
    public function delete(User $user, ?Invoice $invoice = null): bool
    {
        return $user->isSuperAdmin();
    }

    public function void(User $user, ?Invoice $invoice = null): bool
    {
        return $user->hasPermissionTo('invoices.void');
    }

    /**
     * Transition draft → sent. Any operator with invoices.manage can send;
     * only draft invoices can be sent (controller enforces the status check).
     */
    public function send(User $user, ?Invoice $invoice = null): bool
    {
        return $user->hasPermissionTo('invoices.manage');
    }

    /**
     * Record a payment against an existing invoice. Distinct from `update`
     * because marking paid is a status transition on a sent/overdue invoice.
     */
    public function markPaid(User $user, ?Invoice $invoice = null): bool
    {
        return $user->hasPermissionTo('invoices.manage');
    }

    /**
     * Generate a Stripe Checkout payment link for an issued invoice. Same
     * privilege as recording a payment — a money-movement action.
     */
    public function generatePaymentLink(User $user, ?Invoice $invoice = null): bool
    {
        if (! $user->hasPermissionTo('invoices.manage')) {
            return false;
        }

        return $invoice === null
            || in_array($invoice->status, ['sent', 'overdue'], true);
    }

    /**
     * Send a chase reminder. Distinct from `send` because reminders go out
     * repeatedly on already-issued invoices.
     */
    public function sendReminder(User $user, ?Invoice $invoice = null): bool
    {
        if (! $user->hasPermissionTo('invoices.manage')) {
            return false;
        }

        return $invoice === null
            || in_array($invoice->status, ['sent', 'overdue'], true);
    }

    /**
     * Pause / resume automated reminders on a single invoice.
     */
    public function manageReminders(User $user, ?Invoice $invoice = null): bool
    {
        if (! $user->hasPermissionTo('invoices.manage')) {
            return false;
        }

        return $invoice === null
            || in_array($invoice->status, ['sent', 'overdue'], true);
    }
}
