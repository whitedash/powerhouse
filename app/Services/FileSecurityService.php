<?php

namespace App\Services;

use App\Models\ProjectFile;
use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Security layer for project file uploads.
 *
 * Storage itself is delegated to FileUploadService (mandatory per the
 * project rules — UUID names, private disk, size + MIME allow-list,
 * image re-encode/SVG sanitise). This service adds two things on top:
 *   1. A hard blocklist of executable/script extensions + MIME types that
 *      must NEVER be accepted, checked before anything touches disk.
 *   2. Asynchronous ClamAV scanning of the stored file (via ScanProjectFile).
 */
class FileSecurityService
{
    public const MAX_SIZE_MB = 10;

    /** Storage context (config/uploads.php) handed to FileUploadService. */
    private const CONTEXT = 'project_file';

    /** Never accepted, regardless of the allow-list/setting. */
    public const BLOCKED_MIMES = [
        'application/x-php',
        'application/x-httpd-php',
        'text/x-php',
        'application/x-executable',
        'application/x-sh',
        'application/x-bash',
        'text/javascript',
        'application/javascript',
        'application/x-msdownload',
        'application/x-msdos-program',
    ];

    /** Never accepted, regardless of MIME. */
    public const BLOCKED_EXTENSIONS = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'phar',
        'exe', 'bat', 'cmd', 'sh', 'bash', 'js', 'vbs',
        'ps1', 'msi', 'dll', 'com', 'scr',
    ];

    /**
     * Hard pre-checks that run before storage. FileUploadService enforces
     * the positive allow-list + size; this enforces the negative blocklist
     * (defence in depth — a blocked type can't slip through even if the
     * allow-list is later widened).
     *
     * @throws \InvalidArgumentException
     */
    public function validate(UploadedFile $file): void
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if (in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
            throw new \InvalidArgumentException('File type not allowed: .'.$ext);
        }

        $realMime = (string) $file->getMimeType();
        if (in_array($realMime, self::BLOCKED_MIMES, true)) {
            throw new \InvalidArgumentException('File type not permitted.');
        }
    }

    /**
     * Store via FileUploadService and return the metadata ProjectFile needs.
     * The configured max size comes from the file.max_size_mb setting.
     *
     * @return array{filename: string, stored_name: string, path: string, mime_type: string, size_bytes: int}
     *
     * @throws ValidationException from FileUploadService on oversize / disallowed MIME
     */
    public function store(UploadedFile $file): array
    {
        $maxBytes = (int) Setting::getValue('file.max_size_mb', self::MAX_SIZE_MB) * 1024 * 1024;

        $path = app(FileUploadService::class)->store($file, self::CONTEXT, ['max_size' => $maxBytes]);

        return [
            'filename' => $file->getClientOriginalName(),
            'stored_name' => basename($path),
            'path' => $path,
            'mime_type' => (string) $file->getClientMimeType(),
            'size_bytes' => (int) $file->getSize(),
        ];
    }

    /**
     * Scan a stored file with ClamAV. Returns one of:
     *   clean | infected | error | skipped (ClamAV not installed).
     */
    public function scan(ProjectFile $file): string
    {
        $out = [];
        $code = 0;
        exec('which clamdscan 2>/dev/null', $out, $code);
        if ($code !== 0) {
            return 'skipped';
        }

        if ($file->path === '' || ! Storage::disk('private')->exists($file->path)) {
            return 'error';
        }

        $fullPath = Storage::disk('private')->path($file->path);

        $scanOut = [];
        $returnCode = 0;
        exec('clamdscan --no-summary '.escapeshellarg($fullPath), $scanOut, $returnCode);

        // clamdscan: 0 = clean, 1 = virus found, 2 = error.
        return match ($returnCode) {
            0 => 'clean',
            1 => 'infected',
            default => 'error',
        };
    }
}
