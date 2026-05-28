<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AccountGroup extends Model
{
    protected $fillable = ['name'];

    public function customers(): BelongsToMany
    {
        // Pivot table has only created_at — no updated_at — per SCHEMA.md.
        return $this->belongsToMany(Customer::class, 'customer_group_memberships', 'group_id', 'customer_id')
            ->withPivot('role', 'created_at');
    }
}
