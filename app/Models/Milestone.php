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
 * @property int $project_id
 * @property string $title
 * @property string|null $description
 * @property Carbon|null $due_date
 * @property string $status
 * @property int $sort_order
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read Collection<int, Task> $tasks
 * @property-read int $progress
 * @property-read bool $is_overdue
 */
class Milestone extends Model
{
    protected $fillable = [
        'project_id',
        'title',
        'description',
        'due_date',
        'status',
        'sort_order',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Same completion logic as Project but scoped to the milestone's
     * tasks. The Overview tab progress bars draw from here.
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

    protected function isOverdue(): Attribute
    {
        return Attribute::get(fn (): bool => $this->due_date instanceof Carbon
            && $this->due_date->isPast()
            && $this->status !== 'completed');
    }
}
