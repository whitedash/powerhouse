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
 * @property int|null $plan_id
 * @property int|null $billing_entity_id
 * @property string|null $stripe_subscription_id
 * @property string|null $stripe_price_id
 * @property string|null $plan
 * @property string|null $price_monthly
 * @property int $interval_count
 * @property string $interval_unit
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
 * @property-read string $interval_label
 * @property-read Customer|null $customer
 * @property-read Product|null $product
 * @property-read ProductPlan|null $productPlan
 * @property-read BillingEntity|null $billingEntity
 */
class CustomerProduct extends Model
{
    protected $fillable = [
        'customer_id',
        'product_id',
        'plan_id',
        'billing_entity_id',
        'stripe_subscription_id',
        'stripe_price_id',
        'plan',
        'price_monthly',
        'interval_count',
        'interval_unit',
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
            'interval_count' => 'integer',
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

    public function productPlan(): BelongsTo
    {
        return $this->belongsTo(ProductPlan::class, 'plan_id');
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
     * Normalise the effective price into a monthly contribution based
     * on (interval_count, interval_unit). The discount-adjusted
     * effective_price IS the price for one billing period — we divide
     * by the period length to amortise. Mirrors ProductPlan's math so
     * the two never drift.
     */
    protected function mrrContribution(): Attribute
    {
        return Attribute::get(function (): float {
            $effective = $this->effective_price;
            $count = max(1, (int) ($this->interval_count ?? 1));
            $unit = $this->interval_unit ?? 'month';

            return match ($unit) {
                'one_time' => 0.0,
                'day' => round($effective * (365 / 12) / $count, 2),
                'week' => round($effective * (52 / 12) / $count, 2),
                'month' => round($effective / $count, 2),
                'year' => round($effective / ($count * 12), 2),
                default => 0.0,
            };
        });
    }

    /**
     * ARR for this subscription. Derived from MRR so the two figures
     * can't drift; one-time still resolves to £0.
     */
    protected function arrContribution(): Attribute
    {
        return Attribute::get(fn (): float => round($this->mrr_contribution * 12, 2));
    }

    /**
     * Human-readable interval label — same shape as ProductPlan's so
     * the subscription table can render either field interchangeably.
     */
    protected function intervalLabel(): Attribute
    {
        return Attribute::get(function (): string {
            $unit = $this->interval_unit ?? 'month';
            $count = (int) ($this->interval_count ?? 1);

            if ($unit === 'one_time') {
                return 'One-time';
            }

            if ($count === 1) {
                return match ($unit) {
                    'day' => 'Daily',
                    'week' => 'Weekly',
                    'month' => 'Monthly',
                    'year' => 'Yearly',
                    default => ucfirst($unit),
                };
            }

            $plural = match ($unit) {
                'day' => 'days',
                'week' => 'weeks',
                'month' => 'months',
                'year' => 'years',
                default => $unit,
            };

            return "Every {$count} {$plural}";
        });
    }
}
