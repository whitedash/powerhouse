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
 * @property bool $is_pinned
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer|null $customer
 * @property-read Contact|null $contact
 * @property-read Task|null $parentTask
 * @property-read Collection<int, Task> $childTasks
 * @property-read Collection<int, Note> $notes
 * @property-read User|null $assignedTo
 * @property-read User|null $createdBy
 * @property-read bool $is_overdue
 * @property-read string $type_icon
 * @property-read string $type_colour
 */
class Task extends Model
{
    protected $fillable = [
        'customer_id',
        'contact_id',
        'parent_task_id',
        'assigned_to',
        'created_by',
        'title',
        'type',
        'description',
        'priority',
        'status',
        'due_date',
        'due_at',
        'completed_at',
        'outcome',
        'duration_minutes',
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
