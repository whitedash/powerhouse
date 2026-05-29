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
 * @property string $price_monthly
 * @property string|null $price_annual
 * @property array<int, string>|null $features
 * @property string|null $stripe_price_id_monthly
 * @property string|null $stripe_price_id_annual
 * @property bool $is_active
 * @property bool $is_public
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $savings_percent
 * @property-read float|null $annual_monthly_cost
 * @property-read Product|null $product
 * @property-read Collection<int, CustomerProduct> $customerProducts
 */
class ProductPlan extends Model
{
    protected $fillable = [
        'product_id',
        'name',
        'description',
        'price_monthly',
        'price_annual',
        'features',
        'stripe_price_id_monthly',
        'stripe_price_id_annual',
        'is_active',
        'is_public',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'decimal:2',
            'price_annual' => 'decimal:2',
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
     * Percentage saved by paying annually vs monthly × 12. Null when
     * there's no annual price (or when monthly is zero, which would
     * divide by zero and is meaningless anyway).
     */
    protected function savingsPercent(): Attribute
    {
        return Attribute::get(function (): ?int {
            $annual = $this->price_annual !== null ? (float) $this->price_annual : null;
            $monthly = (float) $this->price_monthly;

            if ($annual === null || $monthly <= 0) {
                return null;
            }

            $monthlyEquivalent = $monthly * 12;
            if ($monthlyEquivalent <= 0) {
                return null;
            }

            return (int) round((1 - $annual / $monthlyEquivalent) * 100);
        });
    }

    /**
     * Effective monthly cost when paying annually — used in pricing UI
     * to show "£24.17/mo billed annually" alongside the full annual
     * price.
     */
    protected function annualMonthlyCost(): Attribute
    {
        return Attribute::get(function (): ?float {
            if ($this->price_annual === null) {
                return null;
            }

            return round((float) $this->price_annual / 12, 2);
        });
    }
}
