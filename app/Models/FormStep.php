<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An ordered, labelled step within a multi-step form.
 *
 * The label feeds the respondent progress indicator ("Step 2 — Your
 * Details"); sort_order orders the steps. Fields are assigned to a step via
 * form_fields.form_step_id (nullable — legacy single-step forms leave it null).
 *
 * @property int $id
 * @property int $form_id
 * @property string $label
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Form $form
 * @property-read Collection<int, FormField> $fields
 */
class FormStep extends Model
{
    protected $table = 'form_steps';

    protected $fillable = [
        'form_id',
        'label',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class);
    }
}
