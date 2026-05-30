<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $task_id
 * @property int $project_id
 * @property int $user_id
 * @property int $minutes
 * @property string|null $description
 * @property Carbon $logged_at
 * @property bool $is_billable
 * @property string|null $hourly_rate
 * @property int|null $invoice_line_id
 * @property int|null $invoice_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Task $task
 * @property-read Project $project
 * @property-read User $user
 * @property-read InvoiceLine|null $invoiceLine
 * @property-read Invoice|null $invoice
 * @property-read float $hours
 * @property-read float $effective_rate
 * @property-read float $billable_amount
 */
class TimeEntry extends Model
{
    protected $fillable = [
        'task_id',
        'project_id',
        'user_id',
        'minutes',
        'description',
        'logged_at',
        'is_billable',
        'hourly_rate',
        'invoice_line_id',
        'invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'logged_at' => 'date',
            'is_billable' => 'boolean',
            'hourly_rate' => 'decimal:2',
            'minutes' => 'integer',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Storage is minutes; the UI talks in hours. Rounded to two
     * decimal places — sub-minute precision is meaningless here
     * and would create cents-of-£ drift on invoice totals.
     */
    protected function hours(): Attribute
    {
        return Attribute::get(fn (): float => round($this->minutes / 60, 2));
    }

    /**
     * Entry-level rate wins when set; otherwise the project's
     * default. Zero fallback so multiplication is always safe
     * even when neither side has a rate configured.
     */
    protected function effectiveRate(): Attribute
    {
        return Attribute::get(function (): float {
            if ($this->hourly_rate !== null) {
                return (float) $this->hourly_rate;
            }
            // phpstan sees TimeEntry::project as non-null because
            // project_id is NOT NULL in the schema — drop the nullsafe.
            $rate = $this->project->hourly_rate;

            return $rate !== null ? (float) $rate : 0.0;
        });
    }

    protected function billableAmount(): Attribute
    {
        return Attribute::get(fn (): float => round(
            $this->hours * $this->effective_rate,
            2
        ));
    }
}
