<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A reusable visual theme for the embeddable Plans widget — the FormTheme
 * shape, mirrored (tokens = PARTIAL override set; effective values via
 * App\Support\PlanThemeTokens::resolve()). Assigned per product via
 * products.theme_id; the single-plan embed inherits its product's theme.
 *
 * @property int $id
 * @property string $name
 * @property array<string, mixed> $tokens
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $createdBy
 * @property-read Collection<int, Product> $products
 */
class PlanTheme extends Model
{
    protected $fillable = ['name', 'tokens', 'created_by'];

    protected function casts(): array
    {
        return ['tokens' => 'array'];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'theme_id');
    }
}
