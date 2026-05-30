<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /my-work — the operator's personal task list.
 *
 * Difference from Dashboard.attention: the dashboard shows what
 * everyone *should* care about (overdue invoices, SLA breaches,
 * etc.); MyWork shows what *this operator* needs to do this week,
 * grouped by urgency. The same task can absolutely appear in both;
 * they answer different questions.
 *
 * The grouping is computed server-side rather than client-side so
 * the page doesn't have to ship every task to the browser only to
 * filter half of them out.
 */
class MyWorkController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $request->user()->id;

        $tasks = Task::where('assigned_to', $userId)
            ->whereNotIn('status', ['complete', 'cancelled'])
            ->with([
                'project:id,title,colour,status',
                'milestone:id,title',
                'customer:id,name',
            ])
            // Status-based ordering keeps blocked items at the top:
            // they're the work that needs unblocking before anything
            // else can move.
            ->orderByRaw("CASE status
                WHEN 'blocked'     THEN 1
                WHEN 'in_progress' THEN 2
                WHEN 'in_review'   THEN 3
                WHEN 'todo'        THEN 4
                ELSE 5
                END")
            ->orderByRaw('due_at IS NULL, due_at ASC')
            ->get();

        $mapped = $tasks->map(fn (Task $t): array => [
            'id' => $t->id,
            'title' => $t->title,
            'type' => $t->type,
            'type_icon' => $t->type_icon,
            'status' => $t->status,
            'priority' => $t->priority,
            'due_at' => $t->due_at?->toIso8601String(),
            'due_label' => $t->due_at ? $this->formatDue($t->due_at) : null,
            'is_overdue' => $t->due_at instanceof Carbon
                && $t->due_at->isPast()
                && $t->status !== 'complete',
            'project' => $t->project ? [
                'id' => $t->project->id,
                'title' => $t->project->title,
                'colour' => $t->project->colour,
            ] : null,
            'customer_id' => $t->customer_id,
            'customer_name' => $t->customer?->name,
            'milestone_title' => $t->milestone?->title,
            'blocked_reason' => $t->blocked_reason,
        ]);

        $endOfWeek = now()->endOfWeek();
        $tomorrow = now()->addDay()->startOfDay();

        $grouped = [
            'overdue' => $mapped->filter(fn (array $t): bool => $t['is_overdue'])->values(),
            'today' => $mapped->filter(function (array $t): bool {
                if ($t['is_overdue'] || $t['due_at'] === null) {
                    return false;
                }

                return Carbon::parse($t['due_at'])->isToday();
            })->values(),
            'this_week' => $mapped->filter(function (array $t) use ($tomorrow, $endOfWeek): bool {
                if ($t['is_overdue'] || $t['due_at'] === null) {
                    return false;
                }
                $due = Carbon::parse($t['due_at']);

                return $due->between($tomorrow, $endOfWeek) && ! $due->isToday();
            })->values(),
            'in_progress' => $mapped->filter(fn (array $t): bool => $t['status'] === 'in_progress')->values(),
            'in_review' => $mapped->filter(fn (array $t): bool => $t['status'] === 'in_review')->values(),
            // "Upcoming" catches both undated work and work scheduled
            // beyond this week. Collapsed by default in the UI to
            // keep the page focused on what's pressing.
            'upcoming' => $mapped->filter(function (array $t) use ($endOfWeek): bool {
                if ($t['is_overdue']) {
                    return false;
                }
                if ($t['due_at'] === null) {
                    return true;
                }

                return Carbon::parse($t['due_at'])->isAfter($endOfWeek);
            })->values(),
        ];

        $myProjects = Project::whereHas('members', fn ($q) => $q->where('user_id', $userId))
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->with('customer:id,name')
            ->withCount([
                'tasks',
                'tasks as completed_count' => fn ($q) => $q->where('status', 'complete'),
            ])
            ->orderByRaw('due_date IS NULL, due_date ASC')
            ->get()
            ->map(fn (Project $p): array => [
                'id' => $p->id,
                'title' => $p->title,
                'colour' => $p->colour,
                'customer_name' => $p->customer?->name,
                'progress' => $p->progress,
                'due_date' => $p->due_date?->format('d M Y'),
                'is_overdue' => $p->is_overdue,
                'tasks_count' => $p->tasks_count ?? 0,
                'completed_count' => $p->completed_count ?? 0,
            ]);

        return Inertia::render('Internal/MyWork', [
            'grouped' => $grouped,
            'my_projects' => $myProjects,
            'total' => $mapped->count(),
        ]);
    }

    /**
     * Human-friendly due label for the task rows. We commit to
     * "Today"/"Tomorrow"/"3d overdue" rather than raw dates so the
     * eye can scan the list quickly.
     */
    private function formatDue(Carbon $date): string
    {
        $today = now()->startOfDay();
        $day = $date->copy()->startOfDay();

        if ($day->equalTo($today)) {
            return 'Today';
        }
        if ($day->equalTo($today->copy()->addDay())) {
            return 'Tomorrow';
        }
        if ($day->equalTo($today->copy()->subDay())) {
            return 'Yesterday';
        }
        if ($date->isPast()) {
            $days = (int) abs($today->diffInDays($day, false));

            return $days.'d overdue';
        }

        return $date->format('d M');
    }
}
