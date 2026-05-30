<?php

namespace App\Models;

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
 * @property string $label
 * @property string $field_key
 * @property string $type
 * @property string|null $placeholder
 * @property string|null $default_value
 * @property array<int, string>|null $options
 * @property bool $is_required
 * @property array<string, mixed>|null $validation_rules
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Form $form
 */
class FormField extends Model
{
    protected $table = 'form_fields';

    protected $fillable = [
        'form_id',
        'label',
        'field_key',
        'type',
        'placeholder',
        'default_value',
        'options',
        'is_required',
        'validation_rules',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'validation_rules' => 'array',
            'is_required' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }
}
