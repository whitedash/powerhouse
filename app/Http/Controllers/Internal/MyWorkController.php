<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use App\Services\GoogleCalendarService;
use Illuminate\Http\JsonResponse;
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
            'google_connected' => $request->user()->google_sync_enabled,
        ]);
    }

    /**
     * Calendar feed for the MyWork "Calendar" tab. Returns this user's
     * open tasks (as events) merged with their Google Calendar events,
     * scoped to the date range FullCalendar asks for. Always JSON —
     * FullCalendar fetches it directly, not through Inertia.
     */
    public function calendar(Request $request): JsonResponse
    {
        $user = $request->user();

        $start = Carbon::parse($request->string('start')->toString() ?: now()->startOfMonth()->toDateString());
        $end = Carbon::parse($request->string('end')->toString() ?: now()->endOfMonth()->toDateString());

        $tasks = Task::where('assigned_to', $user->id)
            ->whereNotIn('status', ['complete', 'cancelled'])
            ->where(function ($q) use ($start, $end): void {
                $q->whereBetween('due_at', [$start, $end])
                    ->orWhereBetween('start_at', [$start, $end]);
            })
            ->with('project:id,title,colour')
            ->get()
            ->map(fn (Task $t): array => [
                'id' => 'task_'.$t->id,
                'source' => 'powerhouse',
                'title' => $t->title,
                'start' => $t->is_all_day
                    ? $t->due_at?->format('Y-m-d')
                    : $t->start_at?->toIso8601String(),
                'end' => $t->is_all_day
                    ? null
                    : $t->end_at?->toIso8601String(),
                'allDay' => $t->is_all_day,
                // Project colour anchors the event to its project; loose
                // tasks fall back to amber, meetings to purple.
                'colour' => $t->project !== null
                    ? $t->project->colour
                    : ($t->type === 'meeting' ? '#8B5CF6' : '#F59E0B'),
                'type' => $t->type,
                'status' => $t->status,
                'priority' => $t->priority,
                'location' => $t->location,
                'project_id' => $t->project_id,
                'project_title' => $t->project?->title,
                'url' => $t->project_id
                    ? '/projects/'.$t->project_id
                    : '/activities/'.$t->id,
                'editable' => true,
            ]);

        $gcalEvents = [];
        if ($user->google_sync_enabled && $user->google_access_token !== null) {
            $gcalEvents = (new GoogleCalendarService($user))->getEvents($start, $end);
        }

        return response()->json([
            'events' => $tasks->concat($gcalEvents)->values(),
            'google_connected' => $user->google_sync_enabled,
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
