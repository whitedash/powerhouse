<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
 * @property string|null $qbo_item_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $embed_url
 * @property-read PlanTheme|null $theme
 * @property-read BillingEntity|null $billingEntity
 * @property-read Collection<int, CustomerProduct> $customerProducts
 * @property-read Collection<int, CommissionRule> $commissionRules
 * @property-read Collection<int, OnboardingSequence> $onboardingSequences
 * @property-read Collection<int, ProductPlan> $plans
 * @property-read Collection<int, ProductPlan> $activePlans
 * @property-read Collection<int, ProductPlanCategory> $planCategories
 * @property-read Collection<int, Supplier> $suppliers
 * @property-read ProductSupplier $pivot
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
        'qbo_item_id',
        // Plans-widget theme (plan_themes) — null = default look.
        'theme_id',
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

    public function theme(): BelongsTo
    {
        return $this->belongsTo(PlanTheme::class, 'theme_id');
    }

    /**
     * Public URL of this product's Plans embed widget
     * (PlanEmbedController::script). Mirrors Form::embedUrl — the
     * plan-builder's "Copy embed code" block interpolates it into the
     * div+script snippet.
     */
    protected function embedUrl(): Attribute
    {
        return Attribute::get(
            fn (): string => rtrim((string) config('app.url'), '/').'/plans/'.$this->slug.'/embed.js',
        );
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

    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class, 'product_suppliers')
            ->using(ProductSupplier::class)
            ->withPivot(['cost_per_unit', 'billing_interval', 'notes', 'sort_order'])
            ->withTimestamps()
            ->orderBy('product_suppliers.sort_order');
    }
}
