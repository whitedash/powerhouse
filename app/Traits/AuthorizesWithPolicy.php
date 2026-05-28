<?php

namespace App\Traits;

use Illuminate\Support\Facades\Gate;

/**
 * Tiny shim used by every Internal controller so authorization failures
 * are uniform (403 + message) regardless of which method triggered them.
 *
 * Pattern in controllers:
 *   $customer = Customer::findOrFail($id);
 *   $this->authorizeOrFail('view', $customer);
 */
trait AuthorizesWithPolicy
{
    protected function authorizeOrFail(string $ability, mixed $model): void
    {
        if (! Gate::allows($ability, $model)) {
            abort(403, 'Unauthorised action.');
        }
    }
}
