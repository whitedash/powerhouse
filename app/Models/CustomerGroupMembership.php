<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerGroupMembership extends Model
{
    protected $table = 'customer_group_memberships';

    public $timestamps = false;

    protected $fillable = ['group_id', 'customer_id', 'role'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(AccountGroup::class, 'group_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
