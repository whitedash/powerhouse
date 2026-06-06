<script setup>
/**
 * /my-work — the operator's personal command centre.
 *
 * Four tabs: Today (overdue + due-today + unscheduled), Upcoming
 * (grouped by day), Projects (the operator's active projects), and
 * Calendar (FullCalendar + Google sync — unchanged from before).
 *
 * The server pre-buckets tasks by schedule and ships display-ready
 * shapes; the page just renders them and posts quick-complete /
 * quick-reschedule mutations inline.
 */
import { computed, nextTick, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    IconAlertCircle, IconChevronDown, IconChevronUp,
    IconChecklist, IconCalendarWeek, IconLayoutKanban, IconCalendar,
    IconBrandGoogle, IconMapPin, IconFolder, IconVideo, IconX,
} from '@tabler/icons-vue';
import FullCalendar from '@fullcalendar/vue3';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import listPlugin from '@fullcalendar/list';
import interactionPlugin from '@fullcalendar/interaction';
import InternalLayout from '@/Layouts/InternalLayout.vue';
import MyWorkTaskRow from '@/Components/Internal/MyWorkTaskRow.vue';

const props = defineProps({
    summary: { type: Object, default: () => ({ overdue: 0, today: 0, upcoming: 0, unscheduled: 0, total: 0 }) },
    overdue: { type: Array, default: () => [] },
    due_today: { type: Array, default: () => [] },
    upcoming: { type: Object, default: () => ({}) },
    unscheduled: { type: Array, default: () => [] },
    hours_today: { type: Number, default: 0 },
    my_projects: { type: Array, default: () => [] },
    total: { type: Number, default: 0 },
    google_connected: { type: Boolean, default: false },
});

const page = usePage();
const googleConnected = computed(() => props.google_connected);

const todayLabel = computed(() =>
    new Date().toLocaleDateString('en-GB', {
        weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
    }),
);

/* ─── Tabs ─── */
const activeTab = ref('tasks');

/* Upcoming arrives grouped-by-day; flatten just for the column count badge. */
const upcomingTotal = computed(() => Object.values(props.upcoming).flat().length);

/* ─── Quick complete / reschedule ─── */
const completing = ref(null);
const showUnscheduled = ref(false);

function quickComplete(id) {
    completing.value = id;
    router.post(`/tasks/${id}/quick-complete`, {}, {
        preserveScroll: true,
        onFinish: () => { completing.value = null; },
    });
}
function quickReschedule(id, date) {
    if (! date) return;
    router.post(`/tasks/${id}/quick-reschedule`, { due_at: date }, {
        preserveScroll: true,
    });
}
function goToTask(task) {
    router.visit(task.project_id ? `/projects/${task.project_id}` : `/activities/${task.id}`);
}

/* ─── Calendar ─── */
const csrf = () => document.querySelector('meta[name=csrf-token]')?.content ?? '';

// FullCalendar's event source — fetched per visible range. We normalise
// both Powerhouse tasks and Google events into FullCalendar's event shape
// here (colour → backgroundColor, everything else → extendedProps so the
// detail panel can read source/type/location without auto-navigating on
// the `url` field FullCalendar would otherwise hijack).
const fetchCalendarEvents = async (info, successCallback, failureCallback) => {
    try {
        // encodeURIComponent is required: during BST, FullCalendar's
        // startStr/endStr carry a "+01:00" offset, and a raw "+" in a query
        // string decodes to a space server-side ("...T00:00:00 01:00"),
        // which Carbon::parse rejects ("Double time specification") → 500.
        const res = await fetch(`/my-work/calendar?start=${encodeURIComponent(info.startStr)}&end=${encodeURIComponent(info.endStr)}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (! res.ok) throw new Error('calendar fetch failed');
        const data = await res.json();
        const events = (data.events || []).map((e) => ({
            id: e.id,
            title: e.title,
            start: e.start,
            end: e.end,
            allDay: e.allDay ?? e.all_day ?? false,
            backgroundColor: e.colour,
            borderColor: e.colour,
            editable: e.editable ?? false,
            extendedProps: {
                source: e.source,
                type: e.type ?? null,
                status: e.status ?? null,
                priority: e.priority ?? null,
                location: e.location ?? null,
                project_id: e.project_id ?? null,
                project_title: e.project_title ?? null,
                description: e.description ?? null,
                url: e.url ?? null,
                html_link: e.html_link ?? null,
            },
        }));
        successCallback(events);
    } catch (err) {
        failureCallback(err);
    }
};

const calendarRef = ref(null);

const calendarOptions = ref({
    plugins: [dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin],
    initialView: 'dayGridMonth',
    headerToolbar: {
        left: 'prev,next today',
        center: 'title',
        right: 'dayGridMonth,timeGridWeek,listWeek',
    },
    height: 'auto',
    firstDay: 1, // Monday-first, matching the rest of the app
    editable: true,
    selectable: true,
    dayMaxEvents: true,
    events: fetchCalendarEvents,
    eventClick: (info) => {
        info.jsEvent.preventDefault();
        openEventDetail(info.event);
    },
    eventDrop: (info) => updateTaskDate(info),
    select: (info) => openCreateTask(info),
});

function refetchCalendar() {
    calendarRef.value?.getApi()?.refetchEvents();
}

// FullCalendar mounts inside the hidden (v-show) calendar tab on initial
// page load, so it measures zero dimensions and renders broken — squares
// for the nav icons, collapsed grid, events not painted, "needs a second
// click on Month". The moment the tab actually becomes visible we force a
// re-measure (updateSize) and a refetch so it lays out correctly the first
// time. nextTick waits for v-show to flip display:none → block.
function switchTab(tab) {
    activeTab.value = tab;
    if (tab === 'calendar') {
        nextTick(() => {
            const api = calendarRef.value?.getApi();
            api?.updateSize();
            api?.refetchEvents();
        });
    }
}

/* ─── Event detail slide-over ─── */
const selectedEvent = ref(null);
const showEventDetail = ref(false);

function openEventDetail(event) {
    selectedEvent.value = {
        id: event.id,
        title: event.title,
        start: event.start,
        end: event.end,
        allDay: event.allDay,
        source: event.extendedProps.source,
        type: event.extendedProps.type,
        location: event.extendedProps.location,
        project: event.extendedProps.project_title,
        url: event.extendedProps.url,
        colour: event.backgroundColor,
        gcal_link: event.extendedProps.html_link,
    };
    showEventDetail.value = true;
}

function formatEventDate(ev) {
    if (! ev.start) return '';
    const opts = ev.allDay
        ? { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' }
        : { weekday: 'short', day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' };
    const start = new Date(ev.start).toLocaleDateString('en-GB', opts);
    if (ev.allDay || ! ev.end) return start;
    const end = new Date(ev.end).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
    return `${start} – ${end}`;
}

/* ─── Drag-to-reschedule ─── */
function updateTaskDate(info) {
    const ev = info.event;
    // Google events are read-only here; revert any drag of one.
    if (ev.extendedProps.source !== 'powerhouse') {
        info.revert();
        return;
    }
    const id = String(ev.id).replace('task_', '');
    fetch(`/tasks/${id}/reschedule`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrf(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
            start: ev.start ? ev.start.toISOString() : null,
            end: ev.end ? ev.end.toISOString() : null,
            all_day: ev.allDay,
        }),
    }).then((res) => {
        if (! res.ok) throw new Error('reschedule failed');
    }).catch(() => info.revert());
}

/* ─── Create task from an empty slot ─── */
const showCreateTask = ref(false);
const createForm = useForm({
    title: '',
    type: 'task',
    is_all_day: true,
    due_at: '',
    start_at: '',
    end_at: '',
    location: '',
    assigned_to: page.props.auth?.user?.id,
});

function openCreateTask(info) {
    const allDay = info.allDay ?? true;
    createForm.reset();
    createForm.assigned_to = page.props.auth?.user?.id;
    createForm.is_all_day = allDay;
    if (allDay) {
        // info.startStr is YYYY-MM-DD for an all-day selection.
        createForm.due_at = info.startStr.slice(0, 10);
    } else {
        createForm.start_at = toLocalInput(info.start);
        createForm.end_at = toLocalInput(info.end);
    }
    showCreateTask.value = true;
    // Clear the blue selection highlight on the grid.
    calendarRef.value?.getApi()?.unselect();
}

// <input type="datetime-local"> wants "YYYY-MM-DDTHH:mm" in local time.
function toLocalInput(date) {
    if (! date) return '';
    const d = new Date(date);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function submitCreateTask() {
    if (! createForm.title.trim()) return;
    createForm
        .transform((d) => ({
            title: d.title,
            type: d.type,
            assigned_to: d.assigned_to,
            is_all_day: d.is_all_day,
            due_at: d.is_all_day ? d.due_at : null,
            start_at: d.is_all_day ? null : d.start_at,
            end_at: d.is_all_day ? null : d.end_at,
            location: d.location || null,
        }))
        .post('/tasks', {
            preserveScroll: true,
            onSuccess: () => {
                showCreateTask.value = false;
                createForm.reset();
                refetchCalendar();
            },
        });
}
</script>

<template>
    <Head title="My Work" />

    <InternalLayout title="My Work" active-nav="my-work">
        <div class="my-work">
            <!-- ─── Header ─── -->
            <div class="mw-header">
                <h1>My Work</h1>
                <p class="muted">{{ todayLabel }}</p>
            </div>

            <!-- ─── Summary strip ─── -->
            <div class="mw-summary">
                <div class="mw-stat" :class="{ 'mw-stat--alert': summary.overdue }">
                    <span class="mw-stat-value">{{ summary.overdue }}</span>
                    <span class="mw-stat-label">Overdue</span>
                </div>
                <div class="mw-stat">
                    <span class="mw-stat-value">{{ summary.today }}</span>
                    <span class="mw-stat-label">Due today</span>
                </div>
                <div class="mw-stat">
                    <span class="mw-stat-value">{{ summary.upcoming }}</span>
                    <span class="mw-stat-label">Upcoming</span>
                </div>
                <div class="mw-stat mw-stat--time">
                    <span class="mw-stat-value">{{ hours_today }}h</span>
                    <span class="mw-stat-label">Logged today</span>
                </div>
            </div>

            <!-- ─── Tabs ─── -->
            <nav class="mw-tabs">
                <button type="button" class="mw-tab" :class="{ active: activeTab === 'tasks' }" @click="switchTab('tasks')">
                    <IconChecklist :size="15" stroke-width="2" /> Tasks
                </button>
                <button type="button" class="mw-tab" :class="{ active: activeTab === 'projects' }" @click="switchTab('projects')">
                    <IconLayoutKanban :size="15" stroke-width="2" /> Projects
                </button>
                <button type="button" class="mw-tab" :class="{ active: activeTab === 'calendar' }" @click="switchTab('calendar')">
                    <IconCalendar :size="15" stroke-width="2" /> Calendar
                </button>
            </nav>

            <!-- ════════ TASKS TAB (3-column board) ════════ -->
            <div v-show="activeTab === 'tasks'">
                <div class="mw-columns">
                    <!-- COLUMN 1: OVERDUE -->
                    <div class="mw-col mw-col--overdue">
                        <div class="mw-col-head">
                            <span class="mw-col-title">
                                <IconAlertCircle :size="14" stroke-width="2" /> Overdue
                            </span>
                            <span class="mw-col-badge" :class="{ 'mw-col-badge--danger': overdue.length }">{{ overdue.length }}</span>
                        </div>
                        <div class="mw-col-body">
                            <MyWorkTaskRow
                                v-for="task in overdue"
                                :key="task.id"
                                :task="task"
                                :completing="completing"
                                @complete="quickComplete"
                                @reschedule="quickReschedule"
                                @open="goToTask"
                            />
                            <div v-if="! overdue.length" class="mw-col-empty">No overdue tasks ✓</div>
                        </div>
                    </div>

                    <!-- COLUMN 2: DUE TODAY (+ unscheduled at the bottom) -->
                    <div class="mw-col mw-col--today">
                        <div class="mw-col-head">
                            <span class="mw-col-title">
                                <IconCalendar :size="14" stroke-width="2" /> Today
                            </span>
                            <span class="mw-col-badge">{{ due_today.length }}</span>
                        </div>
                        <div class="mw-col-body">
                            <MyWorkTaskRow
                                v-for="task in due_today"
                                :key="task.id"
                                :task="task"
                                :completing="completing"
                                @complete="quickComplete"
                                @reschedule="quickReschedule"
                                @open="goToTask"
                            />
                            <div v-if="! due_today.length" class="mw-col-empty">Nothing due today 🎉</div>

                            <!-- Unscheduled, parked at the bottom of Today -->
                            <div v-if="unscheduled.length" class="mw-col-section">
                                <button type="button" class="mw-col-section-toggle" @click="showUnscheduled = ! showUnscheduled">
                                    Unscheduled
                                    <span class="mw-col-badge">{{ unscheduled.length }}</span>
                                    <component :is="showUnscheduled ? IconChevronUp : IconChevronDown" :size="13" stroke-width="2" />
                                </button>
                                <template v-if="showUnscheduled">
                                    <MyWorkTaskRow
                                        v-for="task in unscheduled"
                                        :key="task.id"
                                        :task="task"
                                        :completing="completing"
                                        @complete="quickComplete"
                                        @reschedule="quickReschedule"
                                        @open="goToTask"
                                    />
                                </template>
                            </div>
                        </div>
                    </div>

                    <!-- COLUMN 3: UPCOMING -->
                    <div class="mw-col mw-col--upcoming">
                        <div class="mw-col-head">
                            <span class="mw-col-title">
                                <IconCalendarWeek :size="14" stroke-width="2" /> Upcoming
                            </span>
                            <span class="mw-col-badge">{{ upcomingTotal }}</span>
                        </div>
                        <div class="mw-col-body">
                            <template v-for="(tasks, day) in upcoming" :key="day">
                                <div class="mw-day-header">{{ day }}</div>
                                <MyWorkTaskRow
                                    v-for="task in tasks"
                                    :key="task.id"
                                    :task="task"
                                    :completing="completing"
                                    @complete="quickComplete"
                                    @reschedule="quickReschedule"
                                    @open="goToTask"
                                />
                            </template>
                            <div v-if="! upcomingTotal" class="mw-col-empty">Nothing upcoming</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ════════ PROJECTS TAB ════════ -->
            <div v-show="activeTab === 'projects'">
                <div v-if="my_projects.length === 0" class="mw-section">
                    <div class="mw-empty">You're not on any active projects.</div>
                </div>
                <div v-else class="mw-projects-grid">
                    <Link
                        v-for="p in my_projects"
                        :key="p.id"
                        :href="`/projects/${p.id}`"
                        class="strip-card"
                    >
                        <div class="strip-card-colour" :style="{ background: p.colour }"></div>
                        <div class="strip-card-body">
                            <strong>{{ p.title }}</strong>
                            <div class="muted small">{{ p.customer_name ?? 'Internal' }}</div>
                            <div class="project-progress-bar">
                                <div class="project-progress-fill" :style="{ width: p.progress + '%' }"></div>
                            </div>
                            <div class="muted small">{{ p.completed_count }}/{{ p.tasks_count }} · {{ p.progress }}%</div>
                        </div>
                    </Link>
                </div>
            </div>

            <!-- ════════ CALENDAR TAB ════════ -->
            <div v-show="activeTab === 'calendar'" class="my-work-calendar">
                <!-- Google Calendar connection banner -->
                <div v-if="!googleConnected" class="gcal-connect-banner">
                    <IconBrandGoogle :size="16" stroke-width="2" />
                    <span>Connect Google Calendar to see all your events in one place.</span>
                    <a href="/account/google/connect" class="btn btn-ghost btn-sm">Connect →</a>
                </div>

                <!-- Legend -->
                <div class="cal-legend">
                    <span class="cal-legend-item">
                        <span class="cal-dot" style="background:#F59E0B"></span> Tasks
                    </span>
                    <span class="cal-legend-item">
                        <span class="cal-dot" style="background:#8B5CF6"></span> Meetings
                    </span>
                    <span v-if="googleConnected" class="cal-legend-item">
                        <span class="cal-dot" style="background:#4285F4"></span> Google Calendar
                    </span>
                </div>

                <!-- FullCalendar -->
                <div class="cal-wrapper">
                    <FullCalendar ref="calendarRef" :options="calendarOptions" />
                </div>
            </div>

            <!-- ─── Event detail slide-over ─── -->
            <div v-if="showEventDetail" class="mw-slide-over">
                <div class="mw-slide-backdrop" @click="showEventDetail = false"></div>
                <div class="mw-slide-panel">
                    <div class="mw-slide-head">
                        <h2>{{ selectedEvent.title }}</h2>
                        <button type="button" class="icon-btn" @click="showEventDetail = false">
                            <IconX :size="18" stroke-width="2" />
                        </button>
                    </div>
                    <div class="mw-slide-body">
                        <div v-if="selectedEvent.type === 'meeting'" class="event-type-badge">
                            <IconVideo :size="14" stroke-width="2" /> Meeting
                        </div>

                        <div v-if="selectedEvent.start" class="event-detail-row">
                            <IconCalendar :size="15" stroke-width="2" />
                            {{ formatEventDate(selectedEvent) }}
                        </div>
                        <div v-if="selectedEvent.location" class="event-detail-row">
                            <IconMapPin :size="15" stroke-width="2" />
                            {{ selectedEvent.location }}
                        </div>
                        <div v-if="selectedEvent.project" class="event-detail-row">
                            <IconFolder :size="15" stroke-width="2" />
                            {{ selectedEvent.project }}
                        </div>

                        <div class="event-actions">
                            <a
                                v-if="selectedEvent.url && selectedEvent.source === 'powerhouse'"
                                :href="selectedEvent.url"
                                class="btn btn-primary btn-sm"
                            >Open task →</a>
                            <a
                                v-if="selectedEvent.gcal_link"
                                :href="selectedEvent.gcal_link"
                                target="_blank"
                                rel="noopener"
                                class="btn btn-ghost btn-sm"
                            >
                                <IconBrandGoogle :size="14" stroke-width="2" /> Open in Google Calendar
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── Create task slide-over (from empty-slot click) ─── -->
            <div v-if="showCreateTask" class="mw-slide-over">
                <div class="mw-slide-backdrop" @click="showCreateTask = false"></div>
                <div class="mw-slide-panel">
                    <div class="mw-slide-head">
                        <h2>New task</h2>
                        <button type="button" class="icon-btn" @click="showCreateTask = false">
                            <IconX :size="18" stroke-width="2" />
                        </button>
                    </div>
                    <form class="mw-slide-body mw-create-form" @submit.prevent="submitCreateTask">
                        <div class="form-field">
                            <label>Title</label>
                            <input v-model="createForm.title" type="text" maxlength="255" required autofocus />
                            <div v-if="createForm.errors.title" class="err">{{ createForm.errors.title }}</div>
                        </div>

                        <div class="form-field">
                            <label>Type</label>
                            <select v-model="createForm.type">
                                <option value="task">Task</option>
                                <option value="meeting">Meeting</option>
                                <option value="call">Call</option>
                            </select>
                        </div>

                        <label class="mw-allday-row">
                            <input v-model="createForm.is_all_day" type="checkbox" />
                            All-day
                        </label>

                        <div v-if="createForm.is_all_day" class="form-field">
                            <label>Date</label>
                            <input v-model="createForm.due_at" type="date" required />
                            <div v-if="createForm.errors.due_at" class="err">{{ createForm.errors.due_at }}</div>
                        </div>
                        <template v-else>
                            <div class="form-field">
                                <label>Starts</label>
                                <input v-model="createForm.start_at" type="datetime-local" required />
                            </div>
                            <div class="form-field">
                                <label>Ends</label>
                                <input v-model="createForm.end_at" type="datetime-local" />
                            </div>
                            <div class="form-field">
                                <label>Location <span class="muted small">(optional)</span></label>
                                <input v-model="createForm.location" type="text" maxlength="255" placeholder="Office or Zoom link" />
                            </div>
                        </template>

                        <div class="mw-create-actions">
                            <button type="button" class="btn btn-ghost btn-sm" @click="showCreateTask = false">Cancel</button>
                            <button type="submit" class="btn btn-primary btn-sm" :disabled="!createForm.title.trim() || createForm.processing">
                                {{ createForm.processing ? 'Creating…' : 'Create task' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </InternalLayout>
</template>
