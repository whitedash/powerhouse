<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $customer_id
 * @property int $product_id
 * @property int|null $billing_entity_id
 * @property string|null $plan
 * @property string|null $price_monthly
 * @property string $status
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $started_at
 * @property Carbon|null $cancelled_at
 * @property int|null $oauth_client_id
 * @property int|null $wp_user_id
 * @property array<string, mixed>|null $config
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer|null $customer
 * @property-read Product|null $product
 * @property-read BillingEntity|null $billingEntity
 */
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
