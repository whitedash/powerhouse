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

        return $invoice === null || $invoice->status === 'draft';
    }

    public function delete(User $user, ?Invoice $invoice = null): bool
    {
        return $user->isSuperAdmin();
    }

    public function void(User $user, ?Invoice $invoice = null): bool
    {
        return $user->isSuperAdmin();
    }
}
