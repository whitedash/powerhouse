<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

/**
 * Phase 3a: products ride a single capability — products.manage
 * (super_admin-only today, super_admin via Gate::before).
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('products.manage');
    }

    public function view(User $user, ?Product $product = null): bool
    {
        return $user->hasPermissionTo('products.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('products.manage');
    }

    public function update(User $user, ?Product $product = null): bool
    {
        return $user->hasPermissionTo('products.manage');
    }

    public function delete(User $user, ?Product $product = null): bool
    {
        return $user->hasPermissionTo('products.manage');
    }
}
