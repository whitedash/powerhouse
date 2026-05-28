<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OnboardingSequence extends Model
{
    protected $fillable = [
        'product_id',
        'name',
        'is_active',
        'steps',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'steps' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function progress(): HasMany
    {
        return $this->hasMany(CustomerOnboardingProgress::class, 'sequence_id');
    }
}
