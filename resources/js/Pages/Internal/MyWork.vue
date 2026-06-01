<script setup>
/**
 * /my-work — personal task list.
 *
 * The server pre-groups tasks into 6 sections (overdue, today,
 * this_week, in_progress, in_review, upcoming). We render each as
 * a collapsible card; empty sections are hidden unless they have
 * a semantic "good" empty state (e.g. "Today" → "All caught up").
 *
 * The status quick-change popover lets the operator move a card
 * between states without leaving the page; it posts to
 * /tasks/{id}/status the same as the project board does.
 */
import { computed, nextTick, reactive, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    IconPlus, IconAlertTriangle, IconCircleCheck, IconChevronDown,
    IconChevronUp, IconClock, IconEye, IconBan, IconCircle,
    IconList, IconCalendar, IconBrandGoogle, IconMapPin, IconFolder,
    IconVideo, IconX,
} from '@tabler/icons-vue';
import FullCalendar from '@fullcalendar/vue3';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import listPlugin from '@fullcalendar/list';
import interactionPlugin from '@fullcalendar/interaction';
import InternalLayout from '@/Layouts/InternalLayout.vue';

const props = defineProps({
    grouped: { type: Object, required: true },
    my_projects: { type: Array, default: () => [] },
    total: { type: Number, default: 0 },
    google_connected: { type: Boolean, default: false },
});

const page = usePage();

/* ─── List / Calendar tab switch ─── */
const activeTab = ref('list');
const googleConnected = computed(() => props.google_connected);

const me = computed(() => page.props.auth?.user?.name?.split(' ')[0] ?? 'there');

/* ─── Section open/closed state ─── */
const open = reactive({
    overdue: true,
    today: true,
    this_week: true,
    in_progress: true,
    in_review: true,
    upcoming: false, // collapsed by default — see controller comment
});
function toggle(key) { open[key] = ! open[key]; }

const STATUS_LABEL = {
    todo: 'To do', in_progress: 'In progress', in_review: 'In review',
    blocked: 'Blocked', complete: 'Complete', cancelled: 'Cancelled',
};
function statusLabel(s) { return STATUS_LABEL[s] ?? s; }

/* ─── Status popover ─── */
const openPopover = ref(null);
const STATUS_OPTIONS = [
    { key: 'todo',        label: 'To do',       icon: IconCircle },
    { key: 'in_progress', label: 'In progress', icon: IconClock },
    { key: 'in_review',   label: 'In review',   icon: IconEye },
    { key: 'blocked',     label: 'Blocked',     icon: IconBan },
    { key: 'complete',    label: 'Complete',    icon: IconCircleCheck },
];
function togglePopover(taskId) { openPopover.value = openPopover.value === taskId ? null : taskId; }
function setStatus(taskId, status) {
    router.post(`/tasks/${taskId}/status`, { status }, {
        preserveScroll: true,
        onFinish: () => { openPopover.value = null; },
    });
}

/* ─── Quick task add ─── */
const quickAdd = useForm({ title: '' });
function submitQuickAdd() {
    if (! quickAdd.title.trim()) return;
    quickAdd.transform(d => ({ title: d.title, type: 'task', assigned_to: page.props.auth?.user?.id }))
        .post('/tasks', {
            preserveScroll: true,
            onSuccess: () => { quickAdd.title = ''; },
        });
}

/* ─── This-week sub-grouping by day ─── */
const thisWeekByDay = computed(() => {
    const buckets = {};
    for (const t of props.grouped.this_week) {
        const key = t.due_at ? new Date(t.due_at).toLocaleDateString('en-GB', { weekday: 'short', day: '2-digit', month: 'short' }) : 'Later this week';
        if (! buckets[key]) buckets[key] = [];
        buckets[key].push(t);
    }
    return buckets;
});

function initials(name) {
    return (name || '').split(/\s+/).map(p => p[0]).slice(0, 2).join('').toUpperCase();
}

const sections = [
    { key: 'overdue',     label: 'Overdue',          tone: 'red' },
    { key: 'today',       label: 'Today',            tone: 'amber' },
    { key: 'this_week',   label: 'This week',        tone: 'neutral' },
    { key: 'in_progress', label: 'In progress',      tone: 'info' },
    { key: 'in_review',   label: 'Waiting for review', tone: 'neutral' },
    { key: 'upcoming',    label: 'Upcoming',         tone: 'neutral' },
];

/* ─── Calendar ─── */
const csrf = () => document.querySelector('meta[name=csrf-token]')?.content ?? '';

// FullCalendar's event source — fetched per visible range. We normalise
// both Powerhouse tasks and Google events into FullCalendar's event shape
// here (colour → backgroundColor, everything else → extendedProps so the
// detail panel can read source/type/location without auto-navigating on
// the `url` field FullCalendar would otherwise hijack).
const fetchCalendarEvents = async (info, successCallback, failureCallback) => {
    try {
        const res = await fetch(`/my-work/calendar?start=${info.startStr}&end=${info.endStr}`, {
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
                <h1>Good {{ new Date().getHours() < 12 ? 'morning' : (new Date().getHours() < 18 ? 'afternoon' : 'evening') }}, {{ me }}</h1>
                <p class="muted">
                    <strong>{{ total }}</strong> {{ total === 1 ? 'task' : 'tasks' }} assigned to you.
                </p>
            </div>

            <!-- ─── My projects strip ─── -->
            <div v-if="my_projects.length > 0" class="mw-projects-strip">
                <h3 class="strip-title">My active projects</h3>
                <div class="strip-row">
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

            <!-- ─── List / Calendar tabs ─── -->
            <nav class="mw-tabs">
                <button type="button" class="mw-tab" :class="{ active: activeTab === 'list' }" @click="switchTab('list')">
                    <IconList :size="15" stroke-width="2" /> List
                </button>
                <button type="button" class="mw-tab" :class="{ active: activeTab === 'calendar' }" @click="switchTab('calendar')">
                    <IconCalendar :size="15" stroke-width="2" /> Calendar
                </button>
            </nav>

            <!-- ════════ LIST TAB ════════ -->
            <div v-show="activeTab === 'list'">
            <!-- ─── Sections ─── -->
            <div v-for="sec in sections" :key="sec.key">
                <div v-if="grouped[sec.key].length > 0 || sec.key === 'today'" class="mw-section">
                    <button type="button" class="mw-section-head" :class="`tone-${sec.tone}`" @click="toggle(sec.key)">
                        <component :is="open[sec.key] ? IconChevronUp : IconChevronDown" :size="14" stroke-width="2" />
                        <span class="mw-section-title">{{ sec.label }}</span>
                        <span class="mw-section-count">{{ grouped[sec.key].length }}</span>
                    </button>

                    <div v-if="open[sec.key]" class="mw-section-body">
                        <!-- Today empty state -->
                        <div v-if="sec.key === 'today' && grouped[sec.key].length === 0" class="mw-empty-today">
                            <IconCircleCheck :size="20" stroke-width="2" />
                            All caught up for today.
                        </div>

                        <!-- This-week grouped by day -->
                        <template v-else-if="sec.key === 'this_week'">
                            <div v-for="(rows, dayLabel) in thisWeekByDay" :key="dayLabel">
                                <div class="mw-day-label muted small">{{ dayLabel }}</div>
                                <div
                                    v-for="t in rows"
                                    :key="t.id"
                                    class="mw-task-row"
                                >
                                    <div class="mw-status-wrap">
                                        <button type="button" class="mw-status-btn" @click="togglePopover(t.id)">
                                            <span class="status-dot" :class="t.status"></span>
                                        </button>
                                        <div v-if="openPopover === t.id" class="mw-status-pop">
                                            <button v-for="s in STATUS_OPTIONS" :key="s.key" type="button" class="mw-status-opt" @click.stop="setStatus(t.id, s.key)">
                                                <component :is="s.icon" :size="13" stroke-width="2" />
                                                {{ s.label }}
                                            </button>
                                        </div>
                                    </div>
                                    <Link :href="`/activities/${t.id}`" class="mw-task-link">{{ t.title }}</Link>
                                    <div class="mw-task-meta">
                                        <span v-if="t.project" class="project-chip" :style="{ borderColor: t.project.colour }">
                                            <span class="dot" :style="{ background: t.project.colour }"></span>
                                            {{ t.project.title }}
                                        </span>
                                        <span v-if="t.customer_name" class="muted small">· {{ t.customer_name }}</span>
                                        <span v-if="t.due_label" class="muted small">· {{ t.due_label }}</span>
                                        <span class="priority-dot" :class="`pri-${t.priority}`"></span>
                                    </div>
                                </div>
                            </div>
                        </template>

                        <!-- Standard flat list -->
                        <div v-else>
                            <div
                                v-for="t in grouped[sec.key]"
                                :key="t.id"
                                class="mw-task-row"
                                :class="{ overdue: t.is_overdue && sec.key === 'overdue' }"
                            >
                                <div class="mw-status-wrap">
                                    <button type="button" class="mw-status-btn" @click="togglePopover(t.id)">
                                        <span class="status-dot" :class="t.status"></span>
                                    </button>
                                    <div v-if="openPopover === t.id" class="mw-status-pop">
                                        <button v-for="s in STATUS_OPTIONS" :key="s.key" type="button" class="mw-status-opt" @click.stop="setStatus(t.id, s.key)">
                                            <component :is="s.icon" :size="13" stroke-width="2" />
                                            {{ s.label }}
                                        </button>
                                    </div>
                                </div>

                                <Link :href="`/activities/${t.id}`" class="mw-task-link">{{ t.title }}</Link>

                                <div class="mw-task-meta">
                                    <span v-if="t.project" class="project-chip" :style="{ borderColor: t.project.colour }">
                                        <span class="dot" :style="{ background: t.project.colour }"></span>
                                        {{ t.project.title }}
                                    </span>
                                    <span v-if="t.customer_name" class="muted small">· {{ t.customer_name }}</span>
                                    <span v-if="t.due_label" :class="{ 'text-danger': t.is_overdue }" class="small">· {{ t.due_label }}</span>
                                    <span v-if="t.status === 'blocked' && t.blocked_reason" class="muted small blocked-note">
                                        <IconAlertTriangle :size="11" stroke-width="2" />
                                        {{ t.blocked_reason }}
                                    </span>
                                    <span class="priority-dot" :class="`pri-${t.priority}`" :title="t.priority"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── Quick task add (footer) ─── -->
            <div class="mw-quick-add">
                <form @submit.prevent="submitQuickAdd" class="reply-box">
                    <IconPlus :size="16" stroke-width="2" />
                    <input
                        v-model="quickAdd.title"
                        type="text"
                        placeholder="Quick task — title, then Enter"
                        maxlength="255"
                    />
                    <button type="submit" class="btn btn-primary btn-sm" :disabled="!quickAdd.title.trim() || quickAdd.processing">Add</button>
                </form>
            </div>
            </div><!-- /list tab -->

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
