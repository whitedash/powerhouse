<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One step in a workflow. action_type picks which
 * WorkflowEngine::action*() handler runs; config is the
 * action-specific JSON. See the workflows migration docblock
 * for shape examples.
 *
 * @property int $id
 * @property int $workflow_id
 * @property string $action_type
 * @property array<string, mixed> $config
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workflow $workflow
 */
class WorkflowAction extends Model
{
    protected $table = 'workflow_actions';

    protected $fillable = [
        'workflow_id',
        'action_type',
        'config',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }
}
