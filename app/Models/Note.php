<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $customer_id
 * @property int|null $task_id
 * @property int|null $lead_id
 * @property int|null $created_by
 * @property string $type
 * @property string $body
 * @property bool $is_pinned
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer|null $customer
 * @property-read Task|null $task
 * @property-read Lead|null $lead
 * @property-read User|null $createdBy
 * @property-read User|null $author
 */
class Note extends Model
{
    protected $fillable = [
        'customer_id',
        'task_id',
        'lead_id',
        'created_by',
        'type',
        'body',
        'is_pinned',
    ];

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Lead this note is attached to. Notes are normally either
     * customer-scoped or task-scoped; lead-scoped is a third
     * mode used by the leads pipeline detail page. On lead
     * conversion the controller re-targets these to the new
     * customer_id and clears lead_id.
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Alias for createdBy() — the activity-detail payload uses
     * note.author across the UI, and `author` reads cleaner than
     * `createdBy` when you're scanning a notes thread.
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
