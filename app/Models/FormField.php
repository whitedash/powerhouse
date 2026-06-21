<?php

namespace App\Models;

use App\Enums\FieldWidth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single input on a form. Relationally stored (not embedded
 * in forms.json) so the drag-to-reorder UI can persist updates
 * with a single record per row and the public submit endpoint
 * can validate per field without parsing a JSON blob.
 *
 * @property int $id
 * @property int $form_id
 * @property int|null $form_step_id
 * @property string $label
 * @property string|null $content
 * @property string $field_key
 * @property string $type
 * @property string|null $placeholder
 * @property string|null $default_value
 * @property array<int, string>|null $options
 * @property bool $is_required
 * @property FieldWidth $width
 * @property array<string, mixed>|null $validation_rules
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Form $form
 * @property-read FormStep|null $step
 */
class FormField extends Model
{
    protected $table = 'form_fields';

    protected $fillable = [
        'form_id',
        'form_step_id',
        'label',
        'content',
        'field_key',
        'type',
        'placeholder',
        'default_value',
        'options',
        'is_required',
        'width',
        'validation_rules',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'form_step_id' => 'integer',
            'content' => 'string',
            'options' => 'array',
            'validation_rules' => 'array',
            'is_required' => 'boolean',
            'width' => FieldWidth::class,
            'sort_order' => 'integer',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(FormStep::class, 'form_step_id');
    }
}
