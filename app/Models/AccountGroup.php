<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string|null $colour
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Customer> $customers
 * @property-read User|null $createdBy
 */
class AccountGroup extends Model
{
    protected $fillable = [
        'name',
        'description',
        'colour',
        'created_by',
    ];

    public function customers(): BelongsToMany
    {
        // Pivot table has only created_at — no updated_at — per SCHEMA.md.
        return $this->belongsToMany(Customer::class, 'customer_group_memberships', 'group_id', 'customer_id')
            ->withPivot('role', 'created_at');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
