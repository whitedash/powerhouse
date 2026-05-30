<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A trigger + ordered list of actions. The single entry point is
 * WorkflowEngine::trigger($triggerType, $payload) — controllers
 * don't talk to Workflow rows directly, the engine does the
 * matching + execution.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property string $trigger_type
 * @property array<string, mixed>|null $trigger_config
 * @property int $run_count
 * @property Carbon|null $last_run_at
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $createdBy
 * @property-read Collection<int, WorkflowAction> $actions
 */
class Workflow extends Model
{
    protected $table = 'workflows';

    protected $fillable = [
        'name',
        'description',
        'is_active',
        'trigger_type',
        'trigger_config',
        'run_count',
        'last_run_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'trigger_config' => 'array',
            'last_run_at' => 'datetime',
            'run_count' => 'integer',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Actions executed in sort_order by WorkflowEngine::trigger().
     * The ORDER BY here is what makes "context accumulation" work:
     * create_lead must run before create_task so the task can
     * inherit lead_id from the context.
     */
    public function actions(): HasMany
    {
        return $this->hasMany(WorkflowAction::class)->orderBy('sort_order');
    }
}
