<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, ?Product $product = null): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, ?Product $product = null): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, ?Product $product = null): bool
    {
        return $user->isSuperAdmin();
    }
}
