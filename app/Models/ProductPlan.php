<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $product_id
 * @property int|null $category_id
 * @property string $name
 * @property string|null $description
 * @property array<int, string>|null $features
 * @property bool $is_active
 * @property bool $is_public
 * @property int $sort_order
 * @property int|null $disk_quota_gb
 * @property int|null $email_quota
 * @property int|null $bandwidth_quota_gb
 * @property int $active_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read float $mrr_contribution
 * @property-read ProductPlanPrice|null $default_price
 * @property-read Product|null $product
 * @property-read ProductPlanCategory|null $category
 * @property-read Collection<int, CustomerProduct> $customerProducts
 * @property-read Collection<int, ProductPlanPrice> $prices
 * @property-read Collection<int, ProductPlanPrice> $activePrices
 * @property-read ProductPlanPrice|null $defaultPrice
 */
class ProductPlan extends Model
{
    protected $fillable = [
        'product_id',
        'category_id',
        'name',
        'description',
        'features',
        'is_active',
        'is_public',
        'sort_order',
        // Hosting allowances — nullable; only hosting plans use them.
        'disk_quota_gb',
        'email_quota',
        'bandwidth_quota_gb',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'sort_order' => 'integer',
            'disk_quota_gb' => 'integer',
            'email_quota' => 'integer',
            'bandwidth_quota_gb' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductPlanCategory::class, 'category_id');
    }

    public function customerProducts(): HasMany
    {
        return $this->hasMany(CustomerProduct::class, 'plan_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPlanPrice::class, 'plan_id')->orderBy('sort_order');
    }

    public function activePrices(): HasMany
    {
        return $this->hasMany(ProductPlanPrice::class, 'plan_id')
            ->where('is_active', true)
            ->orderBy('sort_order');
    }

    public function defaultPrice(): HasOne
    {
        return $this->hasOne(ProductPlanPrice::class, 'plan_id')->where('is_default', true);
    }

    /**
     * Plan-level MRR is the contribution of its default price. Callers
     * that want the full price spread should walk activePrices
     * themselves. Returns 0 if no default has been chosen — or if the
     * caller forgot to eager-load defaultPrice — so aggregations stay
     * safe under Model::preventLazyLoading().
     */
    protected function mrrContribution(): Attribute
    {
        return Attribute::get(function (): float {
            if (! $this->relationLoaded('defaultPrice') || ! $this->defaultPrice) {
                return 0.0;
            }

            return $this->defaultPrice->mrr_contribution;
        });
    }
}
