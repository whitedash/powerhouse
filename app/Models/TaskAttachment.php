<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $task_id
 * @property string $filename
 * @property string $path
 * @property string $mime_type
 * @property int $size_bytes
 * @property int $uploaded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Task $task
 * @property-read User|null $uploadedBy
 * @property-read string $formatted_size
 */
class TaskAttachment extends Model
{
    protected $fillable = [
        'task_id',
        'filename',
        'path',
        'mime_type',
        'size_bytes',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Human-readable size — shares ProjectFile's formatter so the
     * unified project Files tab renders task attachments identically.
     */
    protected function formattedSize(): Attribute
    {
        return Attribute::get(fn (): string => ProjectFile::humanSize($this->size_bytes));
    }
}
