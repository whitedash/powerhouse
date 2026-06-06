<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A reusable visual theme for embeddable forms — a named bag of design
 * tokens. Standalone (NOT coupled to the Websites module): any number of
 * forms can point at one theme via forms.theme_id.
 *
 * `tokens` is a partial override set; the effective values a form renders
 * with are produced by App\Support\FormThemeTokens::resolve() (defaults
 * merged with these overrides).
 *
 * @property int $id
 * @property string $name
 * @property array<string, mixed> $tokens
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $createdBy
 * @property-read Collection<int, Form> $forms
 */
class FormTheme extends Model
{
    protected $table = 'form_themes';

    protected $fillable = [
        'name',
        'tokens',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'tokens' => 'array',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function forms(): HasMany
    {
        return $this->hasMany(Form::class, 'theme_id');
    }
}
