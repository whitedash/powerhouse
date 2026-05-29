<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $plan_id
 * @property string $price
 * @property int $interval_count
 * @property string $interval_unit
 * @property string|null $stripe_price_id
 * @property string|null $label
 * @property bool $is_default
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $interval_label
 * @property-read float $mrr_contribution
 * @property-read float $arr_contribution
 * @property-read string $display_label
 * @property-read ProductPlan|null $plan
 * @property-read Collection<int, CustomerProduct> $customerProducts
 */
class ProductPlanPrice extends Model
{
    protected $table = 'product_plan_prices';

    protected $fillable = [
        'plan_id',
        'price',
        'interval_count',
        'interval_unit',
        'stripe_price_id',
        'label',
        'is_default',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'interval_count' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProductPlan::class, 'plan_id');
    }

    public function customerProducts(): HasMany
    {
        return $this->hasMany(CustomerProduct::class, 'plan_price_id');
    }

    /**
     * Human-readable interval ("Monthly", "Every 3 months", "Yearly",
     * "One-time"). Identical wording to the old ProductPlan accessor so
     * every UI surface keeps agreeing on the label.
     */
    protected function intervalLabel(): Attribute
    {
        return Attribute::get(function (): string {
            if ($this->interval_unit === 'one_time') {
                return 'One-time';
            }

            $unit = $this->interval_unit;
            $count = $this->interval_count;

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

    /**
     * Amortise the price into a monthly contribution. Same math as the
     * old CustomerProduct accessor — moves here so every aggregate
     * (dashboard MRR, per-product MRR, customer MRR) reads from one
     * source.
     */
    protected function mrrContribution(): Attribute
    {
        return Attribute::get(function (): float {
            $price = (float) $this->price;
            $count = max(1, (int) $this->interval_count);

            return match ($this->interval_unit) {
                'one_time' => 0.0,
                'day' => round($price * (365 / 12) / $count, 2),
                'week' => round($price * (52 / 12) / $count, 2),
                'month' => round($price / $count, 2),
                'year' => round($price / ($count * 12), 2),
                default => 0.0,
            };
        });
    }

    protected function arrContribution(): Attribute
    {
        return Attribute::get(fn (): float => round($this->mrr_contribution * 12, 2));
    }

    /**
     * Marketing-friendly compound label for pricing UIs:
     *   "Monthly · £29.00"
     *   "Monthly · £29.00 — Most popular"
     * Used when a price needs to introduce itself outside the context
     * of its plan card.
     */
    protected function displayLabel(): Attribute
    {
        return Attribute::get(function (): string {
            $base = $this->interval_label.' · £'.number_format((float) $this->price, 2);

            return $this->label
                ? $base.' — '.$this->label
                : $base;
        });
    }
}
