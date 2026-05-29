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
 * @property int $product_id
 * @property string $name
 * @property string|null $description
 * @property string $price
 * @property int $interval_count
 * @property string $interval_unit
 * @property string|null $stripe_price_id
 * @property array<int, string>|null $features
 * @property bool $is_active
 * @property bool $is_public
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $interval_label
 * @property-read float $mrr_contribution
 * @property-read float $arr_contribution
 * @property-read Product|null $product
 * @property-read Collection<int, CustomerProduct> $customerProducts
 */
class ProductPlan extends Model
{
    protected $fillable = [
        'product_id',
        'name',
        'description',
        'price',
        'interval_count',
        'interval_unit',
        'stripe_price_id',
        'features',
        'is_active',
        'is_public',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'interval_count' => 'integer',
            'features' => 'array',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customerProducts(): HasMany
    {
        return $this->hasMany(CustomerProduct::class, 'plan_id');
    }

    /**
     * Human-readable interval, used by every UI surface so they all
     * agree on the wording. (1, month) → "Monthly"; (3, month) →
     * "Every 3 months"; (1, year) → "Yearly"; one_time → "One-time".
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
     * Normalises the plan's price into a monthly contribution. Used by
     * MRR/ARR aggregations. one_time → 0 (one-offs don't recur);
     * monthly subs divide by their interval_count so a "every 3 months
     * £75" plan reports £25/mo MRR; annual subs divide by 12.
     */
    protected function mrrContribution(): Attribute
    {
        return Attribute::get(function (): float {
            $price = (float) $this->price;
            $count = max(1, (int) $this->interval_count);

            return match ($this->interval_unit) {
                'one_time' => 0.0,
                // 365 / 12 ≈ 30.42, the avg month length in days.
                'day' => round($price * (365 / 12) / $count, 2),
                // 52 weeks / 12 months ≈ 4.33.
                'week' => round($price * (52 / 12) / $count, 2),
                'month' => round($price / $count, 2),
                'year' => round($price / ($count * 12), 2),
                default => 0.0,
            };
        });
    }

    /**
     * ARR is just MRR × 12, derived for consistency. Reporting helpers
     * read this rather than re-deriving it.
     */
    protected function arrContribution(): Attribute
    {
        return Attribute::get(fn (): float => round($this->mrr_contribution * 12, 2));
    }
}
