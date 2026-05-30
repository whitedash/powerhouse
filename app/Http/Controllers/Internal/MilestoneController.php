<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Milestone;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * CRUD for project milestones. The columns on the kanban (milestone
 * mode) draw from this resource; the reorder endpoint is what the
 * drag-and-drop UI calls when the operator rearranges milestones.
 */
class MilestoneController extends Controller
{
    private const STATUSES = ['pending', 'in_progress', 'completed'];

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $data = $request->validate([
            'project_id' => 'required|integer|exists:projects,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'due_date' => 'nullable|date',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $milestone = DB::transaction(function () use ($data, $request) {
            // If the operator didn't pass a sort_order, drop the new
            // milestone at the end of the existing list. Computed
            // inside the transaction so a parallel insert can't
            // grab the same slot.
            if (! isset($data['sort_order'])) {
                $max = (int) Milestone::where('project_id', $data['project_id'])
                    ->max('sort_order');
                $data['sort_order'] = $max + 1;
            }

            $milestone = Milestone::create($data);

            $this->log($request, 'milestone.created', $milestone, after: [
                'title' => $milestone->title,
                'project_id' => $milestone->project_id,
            ]);

            return $milestone;
        });

        return back()->with('success', 'Milestone added.');
    }

    public function update(int $id, Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $milestone = Milestone::findOrFail($id);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'due_date' => 'nullable|date',
            'status' => ['required', Rule::in(self::STATUSES)],
        ]);

        $before = $milestone->only(['title', 'status', 'due_date']);

        // Auto-stamp completed_at on transition; clear it on revert
        // so we don't carry stale completion timestamps.
        $milestone->update([
            ...$data,
            'completed_at' => $data['status'] === 'completed'
                ? ($milestone->completed_at ?? now())
                : null,
        ]);

        $this->log($request, 'milestone.updated', $milestone, before: $before, after: [
            'title' => $milestone->title,
            'status' => $milestone->status,
            'due_date' => $milestone->due_date?->toDateString(),
        ]);

        return back()->with('success', 'Milestone updated.');
    }

    public function destroy(int $id, Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $milestone = Milestone::findOrFail($id);

        DB::transaction(function () use ($milestone, $request) {
            // Detach tasks rather than deleting them — work logged
            // against a milestone shouldn't disappear when the
            // milestone does. Operator can re-bucket them later.
            Task::where('milestone_id', $milestone->id)
                ->update(['milestone_id' => null]);

            $title = $milestone->title;
            $projectId = $milestone->project_id;
            $milestone->delete();

            $this->log($request, 'milestone.deleted', $milestone, before: [
                'title' => $title,
                'project_id' => $projectId,
            ]);
        });

        return back()->with('success', 'Milestone removed.');
    }

    /**
     * Bulk reorder — fired by the kanban after a drag. We update each
     * milestone's sort_order inside a transaction so the UI sees a
     * consistent order on the next render.
     */
    public function reorder(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|integer|exists:milestones,id',
            'items.*.sort_order' => 'required|integer|min:0',
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['items'] as $item) {
                Milestone::where('id', $item['id'])
                    ->update(['sort_order' => $item['sort_order']]);
            }
        });

        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function log(Request $request, string $action, Milestone $milestone, ?array $before = null, ?array $after = null): void
    {
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'user_role' => $request->user()->role,
            'action' => $action,
            // entity_type is 'project' because milestones belong to
            // projects in the audit log — easier to filter the
            // project Show "Activity" tab that way.
            'entity_type' => 'project',
            'entity_id' => $milestone->project_id,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
