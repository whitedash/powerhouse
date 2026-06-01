<?php

namespace App\Jobs;

use App\Models\ProjectFile;
use App\Models\User;
use App\Notifications\InfectedFileDetected;
use App\Services\FileSecurityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Async ClamAV scan of an uploaded project file. Updates scan_status; on
 * an infected result the file is purged from disk and super_admins are
 * notified. Runs on the default queue (a worker must be processing it).
 */
class ScanProjectFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 30;

    public function __construct(public int $fileId) {}

    public function handle(FileSecurityService $security): void
    {
        $file = ProjectFile::find($this->fileId);
        if ($file === null) {
            return;
        }

        $result = $security->scan($file);

        $file->update([
            'scan_status' => $result,
            'scan_completed_at' => now(),
            'scan_result' => match ($result) {
                'infected' => 'File flagged by ClamAV',
                'error' => 'Scan error — review manually',
                'skipped' => 'ClamAV not available',
                default => null,
            },
        ]);

        if ($result !== 'infected') {
            return;
        }

        // Purge the malicious file from disk and blank the path so the
        // download route can never serve it.
        if ($file->path !== '') {
            Storage::disk('private')->delete($file->path);
        }

        $file->update([
            'path' => '',
            'scan_result' => 'DELETED: File was flagged as malicious by ClamAV',
        ]);

        // Eager-load the relations the notification reads, so it renders
        // even under preventLazyLoading in the queue worker.
        $file->loadMissing('uploadedBy', 'project');

        User::where('role', 'super_admin')->each(
            fn (User $u) => $u->notify(new InfectedFileDetected($file))
        );

        Log::alert('Malicious file deleted', [
            'file_id' => $file->id,
            'filename' => $file->filename,
            'project_id' => $file->project_id,
            'uploaded_by' => $file->uploaded_by,
        ]);
    }
}
