<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $customer_id
 * @property int $product_id
 * @property int|null $billing_entity_id
 * @property string|null $stripe_subscription_id
 * @property string|null $stripe_price_id
 * @property string|null $plan
 * @property string|null $price_monthly
 * @property string $billing_interval
 * @property string $status
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $started_at
 * @property Carbon|null $next_billing_date
 * @property string|null $discount_pct
 * @property Carbon|null $discount_expires_at
 * @property Carbon|null $cancels_at
 * @property Carbon|null $cancelled_at
 * @property int|null $oauth_client_id
 * @property int|null $wp_user_id
 * @property array<string, mixed>|null $config
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read float $effective_price
 * @property-read float $mrr_contribution
 * @property-read float $arr_contribution
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
        'stripe_subscription_id',
        'stripe_price_id',
        'plan',
        'price_monthly',
        'billing_interval',
        'status',
        'trial_ends_at',
        'started_at',
        'next_billing_date',
        'discount_pct',
        'discount_expires_at',
        'cancels_at',
        'cancelled_at',
        'oauth_client_id',
        'wp_user_id',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'decimal:2',
            'discount_pct' => 'decimal:2',
            'trial_ends_at' => 'datetime',
            'started_at' => 'datetime',
            'next_billing_date' => 'date',
            'discount_expires_at' => 'date',
            'cancels_at' => 'date',
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

    /**
     * Price after the active discount, if any. A discount is "active"
     * when discount_pct is set AND either no expiry was given or the
     * expiry is still in the future. Keeps the math in one place so
     * the table cell, edit slide-over preview, and MRR/ARR helpers
     * can't drift.
     */
    protected function effectivePrice(): Attribute
    {
        return Attribute::get(function (): float {
            $base = (float) ($this->price_monthly ?? 0);
            $pct = (float) ($this->discount_pct ?? 0);

            if ($pct <= 0) {
                return $base;
            }

            $expiry = $this->discount_expires_at;
            if ($expiry !== null && $expiry->isPast()) {
                return $base;
            }

            return round($base * (1 - $pct / 100), 2);
        });
    }

    /**
     * What this subscription contributes to MRR.
     * Annual subs amortise across 12 months; one-off shows £0.
     */
    protected function mrrContribution(): Attribute
    {
        return Attribute::get(function (): float {
            $effective = $this->effective_price;

            return match ($this->billing_interval) {
                'annual' => round($effective / 12, 2),
                'one_off' => 0.0,
                default => $effective,
            };
        });
    }

    /**
     * What this subscription contributes to ARR.
     * Monthly subs annualise; annual is its own price; one-off shows £0.
     */
    protected function arrContribution(): Attribute
    {
        return Attribute::get(function (): float {
            $effective = $this->effective_price;

            return match ($this->billing_interval) {
                'annual' => $effective,
                'one_off' => 0.0,
                default => round($effective * 12, 2),
            };
        });
    }
}
