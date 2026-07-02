<?php

namespace App\Http\Controllers\Internal;

use App\Enums\ScopeArea;
use App\Http\Controllers\Controller;
use App\Jobs\ScanProjectFile;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Services\FileSecurityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Project file management — upload (with hard blocklist + async ClamAV
 * scan), secure auth-gated download from the private disk, and delete.
 * Staff-gated at the route; each method re-checks the staff gate.
 */
class ProjectFileController extends Controller
{
    public function upload(Request $request, int $projectId): RedirectResponse
    {
        Gate::authorize('viewAny', Company::class);
        $project = Project::findOrFail($projectId);
        $this->authorizeScopeItem(ScopeArea::Projects, $project);

        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => ['required', 'file'],
            'description' => ['nullable', 'string', 'max:255'],
            'task_id' => ['nullable', 'integer', 'exists:tasks,id'],
        ]);

        $security = app(FileSecurityService::class);
        $uploaded = [];
        $errors = [];

        foreach ($request->file('files') as $file) {
            try {
                $security->validate($file);
                $stored = $security->store($file);

                $projectFile = ProjectFile::create([
                    ...$stored,
                    'project_id' => $project->id,
                    'task_id' => $request->integer('task_id') ?: null,
                    'description' => $request->string('description')->toString() ?: null,
                    'scan_status' => 'pending',
                    'uploaded_by' => $request->user()->id,
                ]);

                ScanProjectFile::dispatch($projectFile->id);

                $uploaded[] = $projectFile->filename;
            } catch (\Throwable $e) {
                $errors[] = $file->getClientOriginalName().': '.$e->getMessage();
            }
        }

        if ($uploaded !== []) {
            $this->log($request, 'project.files_uploaded', $project->id, ['count' => count($uploaded)]);
        }

        if ($errors !== []) {
            return back()->with('warning', count($uploaded).' file(s) uploaded. '
                .count($errors).' rejected: '.implode(', ', $errors));
        }

        return back()->with('success', count($uploaded).' file(s) uploaded. Scanning in progress.');
    }

    public function download(int $fileId, Request $request): StreamedResponse|RedirectResponse
    {
        Gate::authorize('viewAny', Company::class);
        $file = ProjectFile::findOrFail($fileId);
        $this->authorizeScopeItem(ScopeArea::Projects, Project::findOrFail($file->project_id));

        if ($file->scan_status === 'infected') {
            abort(403, 'This file has been flagged as malicious.');
        }

        if ($file->scan_status === 'pending') {
            return back()->with('error', 'File is still being scanned. Please try again in a moment.');
        }

        abort_unless(
            $file->path !== '' && Storage::disk('private')->exists($file->path),
            404,
            'File not found.',
        );

        $this->log($request, 'project.file_downloaded', $file->project_id, [
            'file_id' => $file->id,
            'filename' => $file->filename,
        ]);

        return Storage::disk('private')->download($file->path, $file->filename);
    }

    public function destroy(int $fileId, Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Company::class);
        $file = ProjectFile::findOrFail($fileId);
        $this->authorizeScopeItem(ScopeArea::Projects, Project::findOrFail($file->project_id));

        if ($file->path !== '') {
            Storage::disk('private')->delete($file->path);
        }

        $snapshot = ['file_id' => $file->id, 'filename' => $file->filename];
        $projectId = $file->project_id;
        $file->delete();

        $this->log($request, 'project.file_deleted', $projectId, $snapshot);

        return back()->with('success', 'File deleted.');
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function log(Request $request, string $action, int $projectId, array $after): void
    {
        ActivityLog::create([
            'user_id' => $request->user()?->id,
            'user_role' => $request->user()?->role,
            'action' => $action,
            'entity_type' => 'project',
            'entity_id' => $projectId,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
