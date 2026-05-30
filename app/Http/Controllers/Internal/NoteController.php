<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Note;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Standalone CRUD for notes used by the activity detail page. The
 * legacy /customers/{id}/notes endpoint on InternalCustomerController
 * still drives the customer page's note panel — keeping both means
 * we don't need to migrate that surface in the same sprint.
 */
class NoteController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            // task_id is the link that makes this thread show up on
            // the activity detail page. Optional so the same endpoint
            // could be reused for customer-only notes later.
            'task_id' => ['nullable', 'integer', 'exists:tasks,id'],
            'body' => ['required', 'string', 'max:10000'],
            'is_pinned' => ['nullable', 'boolean'],
        ]);

        // Authorise against the parent customer — staffers without
        // view access to the customer shouldn't be writing notes to it.
        $customer = Customer::findOrFail($data['customer_id']);
        Gate::authorize('view', $customer);

        // If a task_id was supplied, double-check it belongs to the
        // same customer (otherwise we'd silently scope this note to
        // the wrong account from a forged form post).
        if (! empty($data['task_id'])) {
            $task = Task::findOrFail($data['task_id']);
            abort_unless(
                $task->customer_id === null || $task->customer_id === $customer->id,
                422,
                'Note task does not belong to the chosen customer.',
            );
        }

        $note = DB::transaction(function () use ($data, $request) {
            $note = Note::create([
                'customer_id' => $data['customer_id'],
                'task_id' => $data['task_id'] ?? null,
                'created_by' => $request->user()->id,
                // Default type "internal" matches the legacy enum on
                // the notes table — these aren't call/meeting logs.
                'type' => 'internal',
                'body' => $data['body'],
                'is_pinned' => (bool) ($data['is_pinned'] ?? false),
            ]);

            $this->log($request, 'note.created', $note->id, after: [
                'customer_id' => $note->customer_id,
                'task_id' => $note->task_id,
            ]);

            return $note;
        });

        return back()->with('success', 'Note added.');
    }

    public function update(int $id, Request $request): RedirectResponse
    {
        $note = Note::findOrFail($id);
        $user = $request->user();

        // Only the author or a super_admin can edit a note — quoting
        // someone else's words then editing them would be a bad audit
        // trail.
        abort_unless(
            $note->created_by === $user->id || $user->isSuperAdmin(),
            403,
            'You can only edit notes you authored.',
        );

        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'is_pinned' => ['nullable', 'boolean'],
        ]);

        $before = ['body' => $note->body, 'is_pinned' => $note->is_pinned];

        $note->update([
            'body' => $data['body'],
            'is_pinned' => (bool) ($data['is_pinned'] ?? $note->is_pinned),
        ]);

        $this->log($request, 'note.updated', $note->id, before: $before, after: [
            'body' => $note->body,
            'is_pinned' => $note->is_pinned,
        ]);

        return back()->with('success', 'Note updated.');
    }

    public function destroy(int $id, Request $request): RedirectResponse
    {
        $note = Note::findOrFail($id);
        $user = $request->user();

        abort_unless(
            $note->created_by === $user->id || $user->isSuperAdmin(),
            403,
            'You can only delete notes you authored.',
        );

        $snapshot = [
            'customer_id' => $note->customer_id,
            'task_id' => $note->task_id,
            'body_length' => mb_strlen((string) $note->body),
        ];
        $note->delete();

        $this->log($request, 'note.deleted', $id, before: $snapshot);

        return back()->with('success', 'Note deleted.');
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function log(
        Request $request,
        string $action,
        int $noteId,
        ?array $before = null,
        ?array $after = null,
    ): void {
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'user_role' => $request->user()->role,
            'action' => $action,
            'entity_type' => 'note',
            'entity_id' => $noteId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
