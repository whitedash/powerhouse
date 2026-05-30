<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $customer_id
 * @property string $title
 * @property string|null $description
 * @property string $status
 * @property string $priority
 * @property string $colour
 * @property Carbon|null $start_date
 * @property Carbon|null $due_date
 * @property string|null $budget
 * @property string|null $hourly_rate
 * @property int|null $project_lead
 * @property int $created_by
 * @property Carbon|null $completed_at
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer|null $customer
 * @property-read User|null $lead
 * @property-read User $createdBy
 * @property-read Collection<int, User> $members
 * @property-read Collection<int, Milestone> $milestones
 * @property-read Collection<int, Task> $tasks
 * @property-read Collection<int, TimeEntry> $timeEntries
 * @property-read int $progress
 * @property-read bool $is_overdue
 * @property-read int $total_billable_minutes
 * @property-read int $unbilled_minutes
 * @property-read string $status_colour
 */
class Project extends Model
{
    protected $fillable = [
        'customer_id',
        'title',
        'description',
        'status',
        'priority',
        'colour',
        'start_date',
        'due_date',
        'budget',
        'hourly_rate',
        'project_lead',
        'created_by',
        'completed_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'archived_at' => 'datetime',
            'budget' => 'decimal:2',
            'hourly_rate' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'project_lead');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Many-to-many membership. The pivot carries a role
     * (`lead`/`member`/`viewer`) and a joined_at timestamp; we
     * surface both via withPivot so the team card on the project
     * detail can show "Lead since 12 Apr" without an extra query.
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
            ->withPivot(['role', 'joined_at']);
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class)->orderBy('sort_order');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * Project-wide completion percentage, computed across all tasks
     * regardless of milestone. Returns 0 for empty projects so the
     * UI never has to special-case missing data.
     */
    protected function progress(): Attribute
    {
        return Attribute::get(function (): int {
            $total = $this->tasks()->count();
            if ($total === 0) {
                return 0;
            }
            $done = $this->tasks()->where('status', 'complete')->count();

            return (int) round(($done / $total) * 100);
        });
    }

    /**
     * Overdue = due in the past AND still actionable. Completed and
     * cancelled projects don't show an overdue badge even when their
     * due_date has slipped — they're terminal states.
     */
    protected function isOverdue(): Attribute
    {
        return Attribute::get(fn (): bool => $this->due_date instanceof Carbon
            && $this->due_date->isPast()
            && ! in_array($this->status, ['completed', 'cancelled'], true));
    }

    protected function totalBillableMinutes(): Attribute
    {
        return Attribute::get(fn (): int => (int) $this->timeEntries()
            ->where('is_billable', true)
            ->sum('minutes'));
    }

    protected function unbilledMinutes(): Attribute
    {
        return Attribute::get(fn (): int => (int) $this->timeEntries()
            ->where('is_billable', true)
            ->whereNull('invoice_id')
            ->sum('minutes'));
    }

    /**
     * CSS-variable colour for the status badge. Lives on the model
     * so the project card grid, the detail header and the customer
     * Projects tab all draw the same colour.
     */
    protected function statusColour(): Attribute
    {
        return Attribute::get(fn (): string => match ($this->status) {
            'active' => 'var(--success)',
            'on_hold' => 'var(--warning)',
            'completed' => 'var(--info)',
            'cancelled' => 'var(--danger)',
            default => 'var(--text-tertiary)',
        });
    }
}
