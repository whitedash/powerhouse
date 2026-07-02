<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The Company ↔ Stripe-Customer mapping (Billing P1). One row per customer for
 * the single GBP Stripe account; a future per-entity split adds a
 * billing_entity_id dimension.
 *
 * @property int $id
 * @property int $customer_id
 * @property string $stripe_customer_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company|null $customer
 */
class StripeCustomer extends Model
{
    protected $fillable = [
        'customer_id',
        'stripe_customer_id',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
