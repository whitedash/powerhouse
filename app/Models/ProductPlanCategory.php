<?php

namespace App\Models;

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
 * @property int $sort_order
 * @property bool $is_public
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product|null $product
 * @property-read Collection<int, ProductPlan> $plans
 * @property-read Collection<int, ProductPlan> $activePlans
 */
class ProductPlanCategory extends Model
{
    protected $table = 'product_plan_categories';

    protected $fillable = [
        'product_id',
        'name',
        'description',
        'sort_order',
        'is_public',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_public' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function plans(): HasMany
    {
        return $this->hasMany(ProductPlan::class, 'category_id')->orderBy('sort_order');
    }

    public function activePlans(): HasMany
    {
        return $this->hasMany(ProductPlan::class, 'category_id')
            ->where('is_active', true)
            ->orderBy('sort_order');
    }
}
