<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $task_id
 * @property string $filename
 * @property string $stored_name
 * @property string $path
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $scan_status
 * @property Carbon|null $scan_completed_at
 * @property string|null $scan_result
 * @property int $uploaded_by
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read Task|null $task
 * @property-read User|null $uploadedBy
 * @property-read string $formatted_size
 * @property-read bool $is_downloadable
 * @property-read string $type_icon
 */
class ProjectFile extends Model
{
    protected $fillable = [
        'project_id',
        'task_id',
        'filename',
        'stored_name',
        'path',
        'mime_type',
        'size_bytes',
        'scan_status',
        'scan_completed_at',
        'scan_result',
        'uploaded_by',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'scan_completed_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    protected function formattedSize(): Attribute
    {
        return Attribute::get(fn (): string => self::humanSize($this->size_bytes));
    }

    /**
     * Downloadable only once scanning clears it (or was skipped because
     * ClamAV isn't installed). pending/infected/error are withheld.
     */
    protected function isDownloadable(): Attribute
    {
        return Attribute::get(fn (): bool => in_array($this->scan_status, ['clean', 'skipped'], true));
    }

    /**
     * Coarse file-category key (pdf/doc/xls/ppt/image/zip/text/file) for
     * the frontend icon map — the UI renders @tabler/icons-vue components,
     * there is no icon webfont.
     */
    protected function typeIcon(): Attribute
    {
        return Attribute::get(fn (): string => self::iconKeyFor($this->mime_type));
    }

    public static function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1048576, 1).' MB';
    }

    public static function iconKeyFor(string $mime): string
    {
        return match (true) {
            str_contains($mime, 'pdf') => 'pdf',
            str_contains($mime, 'word'), str_contains($mime, 'document') => 'doc',
            str_contains($mime, 'sheet'), str_contains($mime, 'excel') => 'xls',
            str_contains($mime, 'presentation'), str_contains($mime, 'powerpoint') => 'ppt',
            str_contains($mime, 'image') => 'image',
            str_contains($mime, 'zip'), str_contains($mime, 'compressed') => 'zip',
            str_contains($mime, 'text'), str_contains($mime, 'csv') => 'text',
            default => 'file',
        };
    }
}
