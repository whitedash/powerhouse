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
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    IconPlus, IconAlertTriangle, IconCircleCheck, IconChevronDown,
    IconChevronUp, IconClock, IconEye, IconBan, IconCircle,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';

const props = defineProps({
    grouped: { type: Object, required: true },
    my_projects: { type: Array, default: () => [] },
    total: { type: Number, default: 0 },
});

const page = usePage();

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
        </div>
    </InternalLayout>
</template>
