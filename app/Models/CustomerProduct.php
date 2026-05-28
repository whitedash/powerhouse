<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerProduct extends Model
{
    protected $fillable = [
        'customer_id',
        'product_id',
        'billing_entity_id',
        'plan',
        'price_monthly',
        'status',
        'trial_ends_at',
        'started_at',
        'cancelled_at',
        'oauth_client_id',
        'wp_user_id',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'decimal:2',
            'trial_ends_at' => 'datetime',
            'started_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'config' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function billingEntity(): BelongsTo
    {
        return $this->belongsTo(BillingEntity::class);
    }
}
