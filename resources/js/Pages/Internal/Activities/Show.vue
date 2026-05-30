<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Dialog,
    DialogPanel,
    Menu,
    MenuButton,
    MenuItem,
    MenuItems,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import {
    IconArrowLeft,
    IconArrowRight,
    IconCalendar,
    IconCheck,
    IconCheckbox,
    IconClock,
    IconDots,
    IconHeadset,
    IconMail,
    IconNotes,
    IconPencil,
    IconPhone,
    IconPin,
    IconPlus,
    IconReceipt,
    IconUser,
    IconUserCheck,
    IconUsersGroup,
    IconX,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const props = defineProps({
    task: { type: Object, required: true },
    related: { type: Array, default: () => [] },
    staff: { type: Array, default: () => [] },
    contacts: { type: Array, default: () => [] },
    me_id: { type: Number, default: null },
});

const breadcrumbs = computed(() => [
    props.task.customer
        ? { label: props.task.customer.name, href: `/customers/${props.task.customer.id}` }
        : { label: 'Activities', href: '/' },
    { label: 'Activity detail' },
]);

/* ─── Type → icon component ─── */
const ICON_BY_NAME = {
    phone: IconPhone,
    mail: IconMail,
    users: IconUsersGroup,
    notes: IconNotes,
    checkbox: IconCheckbox,
};
function iconByName(name) {
    return ICON_BY_NAME[name] ?? IconCheckbox;
}
const TYPE_LABEL = {
    task: 'Task',
    call: 'Call',
    email: 'Email',
    meeting: 'Meeting',
    note: 'Note',
};
const PRIORITY_LABEL = {
    low: 'Low',
    medium: 'Medium',
    high: 'High',
};

/* ─── Formatting helpers ─── */
function formatDueAt(iso) {
    if (! iso) return '—';
    const d = new Date(iso);
    return d.toLocaleString('en-GB', {
        day: 'numeric', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}
function formatDueShort(iso) {
    if (! iso) return '—';
    const d = new Date(iso);
    return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
}
function initials(name) {
    const parts = String(name || '').trim().split(/\s+/);
    return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || '?';
}
function avatarStyle(c) {
    return { background: c || '#64748B', color: '#fff' };
}

/* ─── Complete activity (outcome modal) ─── */
const showCompleteModal = ref(false);
const completing = ref(false);
const outcomeText = ref('');
function askComplete() {
    if (props.task.status === 'complete') return;
    outcomeText.value = '';
    showCompleteModal.value = true;
}
function performComplete() {
    completing.value = true;
    router.post(`/tasks/${props.task.id}/complete`, { outcome: outcomeText.value || null }, {
        preserveScroll: false,
        onFinish: () => {
            completing.value = false;
            showCompleteModal.value = false;
        },
    });
}

/* ─── Pin toggle ─── */
function togglePin() {
    router.post(`/tasks/${props.task.id}/pin`, {}, { preserveScroll: true });
}

/* ─── Edit (inline button) — redirects back to customer detail
 *    where the edit slide-over already lives. Building a duplicate
 *    slide-over here would double the surface for no benefit. ─── */
function editActivity() {
    if (props.task.customer) {
        router.visit(`/customers/${props.task.customer.id}?edit_task=${props.task.id}`);
    }
}

/* ─── Delete with ConfirmModal ─── */
const showDeleteModal = ref(false);
const deleting = ref(false);
function askDelete() { showDeleteModal.value = true; }
function performDelete() {
    deleting.value = true;
    router.delete(`/tasks/${props.task.id}`, {
        onFinish: () => {
            deleting.value = false;
            showDeleteModal.value = false;
        },
        onSuccess: () => {
            if (props.task.customer) router.visit(`/customers/${props.task.customer.id}`);
            else router.visit('/');
        },
    });
}

/* ─── Inline add-note form ─── */
const showNoteForm = ref(false);
const noteForm = useForm({
    customer_id: props.task.customer_id,
    task_id: props.task.id,
    body: '',
    is_pinned: false,
});
function openNoteForm() {
    noteForm.reset();
    noteForm.clearErrors();
    noteForm.customer_id = props.task.customer_id;
    noteForm.task_id = props.task.id;
    showNoteForm.value = true;
}
function submitNote() {
    if (! props.task.customer_id) return; // notes need a customer
    noteForm.post('/notes', {
        preserveScroll: true,
        onSuccess: () => {
            showNoteForm.value = false;
            noteForm.reset();
            noteForm.customer_id = props.task.customer_id;
            noteForm.task_id = props.task.id;
        },
    });
}

/* ─── Note actions ─── */
function deleteNote(note) {
    router.delete(`/notes/${note.id}`, { preserveScroll: true });
}
function toggleNotePin(note) {
    router.put(`/notes/${note.id}`, {
        body: note.body,
        is_pinned: ! note.is_pinned,
    }, { preserveScroll: true });
}

/* ─── Create linked sub-task (slide-over) ─── */
const showSubTaskForm = ref(false);
const subTaskForm = useForm({
    type: 'task',
    title: '',
    description: '',
    priority: 'medium',
    customer_id: props.task.customer_id,
    contact_id: null,
    parent_task_id: props.task.id,
    assigned_to: props.me_id,
    due_at: '',
    due_time: '',
});
function openSubTask() {
    subTaskForm.reset();
    subTaskForm.clearErrors();
    subTaskForm.type = 'task';
    subTaskForm.priority = 'medium';
    subTaskForm.customer_id = props.task.customer_id;
    subTaskForm.parent_task_id = props.task.id;
    subTaskForm.assigned_to = props.me_id;
    showSubTaskForm.value = true;
}
function submitSubTask() {
    const payload = { ...subTaskForm.data() };
    if (payload.due_at && payload.due_time) {
        payload.due_at = `${payload.due_at} ${payload.due_time}`;
    }
    delete payload.due_time;
    subTaskForm
        .transform(() => payload)
        .post('/tasks', {
            preserveScroll: false,
            onSuccess: () => {
                showSubTaskForm.value = false;
                subTaskForm.reset();
            },
        });
}
const todayIso = new Date().toISOString().slice(0, 10);

/* ─── Child task quick-complete ─── */
function completeChild(child) {
    router.post(`/tasks/${child.id}/complete`, {}, { preserveScroll: true });
}

const statusBadgeClass = computed(() => {
    if (props.task.status === 'complete') return 'badge-active';
    if (props.task.is_overdue) return 'badge-overdue';
    return 'badge-pending';
});
const statusLabel = computed(() => {
    if (props.task.status === 'complete') return 'Complete';
    if (props.task.is_overdue) return 'Overdue';
    return 'Open';
});
</script>

<template>
    <Head :title="task.title" />

    <InternalLayout :title="task.title" :breadcrumbs="breadcrumbs" active-nav="">
        <template #topbar-actions>
            <Link
                :href="task.customer ? `/customers/${task.customer.id}` : '/'"
                class="btn btn-ghost btn-sm"
            >
                <IconArrowLeft :size="14" stroke-width="1.75" />
                Back
            </Link>
            <button
                v-if="task.status !== 'complete' && task.type !== 'note'"
                type="button"
                class="btn btn-primary btn-sm"
                @click="askComplete"
            >
                <IconCheck :size="14" stroke-width="2" />
                Mark complete
            </button>
            <button type="button" class="btn btn-secondary btn-sm" @click="editActivity">
                <IconPencil :size="14" stroke-width="1.75" />
                Edit
            </button>
            <Menu as="div" class="dd-menu">
                <MenuButton class="icon-btn" aria-label="More actions">
                    <IconDots :size="16" stroke-width="1.75" />
                </MenuButton>
                <MenuItems class="dd-popover right-align">
                    <MenuItem v-slot="{ active }">
                        <button type="button" :class="['dd-option', { active }]" @click="togglePin">
                            {{ task.is_pinned ? 'Unpin' : 'Pin' }}
                        </button>
                    </MenuItem>
                    <div style="height: 1px; background: var(--border-soft); margin: 4px 0;" />
                    <MenuItem v-slot="{ active }">
                        <button type="button" :class="['dd-option', { active }]" style="color: var(--danger);" @click="askDelete">
                            Delete activity
                        </button>
                    </MenuItem>
                </MenuItems>
            </Menu>
        </template>

        <div class="act-show">
            <!-- ═══ HEADER ═══ -->
            <div class="act-show-head">
                <div class="act-show-head-icon" :style="{ background: task.type_colour }">
                    <component :is="iconByName(task.type_icon)" :size="22" stroke-width="2" color="#fff" />
                </div>
                <div class="act-show-head-text">
                    <div class="act-show-head-row">
                        <span class="act-show-type">{{ TYPE_LABEL[task.type] ?? task.type }}</span>
                        <span class="act-show-priority-dot" :class="task.priority" :title="`Priority: ${PRIORITY_LABEL[task.priority] ?? task.priority}`" />
                        <span class="badge badge-sm" :class="statusBadgeClass">{{ statusLabel }}</span>
                        <span v-if="task.is_pinned" class="act-show-pin">
                            <IconPin :size="12" stroke-width="2" />
                            Pinned
                        </span>
                    </div>
                    <h1 class="act-show-title">{{ task.title }}</h1>
                    <div v-if="task.parent_task" class="act-show-parent">
                        Linked to
                        <Link :href="`/activities/${task.parent_task.id}`">{{ task.parent_task.title }}</Link>
                    </div>
                </div>
            </div>

            <!-- ═══ 60/40 GRID ═══ -->
            <div class="act-show-grid">
                <!-- LEFT — detail + notes -->
                <div class="col">
                    <!-- Activity detail card -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Activity details</h3>
                        </div>
                        <div class="act-show-body">
                            <div v-if="task.description" class="act-show-desc">{{ task.description }}</div>
                            <div v-else class="act-show-empty-line">No description added</div>

                            <div v-if="task.outcome" class="act-show-outcome">
                                <div class="act-show-section-label">Outcome</div>
                                <div class="act-show-outcome-box">{{ task.outcome }}</div>
                            </div>

                            <div class="act-show-meta-grid">
                                <div>
                                    <div class="act-show-meta-label">Type</div>
                                    <div class="act-show-meta-value">{{ TYPE_LABEL[task.type] ?? task.type }}</div>
                                </div>
                                <div>
                                    <div class="act-show-meta-label">Priority</div>
                                    <div class="act-show-meta-value">{{ PRIORITY_LABEL[task.priority] ?? task.priority }}</div>
                                </div>
                                <div>
                                    <div class="act-show-meta-label">Due</div>
                                    <div class="act-show-meta-value">
                                        <IconCalendar :size="12" stroke-width="1.75" />
                                        {{ formatDueAt(task.due_at) }}
                                    </div>
                                </div>
                                <div v-if="task.duration_minutes">
                                    <div class="act-show-meta-label">Duration</div>
                                    <div class="act-show-meta-value">
                                        <IconClock :size="12" stroke-width="1.75" />
                                        {{ task.duration_minutes }} min
                                    </div>
                                </div>
                                <div v-if="task.customer">
                                    <div class="act-show-meta-label">Customer</div>
                                    <div class="act-show-meta-value">
                                        <Link :href="`/customers/${task.customer.id}`">{{ task.customer.name }}</Link>
                                    </div>
                                </div>
                                <div v-if="task.contact">
                                    <div class="act-show-meta-label">Contact</div>
                                    <div class="act-show-meta-value">{{ task.contact.name }}</div>
                                </div>
                                <div>
                                    <div class="act-show-meta-label">Assigned to</div>
                                    <div class="act-show-meta-value">
                                        <template v-if="task.assigned_to_user">
                                            <span class="act-show-mini-avatar" :style="avatarStyle(task.assigned_to_user.avatar_colour)">{{ initials(task.assigned_to_user.name) }}</span>
                                            {{ task.assigned_to_user.name }}
                                        </template>
                                        <span v-else class="muted">Unassigned</span>
                                    </div>
                                </div>
                                <div v-if="task.created_by_user">
                                    <div class="act-show-meta-label">Created by</div>
                                    <div class="act-show-meta-value">{{ task.created_by_user.name }}</div>
                                </div>
                                <div>
                                    <div class="act-show-meta-label">Created</div>
                                    <div class="act-show-meta-value">{{ task.created_at }}</div>
                                </div>
                                <div v-if="task.completed_at">
                                    <div class="act-show-meta-label">Completed</div>
                                    <div class="act-show-meta-value">{{ task.completed_at_human }}</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Notes thread -->
                    <div class="card">
                        <div class="card-header">
                            <div class="h-icon"><IconNotes :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Notes</h3>
                                <div class="sub">{{ task.notes.length }} {{ task.notes.length === 1 ? 'note' : 'notes' }}</div>
                            </div>
                            <div class="right">
                                <button
                                    v-if="task.customer_id"
                                    type="button"
                                    class="btn btn-ghost btn-sm"
                                    @click="openNoteForm"
                                >
                                    <IconPlus :size="14" stroke-width="1.75" />
                                    Add note
                                </button>
                            </div>
                        </div>

                        <div v-if="task.notes.length === 0 && ! showNoteForm" class="act-show-empty">
                            No notes yet.
                            <button
                                v-if="task.customer_id"
                                type="button"
                                class="ghost-link"
                                style="display: block; margin-top: 6px;"
                                @click="openNoteForm"
                            >+ Add the first note</button>
                        </div>

                        <div v-if="task.notes.length" class="act-show-notes">
                            <article v-for="n in task.notes" :key="n.id" class="act-show-note" :class="{ pinned: n.is_pinned }">
                                <span class="act-show-mini-avatar lg" :style="avatarStyle(n.author?.avatar_colour)">{{ initials(n.author?.name) }}</span>
                                <div class="act-show-note-body">
                                    <header class="act-show-note-head">
                                        <span class="act-show-note-author">{{ n.author?.name ?? 'Unknown' }}</span>
                                        <span class="act-show-note-time">{{ n.created_at_human }}</span>
                                        <span v-if="n.is_pinned" class="act-show-note-pin" title="Pinned">
                                            <IconPin :size="11" stroke-width="2" />
                                        </span>
                                        <Menu as="div" class="dd-menu" style="margin-left: auto;">
                                            <MenuButton class="icon-btn" aria-label="Note actions">
                                                <IconDots :size="14" stroke-width="1.75" />
                                            </MenuButton>
                                            <MenuItems class="dd-popover right-align">
                                                <MenuItem v-slot="{ active }">
                                                    <button type="button" :class="['dd-option', { active }]" @click="toggleNotePin(n)">
                                                        {{ n.is_pinned ? 'Unpin' : 'Pin' }}
                                                    </button>
                                                </MenuItem>
                                                <div style="height: 1px; background: var(--border-soft); margin: 4px 0;" />
                                                <MenuItem v-slot="{ active }">
                                                    <button type="button" :class="['dd-option', { active }]" style="color: var(--danger);" @click="deleteNote(n)">Delete</button>
                                                </MenuItem>
                                            </MenuItems>
                                        </Menu>
                                    </header>
                                    <div class="act-show-note-text">{{ n.body }}</div>
                                </div>
                            </article>
                        </div>

                        <!-- Inline add-note form -->
                        <div v-if="showNoteForm" class="act-show-note-form">
                            <textarea
                                v-model="noteForm.body"
                                rows="4"
                                placeholder="Write a note about this activity…"
                                :class="{ 'has-err': noteForm.errors.body }"
                                maxlength="10000"
                            />
                            <div v-if="noteForm.errors.body" class="err">{{ noteForm.errors.body }}</div>
                            <div class="act-show-note-form-foot">
                                <label class="act-show-pin-toggle">
                                    <input type="checkbox" v-model="noteForm.is_pinned">
                                    <IconPin :size="11" stroke-width="2" />
                                    Pin
                                </label>
                                <div class="act-show-note-form-actions">
                                    <button type="button" class="btn btn-secondary btn-sm" @click="showNoteForm = false">Cancel</button>
                                    <button type="button" class="btn btn-primary btn-sm" :disabled="noteForm.processing || ! noteForm.body.trim()" @click="submitNote">
                                        <IconPlus :size="13" stroke-width="2" />
                                        {{ noteForm.processing ? 'Adding…' : 'Add note' }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- RIGHT — linked / related / customer -->
                <div class="col">
                    <!-- Linked tasks -->
                    <div class="card">
                        <div class="card-header">
                            <div class="h-icon"><IconCheckbox :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Linked tasks</h3>
                                <div class="sub">{{ task.child_tasks.length }} sub-{{ task.child_tasks.length === 1 ? 'task' : 'tasks' }}</div>
                            </div>
                            <div class="right">
                                <button type="button" class="btn btn-ghost btn-sm" @click="openSubTask">
                                    <IconPlus :size="13" stroke-width="2" />
                                    Create task
                                </button>
                            </div>
                        </div>
                        <div v-if="task.child_tasks.length === 0" class="act-show-empty small">
                            No linked tasks yet.
                            <button type="button" class="ghost-link" style="display: block; margin-top: 6px;" @click="openSubTask">+ Create sub-task</button>
                        </div>
                        <div v-else class="act-show-linked-list">
                            <div v-for="c in task.child_tasks" :key="c.id" class="act-show-linked-row">
                                <button
                                    type="button"
                                    class="cb"
                                    :class="{ done: c.status === 'complete' }"
                                    :aria-label="`Complete ${c.title}`"
                                    :disabled="c.status === 'complete'"
                                    @click.stop="completeChild(c)"
                                />
                                <span class="act-show-linked-icon" :style="{ background: c.type_colour }">
                                    <component :is="iconByName(c.type_icon)" :size="12" stroke-width="2" color="#fff" />
                                </span>
                                <Link :href="`/activities/${c.id}`" class="act-show-linked-title">{{ c.title }}</Link>
                                <span v-if="c.assigned_to_user" class="act-show-mini-avatar" :style="avatarStyle(c.assigned_to_user.avatar_colour)" :title="c.assigned_to_user.name">{{ initials(c.assigned_to_user.name) }}</span>
                                <span class="act-show-linked-due" :class="{ overdue: c.is_overdue }">{{ formatDueShort(c.due_at) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Related open activities -->
                    <div v-if="related.length" class="card">
                        <div class="card-header">
                            <div class="h-icon"><IconCheckbox :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Other activities</h3>
                                <div class="sub">Open on this customer</div>
                            </div>
                        </div>
                        <div class="act-show-related-list">
                            <Link
                                v-for="r in related"
                                :key="r.id"
                                :href="`/activities/${r.id}`"
                                class="act-show-related-row"
                            >
                                <span class="act-show-linked-icon" :style="{ background: r.type_colour }">
                                    <component :is="iconByName(r.type_icon)" :size="12" stroke-width="2" color="#fff" />
                                </span>
                                <div class="act-show-related-text">
                                    <div class="act-show-related-title">{{ r.title }}</div>
                                    <div v-if="r.due_at" class="act-show-related-due" :class="{ overdue: r.is_overdue }">
                                        {{ formatDueShort(r.due_at) }}
                                    </div>
                                </div>
                                <IconArrowRight :size="14" stroke-width="1.75" />
                            </Link>
                        </div>
                    </div>

                    <!-- Customer context -->
                    <div v-if="task.customer" class="card">
                        <div class="card-header">
                            <div class="h-icon"><IconUser :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Customer</h3>
                                <div class="sub">Quick context</div>
                            </div>
                        </div>
                        <div class="act-show-customer-body">
                            <Link :href="`/customers/${task.customer.id}`" class="act-show-customer-row">
                                <div class="act-show-customer-name">{{ task.customer.name }}</div>
                                <div v-if="task.customer.city" class="act-show-customer-sub">{{ task.customer.city }}</div>
                                <span class="act-show-customer-link">View customer<IconArrowRight :size="13" stroke-width="1.75" /></span>
                            </Link>
                            <div v-if="task.contact" class="act-show-contact">
                                <div class="act-show-section-label">Contact</div>
                                <div class="act-show-contact-name">
                                    <IconUserCheck :size="14" stroke-width="1.75" />
                                    {{ task.contact.name }}
                                    <span v-if="task.contact.job_title" class="muted"> · {{ task.contact.job_title }}</span>
                                </div>
                                <div v-if="task.contact.email" class="act-show-contact-line">
                                    <IconMail :size="12" stroke-width="1.75" />
                                    {{ task.contact.email }}
                                </div>
                                <div v-if="task.contact.phone" class="act-show-contact-line">
                                    <IconPhone :size="12" stroke-width="1.75" />
                                    {{ task.contact.phone }}
                                </div>
                            </div>
                            <div class="act-show-quick-actions">
                                <Link :href="`/support?customer=${task.customer.id}`" class="act-show-quick">
                                    <IconHeadset :size="14" stroke-width="1.75" />
                                    Open support ticket
                                    <IconArrowRight :size="12" stroke-width="1.75" />
                                </Link>
                                <Link :href="`/invoices?customer=${task.customer.id}`" class="act-show-quick">
                                    <IconReceipt :size="14" stroke-width="1.75" />
                                    View invoices
                                    <IconArrowRight :size="12" stroke-width="1.75" />
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ COMPLETE MODAL ═══ -->
        <ConfirmModal
            v-model:show="showCompleteModal"
            :title="`Complete: ${task.title}`"
            message=""
            confirm-label="Mark complete"
            variant="primary"
            :loading="completing"
            @confirm="performComplete"
        >
            <div class="form-field" style="margin-top: 8px;">
                <label>Outcome / notes (optional)</label>
                <textarea
                    v-model="outcomeText"
                    rows="3"
                    maxlength="2000"
                    placeholder="What was the result? Any follow-up needed?"
                    style="width: 100%; font: inherit; padding: 8px 10px; border: 1px solid var(--border); border-radius: var(--radius-md);"
                />
            </div>
        </ConfirmModal>

        <!-- ═══ DELETE MODAL ═══ -->
        <ConfirmModal
            v-model:show="showDeleteModal"
            :title="`Delete '${task.title}'?`"
            message="This activity will be permanently removed along with any notes attached to it. This cannot be undone."
            confirm-label="Delete activity"
            variant="danger"
            :loading="deleting"
            @confirm="performDelete"
        />

        <!-- ═══ LINKED SUB-TASK SLIDE-OVER ═══ -->
        <TransitionRoot as="template" :show="showSubTaskForm">
            <Dialog as="div" class="slide-over-dialog" @close="showSubTaskForm = false">
                <TransitionChild
                    as="template"
                    enter="transition-opacity ease-out duration-200" enter-from="opacity-0" enter-to="opacity-100"
                    leave="transition-opacity ease-in duration-150" leave-from="opacity-100" leave-to="opacity-0"
                >
                    <div class="slide-over-backdrop" />
                </TransitionChild>
                <TransitionChild
                    as="template"
                    enter="transform transition ease-out duration-200" enter-from="translate-x-full" enter-to="translate-x-0"
                    leave="transform transition ease-in duration-150" leave-from="translate-x-0" leave-to="translate-x-full"
                >
                    <DialogPanel class="slide-over-panel">
                        <form class="slide-over-form" @submit.prevent="submitSubTask">
                            <header class="slide-over-header">
                                <h2>New linked task</h2>
                                <button type="button" class="icon-btn" aria-label="Close" @click="showSubTaskForm = false">
                                    <IconX :size="18" stroke-width="1.75" />
                                </button>
                            </header>
                            <div class="slide-over-body">
                                <div class="form-section">
                                    <div class="muted-note">
                                        Will be linked to <strong>{{ task.title }}</strong>
                                        <template v-if="task.customer"> · {{ task.customer.name }}</template>
                                    </div>
                                </div>
                                <div class="form-section">
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Title<span class="req">*</span></label>
                                            <input v-model="subTaskForm.title" type="text" required maxlength="500" :class="{ 'has-err': subTaskForm.errors.title }">
                                            <div v-if="subTaskForm.errors.title" class="err">{{ subTaskForm.errors.title }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Details</label>
                                            <textarea v-model="subTaskForm.description" rows="3" maxlength="5000" />
                                        </div>
                                    </div>
                                </div>
                                <div class="form-section">
                                    <div class="form-row">
                                        <div class="form-field">
                                            <label>Priority</label>
                                            <div class="priority-pills">
                                                <button
                                                    v-for="p in ['low', 'medium', 'high']"
                                                    :key="p"
                                                    type="button"
                                                    class="pp-btn"
                                                    :class="[p, { active: subTaskForm.priority === p }]"
                                                    @click="subTaskForm.priority = p"
                                                >{{ p.charAt(0).toUpperCase() + p.slice(1) }}</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-section">
                                    <div class="form-row">
                                        <div class="form-field">
                                            <label>Due date</label>
                                            <input v-model="subTaskForm.due_at" type="date" :min="todayIso">
                                        </div>
                                        <div class="form-field">
                                            <label>Time (optional)</label>
                                            <input v-model="subTaskForm.due_time" type="time">
                                        </div>
                                    </div>
                                </div>
                                <div v-if="contacts.length" class="form-section">
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Contact (optional)</label>
                                            <select v-model="subTaskForm.contact_id">
                                                <option :value="null">— no specific contact —</option>
                                                <option v-for="c in contacts" :key="c.id" :value="c.id">{{ c.name }}</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-section">
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Assign to<span class="req">*</span></label>
                                            <select v-model="subTaskForm.assigned_to" :class="{ 'has-err': subTaskForm.errors.assigned_to }">
                                                <option v-for="u in staff" :key="u.id" :value="u.id">{{ u.name }}</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <footer class="slide-over-footer">
                                <button type="button" class="btn btn-secondary" @click="showSubTaskForm = false">Cancel</button>
                                <button type="submit" class="btn btn-primary" :disabled="subTaskForm.processing">
                                    <IconPlus :size="14" stroke-width="2" />
                                    {{ subTaskForm.processing ? 'Creating…' : 'Create linked task' }}
                                </button>
                            </footer>
                        </form>
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </TransitionRoot>
    </InternalLayout>
</template>
