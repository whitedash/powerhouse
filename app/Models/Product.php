<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
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
}
