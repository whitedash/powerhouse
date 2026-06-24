<?php

namespace App\Http\Controllers\Internal;

use App\Enums\ScopeArea;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
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
        $now = now();

        // MyWork is intrinsically self-only (assigned_to = me); scopeList only
        // ADDS constraints, so it's a no-op under All/Assigned and correctly
        // empties the page under None (phase 3b-ii: None = no task visibility).
        $allTasks = $this->scopeList(Task::where('assigned_to', $userId), ScopeArea::Tasks)
            ->whereNotIn('status', ['complete', 'cancelled'])
            ->with([
                'project:id,title,colour',
                'customer:id,name',
            ])
            ->get();

        // Bucketed by schedule, partitioned on *start of today* so the
        // buckets are mutually exclusive — a task due earlier today lands
        // in due-today (not also overdue). The row still flags an elapsed
        // due time in red via the client's isOverdue check.
        //   overdue   = due before today
        //   due-today = anytime today
        //   upcoming  = after today
        //   unscheduled = no due_at
        $startOfDay = $now->copy()->startOfDay();
        $endOfDay = $now->copy()->endOfDay();

        $overdue = $allTasks
            ->filter(fn (Task $t): bool => $t->due_at !== null && $t->due_at->lt($startOfDay))
            ->sortBy('due_at')
            ->values();

        $dueToday = $allTasks
            ->filter(fn (Task $t): bool => $t->due_at !== null
                && $t->due_at->gte($startOfDay)
                && $t->due_at->lte($endOfDay))
            ->sortBy('due_at')
            ->values();

        $upcoming = $allTasks
            ->filter(fn (Task $t): bool => $t->due_at !== null && $t->due_at->gt($endOfDay))
            ->sortBy('due_at')
            ->values();

        $unscheduled = $allTasks
            ->filter(fn (Task $t): bool => $t->due_at === null)
            ->sortByDesc('created_at')
            ->values();

        $mapTask = fn (Task $t): array => [
            'id' => $t->id,
            'title' => $t->title,
            'type' => $t->type,
            'priority' => $t->priority,
            'status' => $t->status,
            'due_at' => $t->due_at?->format('d M H:i'),
            'due_at_full' => $t->due_at?->toIso8601String(),
            'project_id' => $t->project_id,
            'project_title' => $t->project?->title,
            'project_colour' => $t->project?->colour,
            'customer_name' => $t->customer?->name,
            // Support-triage tasks carry their ticket id so the row can
            // link straight to the helpdesk thread. Null for every other.
            'ticket_id' => $t->ticket_id,
        ];

        $summary = [
            'overdue' => $overdue->count(),
            'today' => $dueToday->count(),
            'upcoming' => $upcoming->count(),
            'unscheduled' => $unscheduled->count(),
            'total' => $allTasks->count(),
        ];

        // Hours logged today. Storage is minutes against logged_at (a DATE
        // column — there is no `date` column on time_entries).
        $minutesToday = (int) TimeEntry::where('user_id', $userId)
            ->whereDate('logged_at', today())
            ->sum('minutes');
        $hoursToday = round($minutesToday / 60, 1);

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
            'summary' => $summary,
            'overdue' => $overdue->map($mapTask)->values(),
            'due_today' => $dueToday->map($mapTask)->values(),
            // Grouped by day so the Upcoming tab can render day headers.
            'upcoming' => $upcoming->map($mapTask)
                ->groupBy(fn (array $t): string => Carbon::parse($t['due_at_full'])->format('D d M')),
            'unscheduled' => $unscheduled->map($mapTask)->take(10)->values(),
            'hours_today' => $hoursToday,
            'my_projects' => $myProjects,
            'total' => $summary['total'],
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

        // Self-only already (assigned_to = me); scopeList no-ops under
        // All/Assigned and empties the calendar feed under None (phase 3b-ii).
        $tasks = $this->scopeList(Task::where('assigned_to', $user->id), ScopeArea::Tasks)
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
}
