<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $customer_id
 * @property int|null $project_id
 * @property int|null $milestone_id
 * @property int|null $lead_id
 * @property int|null $contact_id
 * @property int|null $parent_task_id
 * @property int|null $assigned_to
 * @property int|null $created_by
 * @property string $title
 * @property string $type
 * @property string|null $description
 * @property string $priority
 * @property string $status
 * @property Carbon|null $due_date
 * @property Carbon|null $due_at
 * @property Carbon|null $completed_at
 * @property string|null $outcome
 * @property int|null $duration_minutes
 * @property string|null $estimated_hours
 * @property int $sort_order
 * @property string|null $blocked_reason
 * @property bool $is_pinned
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer|null $customer
 * @property-read Project|null $project
 * @property-read Milestone|null $milestone
 * @property-read Contact|null $contact
 * @property-read Task|null $parentTask
 * @property-read Collection<int, Task> $childTasks
 * @property-read Collection<int, Note> $notes
 * @property-read Collection<int, TimeEntry> $timeEntries
 * @property-read User|null $assignedTo
 * @property-read User|null $createdBy
 * @property-read bool $is_overdue
 * @property-read string $type_icon
 * @property-read string $type_colour
 * @property-read int $total_minutes
 * @property-read float $total_hours
 * @property-read bool $is_pm_task
 */
class Task extends Model
{
    protected $fillable = [
        'customer_id',
        'project_id',
        'milestone_id',
        'lead_id',
        'contact_id',
        'parent_task_id',
        'assigned_to',
        'created_by',
        'title',
        'type',
        'description',
        'priority',
        // The PM sprint widened status to:
        //   todo | in_progress | in_review | blocked | complete | cancelled
        // The legacy CRM still treats {todo, complete} as "open" vs
        // "done"; the new kanban needs the full set.
        'status',
        'due_date',
        'due_at',
        'completed_at',
        'outcome',
        'duration_minutes',
        'estimated_hours',
        'sort_order',
        'blocked_reason',
        'is_pinned',
    ];

    protected function casts(): array
    {
        return [
            // due_date is kept as legacy for any older code path that
            // still reads it; due_at is the canonical schedule field.
            'due_date' => 'date',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'is_pinned' => 'boolean',
            'duration_minutes' => 'integer',
            'estimated_hours' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Parent task this one was spawned from. The activity detail page
     * surfaces children under "Linked tasks" — they were created from
     * the parent's "Create linked task" affordance with parent_task_id
     * pre-filled.
     */
    public function parentTask(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_task_id');
    }

    public function childTasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_task_id');
    }

    /**
     * Notes scoped to this task. A note can also be customer-scoped
     * (task_id null), which is how the legacy customer-page note panel
     * keeps working unchanged.
     */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    /**
     * Parent project — null for legacy CRM tasks created before the
     * project management feature shipped, and for any task that the
     * operator chooses to keep loose (e.g. a personal "Quick task"
     * logged from the MyWork page).
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * Overdue = scheduled in the past with no completion timestamp.
     * Notes have no due_at by design, so they can never be "overdue" —
     * the !$this->completed_at && $this->due_at conjunction handles
     * that without an explicit type check.
     */
    protected function isOverdue(): Attribute
    {
        return Attribute::get(fn (): bool => ! $this->completed_at
            && $this->due_at instanceof Carbon
            && $this->due_at->isPast());
    }

    /**
     * Aggregate of every logged minute on this task. Hydrated lazily
     * — the kanban card uses this for the "2h logged" stamp, and the
     * project Tasks tab for the Hours column.
     */
    protected function totalMinutes(): Attribute
    {
        return Attribute::get(fn (): int => (int) $this->timeEntries()->sum('minutes'));
    }

    protected function totalHours(): Attribute
    {
        return Attribute::get(fn (): float => round($this->total_minutes / 60, 2));
    }

    /**
     * Marker used by the activity feed + customer Show.vue to decide
     * whether to render the project chip on a task row. CRM-only tasks
     * (no project) keep the original look.
     */
    protected function isPmTask(): Attribute
    {
        return Attribute::get(fn (): bool => $this->project_id !== null);
    }

    /**
     * Tabler icon name keyed by activity type. Frontend resolves the
     * Vue component from the name so we don't ship an import map server-
     * side. Lives on the model rather than the controller because the
     * Dashboard list and Customer timeline both need it.
     */
    protected function typeIcon(): Attribute
    {
        return Attribute::get(fn (): string => match ($this->type) {
            'call' => 'phone',
            'email' => 'mail',
            'meeting' => 'users',
            'note' => 'notes',
            default => 'checkbox',
        });
    }

    /**
     * CSS-variable colour for the activity icon background. Source of
     * truth for the timeline + the Dashboard list — change here, both
     * surfaces update.
     */
    protected function typeColour(): Attribute
    {
        return Attribute::get(fn (): string => match ($this->type) {
            'call' => 'var(--success)',
            'email' => 'var(--info)',
            'meeting' => 'var(--accent)',
            'note' => 'var(--text-tertiary)',
            default => 'var(--text-secondary)',
        });
    }
}
