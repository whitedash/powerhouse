<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per workflow firing — the structured, status-bearing run ledger
 * written by WorkflowEngine. Append-only (created_at only, no updated_at, no
 * soft deletes), like activity_log. The row is written OUTSIDE the per-workflow
 * transaction, so status=failed rows survive a run whose actions rolled back.
 *
 * @property int $id
 * @property int|null $workflow_id Null once the workflow is hard-deleted (nullOnDelete) — the run row is kept as an orphaned audit record.
 * @property string $trigger_type
 * @property int|null $trigger_entity_id
 * @property string $status succeeded|failed|skipped
 * @property string|null $error
 * @property int|null $duration_ms
 * @property array<string, mixed>|null $context_summary
 * @property list<array<string, mixed>>|null $actions
 * @property Carbon|null $created_at
 * @property-read Workflow|null $workflow
 */
class WorkflowRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'workflow_id',
        'trigger_type',
        'trigger_entity_id',
        'status',
        'error',
        'duration_ms',
        'context_summary',
        'actions',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'context_summary' => 'array',
            'actions' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }
}
