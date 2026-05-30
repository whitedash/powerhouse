<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property int|null $billing_entity_id
 * @property string|null $icon_colour
 * @property bool $is_active
 * @property bool $is_coming_soon
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BillingEntity|null $billingEntity
 * @property-read Collection<int, CustomerProduct> $customerProducts
 * @property-read Collection<int, CommissionRule> $commissionRules
 * @property-read Collection<int, OnboardingSequence> $onboardingSequences
 * @property-read Collection<int, ProductPlan> $plans
 * @property-read Collection<int, ProductPlan> $activePlans
 * @property-read Collection<int, ProductPlanCategory> $planCategories
 */
class Product extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
        // Default billing entity — when set, invoices that include
        // this product pre-select the matching entity automatically.
        'billing_entity_id',
        'icon_colour',
        'is_active',
        'is_coming_soon',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_coming_soon' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function billingEntity(): BelongsTo
    {
        return $this->belongsTo(BillingEntity::class);
    }

    public function customerProducts(): HasMany
    {
        return $this->hasMany(CustomerProduct::class);
    }

    public function commissionRules(): HasMany
    {
        return $this->hasMany(CommissionRule::class);
    }

    public function onboardingSequences(): HasMany
    {
        return $this->hasMany(OnboardingSequence::class);
    }

    public function plans(): HasMany
    {
        return $this->hasMany(ProductPlan::class)->orderBy('sort_order');
    }

    public function activePlans(): HasMany
    {
        return $this->hasMany(ProductPlan::class)
            ->where('is_active', true)
            ->orderBy('sort_order');
    }

    public function planCategories(): HasMany
    {
        return $this->hasMany(ProductPlanCategory::class)->orderBy('sort_order');
    }
}
