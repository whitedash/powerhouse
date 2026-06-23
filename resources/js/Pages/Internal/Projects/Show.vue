<script setup>
/**
 * Project detail — 5 tabs (Overview / Board / Tasks / Time / Activity).
 *
 * The Board tab carries the most weight here: two render modes
 * (milestone vs. status), each implementing HTML5 drag-and-drop
 * against either /tasks/reorder (move between milestones) or
 * /tasks/{id}/status (move between status columns).
 *
 * All mutations go through router.post / useForm so the toast
 * container picks them up automatically.
 */
import { computed, ref, reactive } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    IconX, IconArchive, IconEdit, IconDots, IconPlus,
    IconClock, IconReceipt, IconAlertTriangle, IconCirclePlus,
    IconLayoutColumns, IconList, IconHistory,
    IconUpload, IconTrash, IconSearch, IconFiles, IconFile,
    IconFileTypePdf, IconFileTypeDoc, IconFileTypeXls, IconFileTypePpt,
    IconPhoto, IconFileZip, IconFileText, IconLoader2, IconCheckbox,
    IconVirus, IconCircleCheck, IconInfoCircle, IconExternalLink,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';
import { useDirtyClose } from '@/Composables/useDirtyClose';

const props = defineProps({
    project: { type: Object, required: true },
    time_summary: { type: Object, required: true },
    costs: { type: Object, default: () => ({ time_breakdown: [], expenses: [] }) },
    suppliers: { type: Array, default: () => [] },
    staff: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
    billing_entities: { type: Array, default: () => [] },
    activity: { type: Array, default: () => [] },
    files: { type: Array, default: () => [] },
    file_summary: { type: Object, default: () => ({ total: 0, pending: 0, infected: 0 }) },
    file_max_mb: { type: Number, default: 10 },
    clamav_available: { type: Boolean, default: false },
});

/* ─── Tabs ─── */
const activeTab = ref('overview');
const TABS = [
    { key: 'overview', label: 'Overview' },
    { key: 'board',    label: 'Board' },
    { key: 'tasks',    label: 'Tasks' },
    { key: 'time',     label: 'Time' },
    { key: 'cost',     label: 'Cost' },
    { key: 'files',    label: 'Files' },
    { key: 'activity', label: 'Activity' },
];

/* ─── Status / priority label maps ─── */
const STATUS_LABEL = {
    todo: 'To do', in_progress: 'In progress', in_review: 'In review',
    blocked: 'Blocked', complete: 'Complete', cancelled: 'Cancelled',
    planning: 'Planning', active: 'Active', on_hold: 'On hold',
    completed: 'Completed', pending: 'Pending',
};
function statusLabel(s) { return STATUS_LABEL[s] ?? s; }

const PRIORITY_LABEL = {
    low: 'Low', medium: 'Medium', high: 'High', urgent: 'Urgent',
};
function priorityLabel(p) { return PRIORITY_LABEL[p] ?? p; }

/* ─── Cost tab ─── */
const fmt = (val) => Number(val ?? 0).toLocaleString('en-GB', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const EXPENSE_CATEGORIES = [
    'software', 'hosting', 'travel', 'office',
    'marketing', 'advertising', 'equipment', 'other',
];

const expenseToday = new Date().toISOString().slice(0, 10);
const showAddExpense = ref(false);
const expenseForm = useForm({
    description: '',
    category: 'other',
    supplier_id: '',
    amount: '',
    vat_rate: 20,
    expense_date: expenseToday,
    project_id: props.project.id,
    receipt: null,
});

// Auto-fill category + VAT from the chosen supplier's defaults.
function onExpenseSupplierChange() {
    const s = props.suppliers.find((x) => x.id === Number(expenseForm.supplier_id));
    if (! s) return;
    if (s.default_expense_category) expenseForm.category = s.default_expense_category;
    if (s.default_vat_rate !== null && s.default_vat_rate !== undefined) expenseForm.vat_rate = s.default_vat_rate;
}

function onExpenseReceipt(e) {
    expenseForm.receipt = e.target.files?.[0] ?? null;
}

function submitExpense() {
    expenseForm
        .transform((d) => ({ ...d, supplier_id: d.supplier_id || null }))
        .post('/expenses', {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                showAddExpense.value = false;
                expenseForm.reset();
                expenseForm.project_id = props.project.id;
                expenseForm.expense_date = expenseToday;
                // Refresh just the cost payload so the new row + totals appear.
                router.reload({ only: ['costs'] });
            },
        });
}

/* ─── Member helpers ─── */
function initials(name) {
    return (name || '').split(/\s+/).map(p => p[0]).slice(0, 2).join('').toUpperCase();
}

/* ─── Files tab ─── */
const FILE_ICON = {
    pdf: IconFileTypePdf, doc: IconFileTypeDoc, xls: IconFileTypeXls,
    ppt: IconFileTypePpt, image: IconPhoto, zip: IconFileZip,
    text: IconFileText, file: IconFile,
};
function fileIcon(key) { return FILE_ICON[key] ?? IconFile; }

const uploading = ref(false);
const fileSearch = ref('');
const isDragging = ref(false);

const filteredFiles = computed(() => props.files.filter((f) =>
    !fileSearch.value
    || f.filename.toLowerCase().includes(fileSearch.value.toLowerCase())
    || (f.task_title || '').toLowerCase().includes(fileSearch.value.toLowerCase()),
));

function uploadFiles(event) {
    const list = event.target?.files || event.dataTransfer?.files;
    if (!list?.length) return;
    uploading.value = true;
    const formData = new FormData();
    Array.from(list).forEach((f) => formData.append('files[]', f));
    router.post(`/projects/${props.project.id}/files`, formData, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            uploading.value = false;
            if (event.target) event.target.value = '';
        },
    });
}

const showDeleteFileModal = ref(false);
const fileToDelete = ref(null);
function askDeleteFile(id) {
    fileToDelete.value = id;
    showDeleteFileModal.value = true;
}
function performDeleteFile() {
    if (fileToDelete.value === null) return;
    router.delete(`/projects/files/${fileToDelete.value}`, {
        preserveScroll: true,
        onFinish: () => {
            showDeleteFileModal.value = false;
            fileToDelete.value = null;
        },
    });
}

function fileDragOver() { isDragging.value = true; }
function fileDragLeave() { isDragging.value = false; }
function fileDrop(e) { isDragging.value = false; uploadFiles(e); }

/* ─── Archive confirm ─── */
const showArchiveConfirm = ref(false);
function confirmArchive() {
    router.delete(`/projects/${props.project.id}`, {
        preserveScroll: false,
        onFinish: () => { showArchiveConfirm.value = false; },
    });
}

/* ─── Edit project slide-over ─── */
const showEdit = ref(false);
const editForm = useForm({
    title: props.project.title,
    description: props.project.description,
    customer_id: props.project.customer_id,
    status: props.project.status,
    priority: props.project.priority,
    colour: props.project.colour,
    start_date: '',
    due_date: props.project.due_date_raw ?? '',
    budget: props.project.budget,
    hourly_rate: props.project.hourly_rate,
    project_lead: props.project.lead?.id ?? null,
    member_ids: props.project.members.map(m => m.id),
});
function openEdit() { showEdit.value = true; }
function submitEdit() {
    editForm.put(`/projects/${props.project.id}`, {
        preserveScroll: true,
        onSuccess: () => { showEdit.value = false; },
    });
}

/* ─── Milestone slide-over (add + edit) ─── */
const milestoneForm = useForm({
    id: null,
    project_id: props.project.id,
    title: '',
    description: '',
    due_date: '',
    status: 'pending',
});
const showMilestone = ref(false);
function openNewMilestone() {
    milestoneForm.reset();
    milestoneForm.project_id = props.project.id;
    milestoneForm.id = null;
    showMilestone.value = true;
}
function openEditMilestone(m) {
    milestoneForm.id = m.id;
    milestoneForm.project_id = props.project.id;
    milestoneForm.title = m.title;
    milestoneForm.description = m.description;
    milestoneForm.due_date = m.due_date_raw ?? '';
    milestoneForm.status = m.status;
    showMilestone.value = true;
}
function submitMilestone() {
    if (milestoneForm.id) {
        milestoneForm.put(`/milestones/${milestoneForm.id}`, {
            preserveScroll: true,
            onSuccess: () => { showMilestone.value = false; },
        });
    } else {
        milestoneForm.post('/milestones', {
            preserveScroll: true,
            onSuccess: () => { showMilestone.value = false; },
        });
    }
}
function deleteMilestone(id) {
    router.delete(`/milestones/${id}`, { preserveScroll: true });
}

/* ─── Quick-add task per column ─── */
const quickAddOpen = reactive({});
const quickAddTitle = reactive({});
const quickAddDue = reactive({});
const quickAddError = reactive({});

function openQuickAdd(key) {
    quickAddOpen[key] = true;
    quickAddTitle[key] = '';
    quickAddDue[key] = '';
    quickAddError[key] = '';
}
function closeQuickAdd(key) { quickAddOpen[key] = false; quickAddError[key] = ''; }

function submitQuickAdd(milestoneId) {
    const key = milestoneId ?? 'unassigned';
    const title = (quickAddTitle[key] ?? '').trim();
    if (! title) return;

    // due_at is mandatory for an actionable task (Part A). Guard client-side
    // so the quick-add can't fire a request the server is bound to 422 — the
    // exact regression this introduced before a due field existed here.
    if (! quickAddDue[key]) {
        quickAddError[key] = 'A due date is required.';
        return;
    }

    router.post('/tasks', {
        title,
        type: 'task',
        due_at: quickAddDue[key],
        project_id: props.project.id,
        milestone_id: milestoneId,
        assigned_to: null,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            quickAddTitle[key] = '';
            quickAddDue[key] = '';
            quickAddError[key] = '';
            closeQuickAdd(key);
        },
        onError: (errors) => {
            quickAddError[key] = errors.due_at ?? errors.title ?? 'Could not create the task.';
        },
    });
}

/* ─── Board mode (milestone vs status) ─── */
const boardMode = ref('milestone');

const tasksByMilestone = computed(() => {
    const buckets = {};
    for (const m of props.project.milestones) buckets[m.id] = [];
    buckets.unassigned = [];
    for (const t of props.project.tasks) {
        const k = t.milestone_id ?? 'unassigned';
        if (! buckets[k]) buckets[k] = [];
        buckets[k].push(t);
    }
    // Sort by sort_order so the rendered order matches the drag state.
    for (const k of Object.keys(buckets)) {
        buckets[k].sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0));
    }
    return buckets;
});

const STATUS_ORDER = ['todo', 'in_progress', 'in_review', 'blocked', 'complete'];
const tasksByStatus = computed(() => {
    const buckets = {};
    for (const s of STATUS_ORDER) buckets[s] = [];
    for (const t of props.project.tasks) {
        if (! buckets[t.status]) buckets[t.status] = [];
        buckets[t.status].push(t);
    }
    return buckets;
});

/* ─── Drag and drop ─── */
const dragState = reactive({ taskId: null, fromKey: null });

function onDragStart(task, fromKey) {
    dragState.taskId = task.id;
    dragState.fromKey = fromKey;
}
function onDragOver(e) { e.preventDefault(); }

function onDropMilestone(toKey) {
    if (! dragState.taskId) return;
    const taskId = dragState.taskId;
    const target = toKey === 'unassigned' ? null : Number(toKey);
    // Snapshot the destination column as it is after the drop; we
    // ask the server to renumber sort_order on every card so the
    // local UI doesn't have to guess.
    const dest = (tasksByMilestone.value[toKey] ?? [])
        .filter(t => t.id !== taskId);
    // Append for now — we don't yet support precise drop-position;
    // the operator can drag again to reorder within the column.
    dest.push({ id: taskId });

    const items = dest.map((t, i) => ({
        id: t.id, sort_order: i + 1, milestone_id: target,
    }));

    // Optimistic move: re-point the task locally so the card jumps to
    // its new column immediately. tasksByMilestone is computed off
    // project.tasks, so mutating the task re-buckets it with no reload.
    const task = props.project.tasks.find(t => t.id === taskId);
    const prev = task ? { milestone_id: task.milestone_id, sort_order: task.sort_order } : null;
    if (task) {
        task.milestone_id = target;
        task.sort_order = items.find(i => i.id === taskId)?.sort_order ?? task.sort_order;
    }

    dragState.taskId = null;
    dragState.fromKey = null;

    // Reorder is a JSON endpoint — Inertia's router.post() would
    // throw because the response is {"ok":true}, not an Inertia page.
    // fetch() bypasses the Inertia adapter cleanly. Revert the local
    // move if the server rejects it.
    fetch('/tasks/reorder', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ items }),
    }).then((res) => {
        if (! res.ok) throw new Error('reorder failed');
    }).catch(() => {
        if (task && prev) {
            task.milestone_id = prev.milestone_id;
            task.sort_order = prev.sort_order;
        }
    });
}

function onDropStatus(toStatus) {
    if (! dragState.taskId) return;
    // Moving into 'blocked' requires a reason — CLAUDE.md bans
    // window.prompt() for this, so we open the BlockedReasonModal
    // and finish the transition when the operator confirms.
    if (toStatus === 'blocked') {
        blockedTaskId.value = dragState.taskId;
        blockedReason.value = '';
        showBlockedModal.value = true;
        dragState.taskId = null;
        dragState.fromKey = null;
        return;
    }
    const taskId = dragState.taskId;
    // Optimistic move: flip the task's status locally so it lands in the
    // target column instantly; tasksByStatus re-buckets off project.tasks.
    const task = props.project.tasks.find(t => t.id === taskId);
    const prevStatus = task ? task.status : null;
    if (task) task.status = toStatus;

    dragState.taskId = null;
    dragState.fromKey = null;

    // Drag-drop status changes use fetch() — same reason as the
    // reorder endpoint: the controller switches to JSON when the
    // request looks XHR-shaped, and Inertia would otherwise reject
    // a non-Inertia response. Revert locally if the server rejects.
    fetch(`/tasks/${taskId}/status`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ status: toStatus }),
    }).then((res) => {
        if (! res.ok) throw new Error('status change failed');
    }).catch(() => {
        if (task) task.status = prevStatus;
    });
}

/* ─── Blocked-reason modal (replacement for window.prompt) ─── */
const showBlockedModal = ref(false);
const blockedTaskId = ref(null);
const blockedReason = ref('');
function confirmBlocked() {
    if (! blockedReason.value.trim() || ! blockedTaskId.value) return;
    const id = blockedTaskId.value;
    const reason = blockedReason.value.trim();
    // Optimistic move into the Blocked column with its reason.
    const task = props.project.tasks.find(t => t.id === id);
    const prev = task ? { status: task.status, blocked_reason: task.blocked_reason } : null;
    if (task) {
        task.status = 'blocked';
        task.blocked_reason = reason;
    }
    // Same fetch path as the kanban drag handlers — the modal sits
    // on top of the same kanban, so any router.post() would refuse
    // the JSON response from updateStatus().
    fetch(`/tasks/${id}/status`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ status: 'blocked', blocked_reason: reason }),
    }).then((res) => {
        if (! res.ok) throw new Error('status change failed');
    }).catch(() => {
        if (task && prev) {
            task.status = prev.status;
            task.blocked_reason = prev.blocked_reason;
        }
    }).finally(() => {
        showBlockedModal.value = false;
        blockedTaskId.value = null;
        blockedReason.value = '';
    });
}

/* ─── Time logging ─── */
const showLogTime = ref(false);
const logForm = useForm({
    task_id: null,
    hours: 0,
    minutes: 30,
    description: '',
    logged_at: new Date().toISOString().slice(0, 10),
    is_billable: true,
    hourly_rate: '',
});
function openLogTime() {
    logForm.reset();
    logForm.logged_at = new Date().toISOString().slice(0, 10);
    logForm.minutes = 30;
    logForm.is_billable = true;
    showLogTime.value = true;
}
function submitLogTime() {
    const totalMinutes = (Number(logForm.hours) || 0) * 60 + (Number(logForm.minutes) || 0);
    if (totalMinutes <= 0) return;
    router.post('/time-entries', {
        task_id: logForm.task_id,
        minutes: totalMinutes,
        description: logForm.description,
        logged_at: logForm.logged_at,
        is_billable: logForm.is_billable,
        hourly_rate: logForm.hourly_rate || null,
    }, {
        preserveScroll: true,
        onSuccess: () => { showLogTime.value = false; },
    });
}

/* ─── Invoice generation ─── */
const showInvoiceModal = ref(false);
const invoiceForm = useForm({
    entry_ids: [],
    billing_entity_id: null,
    hourly_rate: props.project.hourly_rate ?? '',
});
const unbilledEntries = computed(() => props.project.time_entries.filter(e => e.is_billable && ! e.invoice_id));

function openInvoiceModal() {
    invoiceForm.entry_ids = unbilledEntries.value.map(e => e.id);
    invoiceForm.billing_entity_id = props.billing_entities[0]?.id ?? null;
    invoiceForm.hourly_rate = props.project.hourly_rate ?? '';
    showInvoiceModal.value = true;
}
function toggleInvoiceEntry(id) {
    const i = invoiceForm.entry_ids.indexOf(id);
    if (i >= 0) invoiceForm.entry_ids.splice(i, 1);
    else invoiceForm.entry_ids.push(id);
}
function submitInvoice() {
    invoiceForm.post(`/projects/${props.project.id}/invoice`, { preserveScroll: false });
}

/* ─── Delete time entry ─── */
const showDeleteEntry = ref(false);
const entryToDelete = ref(null);
function askDeleteEntry(id) {
    entryToDelete.value = id;
    showDeleteEntry.value = true;
}
function confirmDeleteEntry() {
    if (! entryToDelete.value) return;
    router.delete(`/time-entries/${entryToDelete.value}`, {
        preserveScroll: true,
        onFinish: () => { showDeleteEntry.value = false; entryToDelete.value = null; },
    });
}

/* ─── Kanban task card ··· menu (edit / delete / status) ───
 *
 * The kanban card is a <Link> to /activities/{id}; the menu
 * button uses click.prevent.stop so navigation doesn't fire
 * underneath. State is keyed by task id; only one menu is
 * open at a time.
 */
const openTaskMenu = ref(null);
// The kanban board is an overflow-x:auto scroller, which clips any
// absolutely-positioned child. The menu is therefore teleported to
// <body> and positioned with fixed coords captured from the ··· button
// the moment it opens.
const taskMenuPos = ref({ top: 0, left: 0 });
function toggleTaskMenu(id, ev) {
    if (openTaskMenu.value === id) {
        openTaskMenu.value = null;

        return;
    }
    const r = ev.currentTarget.getBoundingClientRect();
    // Right-align the 176px popover under the button; never let it run
    // off the left edge.
    taskMenuPos.value = { top: r.bottom + 4, left: Math.max(8, r.right - 176) };
    openTaskMenu.value = id;
}
// Auto-close when clicking outside the button or the teleported menu.
function closeTaskMenuOnOutside(e) {
    if (! openTaskMenu.value) return;
    if (e.target.closest('.kt-menu-wrap') || e.target.closest('.kt-menu')) return;
    openTaskMenu.value = null;
}
if (typeof window !== 'undefined') {
    window.addEventListener('click', closeTaskMenuOnOutside, true);
}

/* Edit slide-over — mirrors the Customers/Show.vue task editor
 * pattern (transform + PUT /tasks/{id}). Submits via fetch() so
 * the kanban doesn't full-reload; we mutate the local card on
 * success and close. */
const showEditTask = ref(false);
const editingTaskId = ref(null);
const editTask = reactive({
    type: 'task',
    title: '',
    description: '',
    priority: 'medium',
    assigned_to: null,
    due_at: '',
    estimated_hours: null,
    milestone_id: null,
});

function openEditTask(t) {
    openTaskMenu.value = null;
    editingTaskId.value = t.id;
    editTask.type = t.type ?? 'task';
    editTask.title = t.title ?? '';
    editTask.description = t.description ?? '';
    editTask.priority = t.priority ?? 'medium';
    editTask.assigned_to = t.assigned_to?.id ?? t.assigned_to ?? null;
    // due_at on the server is a TIMESTAMP; we only edit the date
    // portion from this slide-over to keep the form simple. Time
    // editing is on the activity detail page.
    editTask.due_at = t.due_at
        ? new Date(t.due_at).toISOString().slice(0, 10)
        : '';
    editTask.estimated_hours = t.estimated_hours ?? null;
    editTask.milestone_id = t.milestone_id ?? null;
    showEditTask.value = true;
}

function submitEditTask() {
    if (! editingTaskId.value) return;
    fetch(`/tasks/${editingTaskId.value}`, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ ...editTask }),
    }).then(() => {
        // Hydrate the kanban + tasks lists from the server so any
        // computed fields (assigned_to relation, total_hours) update.
        router.reload({ only: ['project'] });
        showEditTask.value = false;
        editingTaskId.value = null;
    });
}

/* Delete confirm */
const showDeleteTask = ref(false);
const taskToDelete = ref(null);
function askDeleteTask(t) {
    openTaskMenu.value = null;
    taskToDelete.value = t;
    showDeleteTask.value = true;
}
function confirmDeleteTask() {
    if (! taskToDelete.value) return;
    const id = taskToDelete.value.id;
    fetch(`/tasks/${id}`, {
        method: 'DELETE',
        headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
            'X-Requested-With': 'XMLHttpRequest',
        },
    }).then(() => {
        router.reload({ only: ['project'] });
        showDeleteTask.value = false;
        taskToDelete.value = null;
    });
}

/* In-menu status change — bypasses the modal-required 'blocked'
 * status (drag into Blocked column for that). */
function menuStatusChange(taskId, status) {
    openTaskMenu.value = null;
    fetch(`/tasks/${taskId}/status`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ status }),
    }).then(() => router.reload({ only: ['project'] }));
}

/* ─── Status quick-change inline (Tasks tab) ─── */
function quickStatus(taskId, status) {
    router.post(`/tasks/${taskId}/status`, { status }, { preserveScroll: true });
}

/* ─── Activity icon resolver ─── */
function actionLabel(action) {
    const map = {
        'project.created': 'Project created',
        'project.updated': 'Project updated',
        'project.archived': 'Project archived',
        'project.invoice_generated': 'Invoice generated from time',
        'milestone.created': 'Milestone added',
        'milestone.updated': 'Milestone updated',
        'milestone.deleted': 'Milestone removed',
        'task.created': 'Task added',
        'task.updated': 'Task updated',
        'task.status_changed': 'Task status changed',
        'task.deleted': 'Task removed',
        'time_entry.created': 'Time logged',
        'time_entry.updated': 'Time entry updated',
        'time_entry.deleted': 'Time entry removed',
    };
    return map[action] ?? action;
}

/* ─── Unsaved-changes discard guards (Issue B). Route every close surface
 *     (overlay dismiss, X, Escape, Cancel) through <guard>.attemptClose. ─── */
const editGuard = useDirtyClose(() => editForm.isDirty, () => { showEdit.value = false; });
const milestoneGuard = useDirtyClose(() => milestoneForm.isDirty, () => { showMilestone.value = false; });
const logTimeGuard = useDirtyClose(() => logForm.isDirty, () => { showLogTime.value = false; });
const expenseGuard = useDirtyClose(() => expenseForm.isDirty, () => { showAddExpense.value = false; });
const invoiceGuard = useDirtyClose(() => invoiceForm.isDirty, () => { showInvoiceModal.value = false; });
const blockedGuard = useDirtyClose(() => blockedReason.value.trim() !== '', () => { showBlockedModal.value = false; });
</script>

<template>
    <Head :title="project.title" />

    <InternalLayout
        :title="project.title"
        active-nav="projects"
        :breadcrumbs="[{ label: 'Powerhouse', href: '/' }, { label: 'Projects', href: '/projects' }, { label: project.title }]"
    >
        <div class="projects-show">
            <!-- ─── Header ─── -->
            <div class="project-header">
                <div class="project-header-left">
                    <span class="project-colour-disc" :style="{ background: project.colour }"></span>
                    <div class="project-title-block">
                        <h1>{{ project.title }}</h1>
                        <div class="project-header-meta">
                            <Link v-if="project.customer_id" :href="`/customers/${project.customer_id}`" class="meta-link">
                                {{ project.customer_name }}
                            </Link>
                            <span v-else class="muted">Internal project</span>
                            <span class="status-badge" :class="`status-${project.status}`">{{ statusLabel(project.status) }}</span>
                            <span class="priority-badge" :class="`pri-${project.priority}`">{{ priorityLabel(project.priority) }}</span>
                            <span v-if="project.due_date" :class="['meta-due', { overdue: project.is_overdue }]">
                                Due {{ project.due_date }}
                            </span>
                        </div>
                    </div>
                </div>
                <div class="project-header-right">
                    <div v-if="project.lead" class="lead-chip">
                        <span class="av sm" :style="{ background: project.lead.avatar_colour ?? 'var(--text-tertiary)' }">{{ initials(project.lead.name) }}</span>
                        <span>{{ project.lead.name }}</span>
                    </div>
                    <button class="btn btn-ghost" @click="openEdit">
                        <IconEdit :size="14" stroke-width="2" />
                        Edit
                    </button>
                    <button class="btn btn-ghost danger" @click="showArchiveConfirm = true">
                        <IconArchive :size="14" stroke-width="2" />
                        Archive
                    </button>
                </div>
            </div>

            <!-- ─── Tabs ─── -->
            <nav class="tabs">
                <button
                    v-for="t in TABS"
                    :key="t.key"
                    type="button"
                    class="tab"
                    :class="{ active: activeTab === t.key }"
                    @click="activeTab = t.key"
                >
                    {{ t.label }}
                    <span v-if="t.key === 'files' && file_summary.total" class="count">{{ file_summary.total }}</span>
                    <span v-if="t.key === 'files' && file_summary.infected" class="tab-danger-dot" title="Infected files detected" />
                    <span v-if="t.key === 'cost' && costs.over_budget" class="tab-danger-dot" title="Over budget" />
                </button>
            </nav>

            <!-- ─── OVERVIEW TAB ─── -->
            <div v-if="activeTab === 'overview'" class="ov-grid">
                <!-- LEFT — milestones progress -->
                <div class="ov-left">
                    <section class="card">
                        <div class="card-head">
                            <h3>Milestones</h3>
                            <button type="button" class="ghost-link" @click="openNewMilestone">
                                <IconPlus :size="14" stroke-width="2" />
                                Add milestone
                            </button>
                        </div>
                        <div class="card-body">
                            <div v-if="project.milestones.length === 0" class="muted center">
                                No milestones yet. Add one to start grouping tasks into phases.
                            </div>
                            <div v-for="m in project.milestones" :key="m.id" class="milestone-row">
                                <div class="milestone-head">
                                    <strong>{{ m.title }}</strong>
                                    <span v-if="m.due_date" :class="{ 'overdue text-danger': m.is_overdue }">
                                        Due {{ m.due_date }}
                                    </span>
                                    <span class="status-badge" :class="`status-${m.status}`">{{ statusLabel(m.status) }}</span>
                                    <button type="button" class="icon-btn sm ml-auto" @click="openEditMilestone(m)">
                                        <IconEdit :size="14" stroke-width="2" />
                                    </button>
                                </div>
                                <div class="milestone-progress">
                                    <div class="project-progress-bar">
                                        <div class="project-progress-fill" :style="{ width: m.progress + '%' }"></div>
                                    </div>
                                    <span class="muted small">{{ m.completed_count }}/{{ m.tasks_count }} tasks · {{ m.progress }}%</span>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>

                <!-- RIGHT — stats + team + activity -->
                <div class="ov-right">
                    <section class="card">
                        <div class="card-head"><h3>Project stats</h3></div>
                        <div class="card-body stats-list">
                            <div class="stat-row">
                                <span class="label">Progress</span>
                                <strong>{{ project.progress }}%</strong>
                            </div>
                            <div class="stat-row">
                                <span class="label">Tasks</span>
                                <strong>{{ project.tasks.filter(t => t.status === 'complete').length }}/{{ project.tasks.length }}</strong>
                            </div>
                            <div class="stat-row">
                                <span class="label">Milestones</span>
                                <strong>{{ project.milestones.filter(m => m.status === 'completed').length }}/{{ project.milestones.length }}</strong>
                            </div>
                            <div class="stat-row">
                                <span class="label">Hours logged</span>
                                <strong>{{ time_summary.total_hours }}h</strong>
                            </div>
                            <div class="stat-row" :class="{ amber: time_summary.unbilled_hours > 0 }">
                                <span class="label">Unbilled</span>
                                <strong>{{ time_summary.unbilled_hours }}h (£{{ time_summary.unbilled_amount.toFixed(2) }})</strong>
                            </div>
                            <div v-if="project.budget" class="stat-row">
                                <span class="label">Budget</span>
                                <strong>£{{ Number(project.budget).toFixed(2) }}</strong>
                            </div>
                        </div>
                    </section>

                    <section class="card">
                        <div class="card-head"><h3>Team</h3></div>
                        <div class="card-body team-list">
                            <div v-for="m in project.members" :key="m.id" class="team-row">
                                <span class="av sm" :style="{ background: m.avatar_colour ?? 'var(--text-tertiary)' }">{{ initials(m.name) }}</span>
                                <span class="team-name">{{ m.name }}</span>
                                <span class="role-badge" :class="`role-${m.role}`">{{ m.role }}</span>
                            </div>
                            <div v-if="project.members.length === 0" class="muted">No team members yet.</div>
                        </div>
                    </section>

                    <section class="card">
                        <div class="card-head"><h3>Recent activity</h3></div>
                        <div class="card-body activity-mini">
                            <div v-for="a in activity.slice(0, 5)" :key="a.id" class="activity-mini-row">
                                <span class="muted small">{{ a.time_ago }}</span>
                                <span>{{ actionLabel(a.action) }}</span>
                            </div>
                            <div v-if="activity.length === 0" class="muted">No activity yet.</div>
                        </div>
                    </section>
                </div>
            </div>

            <!-- ─── BOARD TAB ─── -->
            <div v-else-if="activeTab === 'board'" class="board-wrap">
                <div class="board-modes">
                    <button class="board-mode-btn" :class="{ active: boardMode === 'milestone' }" @click="boardMode = 'milestone'">
                        <IconLayoutColumns :size="14" stroke-width="2" /> Milestone view
                    </button>
                    <button class="board-mode-btn" :class="{ active: boardMode === 'status' }" @click="boardMode = 'status'">
                        <IconList :size="14" stroke-width="2" /> Status view
                    </button>
                </div>

                <!-- Milestone board -->
                <div v-if="boardMode === 'milestone'" class="kanban-board">
                    <div
                        v-for="m in project.milestones"
                        :key="m.id"
                        class="kanban-column"
                        @dragover="onDragOver"
                        @drop="onDropMilestone(m.id)"
                    >
                        <div class="kanban-column-header">
                            <span class="kanban-column-title">{{ m.title }}</span>
                            <span class="kanban-column-count">{{ tasksByMilestone[m.id]?.length ?? 0 }}</span>
                            <button type="button" class="icon-btn xs" @click="openEditMilestone(m)">
                                <IconDots :size="14" stroke-width="2" />
                            </button>
                        </div>
                        <div v-if="m.due_date" :class="['muted', 'small', { 'text-danger': m.is_overdue }]">
                            Due {{ m.due_date }}
                        </div>
                        <div class="kanban-cards">
                            <div
                                v-for="t in tasksByMilestone[m.id] ?? []"
                                :key="t.id"
                                class="kt-card-wrap kt-menu-wrap"
                            >
                                <Link
                                    :href="`/activities/${t.id}`"
                                    class="kanban-task-card"
                                    draggable="true"
                                    @dragstart="onDragStart(t, m.id)"
                                >
                                    <div class="kt-head">
                                        <span class="priority-dot" :class="`pri-${t.priority}`"></span>
                                        <span class="kt-title">{{ t.title }}</span>
                                    </div>
                                    <div class="kt-meta">
                                        <span class="status-dot" :class="t.status"></span>
                                        <span class="muted small">{{ statusLabel(t.status) }}</span>
                                        <span v-if="t.due_at" class="muted small">· {{ new Date(t.due_at).toLocaleDateString('en-GB', { day:'2-digit', month:'short'}) }}</span>
                                    </div>
                                    <div v-if="t.assigned_to" class="kt-foot">
                                        <span class="av xs" :style="{ background: t.assigned_to.avatar_colour ?? 'var(--text-tertiary)' }">{{ initials(t.assigned_to.name) }}</span>
                                        <span v-if="t.total_hours > 0" class="muted small">{{ t.total_hours }}h logged</span>
                                    </div>
                                </Link>
                                <button
                                    type="button"
                                    class="kt-menu-btn"
                                    @click.prevent.stop="toggleTaskMenu(t.id, $event)"
                                >
                                    <IconDots :size="14" stroke-width="2" />
                                </button>
                                <Teleport to="body">
                                    <div
                                        v-if="openTaskMenu === t.id"
                                        class="row-menu kt-menu"
                                        :style="{ position: 'fixed', top: taskMenuPos.top + 'px', left: taskMenuPos.left + 'px', right: 'auto' }"
                                        @click.stop
                                    >
                                        <button type="button" @click="openEditTask(t)">
                                            <IconEdit :size="13" stroke-width="2" /> Edit task
                                        </button>
                                        <div class="kt-menu-sub muted small">Change status</div>
                                        <button type="button" @click="menuStatusChange(t.id, 'todo')">Todo</button>
                                        <button type="button" @click="menuStatusChange(t.id, 'in_progress')">In progress</button>
                                        <button type="button" @click="menuStatusChange(t.id, 'in_review')">In review</button>
                                        <button type="button" @click="menuStatusChange(t.id, 'complete')">Complete</button>
                                        <button type="button" class="danger" @click="askDeleteTask(t)">
                                            <IconX :size="13" stroke-width="2" /> Delete
                                        </button>
                                    </div>
                                </Teleport>
                            </div>
                        </div>
                        <div class="kanban-add-wrap">
                            <button v-if="!quickAddOpen[m.id]" type="button" class="kanban-add" @click="openQuickAdd(m.id)">
                                <IconPlus :size="13" stroke-width="2" /> Add task
                            </button>
                            <div v-else class="kanban-quick-add">
                                <input
                                    v-model="quickAddTitle[m.id]"
                                    type="text"
                                    placeholder="Task title…"
                                    autofocus
                                    @keyup.enter="submitQuickAdd(m.id)"
                                    @keyup.esc="closeQuickAdd(m.id)"
                                />
                                <input
                                    v-model="quickAddDue[m.id]"
                                    type="date"
                                    required
                                    aria-label="Due date"
                                    @keyup.enter="submitQuickAdd(m.id)"
                                    @keyup.esc="closeQuickAdd(m.id)"
                                />
                                <div v-if="quickAddError[m.id]" class="err">{{ quickAddError[m.id] }}</div>
                                <div class="qa-actions">
                                    <button class="btn btn-primary btn-sm" @click="submitQuickAdd(m.id)">Add</button>
                                    <button class="btn btn-ghost btn-sm" @click="closeQuickAdd(m.id)">Cancel</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Unassigned column -->
                    <div
                        class="kanban-column"
                        @dragover="onDragOver"
                        @drop="onDropMilestone('unassigned')"
                    >
                        <div class="kanban-column-header">
                            <span class="kanban-column-title">Unassigned</span>
                            <span class="kanban-column-count">{{ tasksByMilestone.unassigned?.length ?? 0 }}</span>
                        </div>
                        <div class="kanban-cards">
                            <div
                                v-for="t in tasksByMilestone.unassigned ?? []"
                                :key="t.id"
                                class="kt-card-wrap kt-menu-wrap"
                            >
                                <Link
                                    :href="`/activities/${t.id}`"
                                    class="kanban-task-card"
                                    draggable="true"
                                    @dragstart="onDragStart(t, 'unassigned')"
                                >
                                    <div class="kt-head">
                                        <span class="priority-dot" :class="`pri-${t.priority}`"></span>
                                        <span class="kt-title">{{ t.title }}</span>
                                    </div>
                                    <div class="kt-meta">
                                        <span class="status-dot" :class="t.status"></span>
                                        <span class="muted small">{{ statusLabel(t.status) }}</span>
                                    </div>
                                </Link>
                                <button type="button" class="kt-menu-btn" @click.prevent.stop="toggleTaskMenu(t.id, $event)">
                                    <IconDots :size="14" stroke-width="2" />
                                </button>
                                <Teleport to="body">
                                    <div
                                        v-if="openTaskMenu === t.id"
                                        class="row-menu kt-menu"
                                        :style="{ position: 'fixed', top: taskMenuPos.top + 'px', left: taskMenuPos.left + 'px', right: 'auto' }"
                                        @click.stop
                                    >
                                        <button type="button" @click="openEditTask(t)">
                                            <IconEdit :size="13" stroke-width="2" /> Edit task
                                        </button>
                                        <div class="kt-menu-sub muted small">Change status</div>
                                        <button type="button" @click="menuStatusChange(t.id, 'todo')">Todo</button>
                                        <button type="button" @click="menuStatusChange(t.id, 'in_progress')">In progress</button>
                                        <button type="button" @click="menuStatusChange(t.id, 'in_review')">In review</button>
                                        <button type="button" @click="menuStatusChange(t.id, 'complete')">Complete</button>
                                        <button type="button" class="danger" @click="askDeleteTask(t)">
                                            <IconX :size="13" stroke-width="2" /> Delete
                                        </button>
                                    </div>
                                </Teleport>
                            </div>
                        </div>
                        <div class="kanban-add-wrap">
                            <button v-if="!quickAddOpen.unassigned" type="button" class="kanban-add" @click="openQuickAdd('unassigned')">
                                <IconPlus :size="13" stroke-width="2" /> Add task
                            </button>
                            <div v-else class="kanban-quick-add">
                                <input v-model="quickAddTitle.unassigned" type="text" placeholder="Task title…" @keyup.enter="submitQuickAdd(null)" @keyup.esc="closeQuickAdd('unassigned')" />
                                <input v-model="quickAddDue.unassigned" type="date" required aria-label="Due date" @keyup.enter="submitQuickAdd(null)" @keyup.esc="closeQuickAdd('unassigned')" />
                                <div v-if="quickAddError.unassigned" class="err">{{ quickAddError.unassigned }}</div>
                                <div class="qa-actions">
                                    <button class="btn btn-primary btn-sm" @click="submitQuickAdd(null)">Add</button>
                                    <button class="btn btn-ghost btn-sm" @click="closeQuickAdd('unassigned')">Cancel</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="kanban-column" style="border: 2px dashed var(--border); background: transparent; align-self: flex-start;">
                        <button type="button" class="kanban-add" style="margin-top: 12px;" @click="openNewMilestone">
                            <IconCirclePlus :size="14" stroke-width="2" /> Add milestone
                        </button>
                    </div>
                </div>

                <!-- Status board -->
                <div v-else class="kanban-board">
                    <div
                        v-for="s in STATUS_ORDER"
                        :key="s"
                        class="kanban-column"
                        @dragover="onDragOver"
                        @drop="onDropStatus(s)"
                    >
                        <div class="kanban-column-header">
                            <span class="kanban-column-title">
                                <span class="status-dot" :class="s"></span>
                                {{ statusLabel(s) }}
                            </span>
                            <span class="kanban-column-count">{{ tasksByStatus[s]?.length ?? 0 }}</span>
                        </div>
                        <div class="kanban-cards">
                            <div
                                v-for="t in tasksByStatus[s] ?? []"
                                :key="t.id"
                                class="kt-card-wrap kt-menu-wrap"
                            >
                                <Link
                                    :href="`/activities/${t.id}`"
                                    class="kanban-task-card"
                                    draggable="true"
                                    @dragstart="onDragStart(t, s)"
                                >
                                    <div class="kt-head">
                                        <span class="priority-dot" :class="`pri-${t.priority}`"></span>
                                        <span class="kt-title">{{ t.title }}</span>
                                    </div>
                                    <div v-if="t.milestone_title" class="kt-meta">
                                        <span class="muted small">{{ t.milestone_title }}</span>
                                    </div>
                                    <div v-if="s === 'blocked' && t.blocked_reason" class="kt-blocked muted small">
                                        <IconAlertTriangle :size="12" stroke-width="2" />
                                        {{ t.blocked_reason }}
                                    </div>
                                </Link>
                                <button type="button" class="kt-menu-btn" @click.prevent.stop="toggleTaskMenu(t.id, $event)">
                                    <IconDots :size="14" stroke-width="2" />
                                </button>
                                <Teleport to="body">
                                    <div
                                        v-if="openTaskMenu === t.id"
                                        class="row-menu kt-menu"
                                        :style="{ position: 'fixed', top: taskMenuPos.top + 'px', left: taskMenuPos.left + 'px', right: 'auto' }"
                                        @click.stop
                                    >
                                        <button type="button" @click="openEditTask(t)">
                                            <IconEdit :size="13" stroke-width="2" /> Edit task
                                        </button>
                                        <button type="button" class="danger" @click="askDeleteTask(t)">
                                            <IconX :size="13" stroke-width="2" /> Delete
                                        </button>
                                    </div>
                                </Teleport>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── TASKS TAB ─── -->
            <section v-else-if="activeTab === 'tasks'" class="card">
                <div class="card-head">
                    <h3>Tasks ({{ project.tasks.length }})</h3>
                </div>
                <table class="tbl">
                    <thead>
                        <tr>
                            <th style="width: 130px;">Status</th>
                            <th>Task</th>
                            <th style="width: 160px;">Milestone</th>
                            <th style="width: 120px;">Assignee</th>
                            <th style="width: 120px;">Due</th>
                            <th style="width: 80px;">Hours</th>
                            <th style="width: 90px;">Priority</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="t in project.tasks" :key="t.id">
                            <td>
                                <select :value="t.status" @change="quickStatus(t.id, $event.target.value)" class="status-select">
                                    <option v-for="s in STATUS_ORDER.concat(['cancelled'])" :key="s" :value="s">{{ statusLabel(s) }}</option>
                                </select>
                            </td>
                            <td><Link :href="`/activities/${t.id}`" class="tbl-link">{{ t.title }}</Link></td>
                            <td>{{ t.milestone_title ?? '—' }}</td>
                            <td>
                                <span v-if="t.assigned_to" class="av-with-name">
                                    <span class="av xs" :style="{ background: t.assigned_to.avatar_colour ?? 'var(--text-tertiary)' }">{{ initials(t.assigned_to.name) }}</span>
                                    {{ t.assigned_to.name }}
                                </span>
                                <span v-else class="muted">—</span>
                            </td>
                            <td :class="{ 'text-danger': t.is_overdue }">
                                {{ t.due_at ? new Date(t.due_at).toLocaleDateString('en-GB', { day:'2-digit', month:'short' }) : '—' }}
                            </td>
                            <td>{{ t.total_hours > 0 ? `${t.total_hours}h` : '—' }}</td>
                            <td><span class="priority-dot" :class="`pri-${t.priority}`"></span> {{ priorityLabel(t.priority) }}</td>
                        </tr>
                        <tr v-if="project.tasks.length === 0">
                            <td colspan="7" class="muted center">No tasks yet.</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!-- ─── TIME TAB ─── -->
            <div v-else-if="activeTab === 'time'">
                <div class="time-summary-strip">
                    <div class="time-summary-card">
                        <span class="label">Total logged</span>
                        <strong>{{ time_summary.total_hours }}h</strong>
                    </div>
                    <div class="time-summary-card">
                        <span class="label">Billable</span>
                        <strong>{{ time_summary.billable_hours }}h</strong>
                    </div>
                    <div class="time-summary-card amber">
                        <span class="label">Unbilled</span>
                        <strong>{{ time_summary.unbilled_hours }}h</strong>
                    </div>
                    <div class="time-summary-card">
                        <span class="label">Billed</span>
                        <strong>{{ time_summary.billed_hours }}h</strong>
                    </div>
                </div>

                <div v-if="time_summary.unbilled_hours > 0" class="unbilled-banner">
                    <div>
                        <strong>{{ time_summary.unbilled_hours }}h unbilled · £{{ time_summary.unbilled_amount.toFixed(2) }}</strong>
                        <p class="muted small">Generate a draft invoice from these entries.</p>
                    </div>
                    <button class="btn btn-primary" @click="openInvoiceModal">
                        <IconReceipt :size="14" stroke-width="2" />
                        Generate invoice
                    </button>
                </div>

                <section class="card">
                    <div class="card-head">
                        <h3>Time entries</h3>
                        <button class="btn btn-primary btn-sm" @click="openLogTime">
                            <IconClock :size="13" stroke-width="2" />
                            Log time
                        </button>
                    </div>
                    <table class="tbl">
                        <thead>
                            <tr>
                                <th style="width: 100px;">Date</th>
                                <th>Task</th>
                                <th>Description</th>
                                <th style="width: 140px;">User</th>
                                <th style="width: 70px;">Hours</th>
                                <th style="width: 70px;">Rate</th>
                                <th style="width: 80px;">Amount</th>
                                <th style="width: 100px;">Status</th>
                                <th style="width: 50px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="e in project.time_entries" :key="e.id">
                                <td>{{ e.logged_at }}</td>
                                <td><Link :href="`/activities/${e.task_id}`" class="tbl-link">{{ e.task_title }}</Link></td>
                                <td class="muted">{{ e.description || '—' }}</td>
                                <td>
                                    <span class="av-with-name">
                                        <span class="av xs" :style="{ background: e.user.avatar_colour ?? 'var(--text-tertiary)' }">{{ initials(e.user.name) }}</span>
                                        {{ e.user.name }}
                                    </span>
                                </td>
                                <td>{{ e.hours }}h</td>
                                <td>{{ e.is_billable ? `£${Number(e.effective_rate).toFixed(2)}` : '—' }}</td>
                                <td>{{ e.is_billable ? `£${Number(e.billable_amount).toFixed(2)}` : '—' }}</td>
                                <td>
                                    <span v-if="e.invoice_id" class="badge-pending">Invoiced</span>
                                    <span v-else-if="e.is_billable" class="badge-active">Billable</span>
                                    <span v-else class="muted small">Not billable</span>
                                </td>
                                <td>
                                    <button v-if="!e.invoice_id" type="button" class="icon-btn xs danger" @click="askDeleteEntry(e.id)">
                                        <IconX :size="13" stroke-width="2" />
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="project.time_entries.length === 0">
                                <td colspan="9" class="muted center">No time logged yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>

            <!-- ─── COST TAB ─── -->
            <div v-else-if="activeTab === 'cost'" class="project-cost">
                <!-- Overview cards -->
                <div class="cost-overview">
                    <div v-if="costs.budget" class="cost-stat">
                        <div class="cost-stat-label">Budget</div>
                        <div class="cost-stat-value">£{{ fmt(costs.budget) }}</div>
                    </div>
                    <div class="cost-stat">
                        <div class="cost-stat-label">Time costs</div>
                        <div class="cost-stat-value">£{{ fmt(costs.time_cost) }}</div>
                        <div class="cost-stat-sub">{{ costs.billable_hours }}h billable</div>
                    </div>
                    <div class="cost-stat">
                        <div class="cost-stat-label">Expenses</div>
                        <div class="cost-stat-value">£{{ fmt(costs.expense_total) }}</div>
                    </div>
                    <div class="cost-stat cost-stat-total" :class="{ 'over-budget': costs.over_budget }">
                        <div class="cost-stat-label">Total cost</div>
                        <div class="cost-stat-value">£{{ fmt(costs.total_cost) }}</div>
                        <div v-if="costs.remaining !== null" class="cost-stat-sub">
                            {{ costs.remaining >= 0
                                ? '£' + fmt(costs.remaining) + ' remaining'
                                : '£' + fmt(Math.abs(costs.remaining)) + ' over budget' }}
                        </div>
                    </div>
                </div>

                <!-- Budget progress bar -->
                <div v-if="costs.budget" class="budget-bar-wrap">
                    <div class="budget-bar">
                        <div
                            class="budget-bar-fill"
                            :class="{ 'budget-bar-over': costs.over_budget }"
                            :style="{ width: Math.min(100, costs.budget_used_percent) + '%' }"
                        ></div>
                    </div>
                    <span class="budget-bar-label">{{ costs.budget_used_percent }}% of budget used</span>
                </div>

                <!-- Time costs -->
                <div class="cost-section">
                    <div class="cost-section-head">
                        <h3>Time costs</h3>
                        <div class="cost-section-meta">
                            {{ costs.billable_hours }}h billable · £{{ fmt(costs.time_cost) }}
                            <span v-if="costs.non_billable_hours" class="text-tertiary">
                                · {{ costs.non_billable_hours }}h non-billable
                            </span>
                        </div>
                    </div>

                    <table v-if="costs.time_breakdown.length" class="cost-table">
                        <thead>
                            <tr>
                                <th>Task</th>
                                <th>Assignee</th>
                                <th class="text-right">Billable hrs</th>
                                <th class="text-right">Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in costs.time_breakdown" :key="row.task_id">
                                <td>{{ row.task_title }}</td>
                                <td class="text-secondary">{{ row.assignee ?? '—' }}</td>
                                <td class="text-right mono">{{ row.billable_hours }}h</td>
                                <td class="text-right mono">
                                    <span v-if="row.cost > 0">£{{ fmt(row.cost) }}</span>
                                    <span v-else class="text-tertiary">—</span>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="cost-table-total">
                                <td colspan="2">Total</td>
                                <td class="text-right mono">{{ costs.billable_hours }}h</td>
                                <td class="text-right mono">£{{ fmt(costs.time_cost) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                    <div v-else class="cost-empty">No time logged on this project yet.</div>

                    <div v-if="!costs.hourly_rate" class="cost-hint">
                        <IconInfoCircle :size="14" stroke-width="2" />
                        Set a project hourly rate to calculate time costs.
                        <button type="button" class="btn-link" @click="openEdit">Edit project →</button>
                    </div>
                </div>

                <!-- Expenses -->
                <div class="cost-section">
                    <div class="cost-section-head">
                        <h3>Expenses</h3>
                        <button type="button" class="btn btn-ghost btn-sm" @click="showAddExpense = true">
                            <IconPlus :size="14" stroke-width="2" /> Add expense
                        </button>
                    </div>

                    <table v-if="costs.expenses.length" class="cost-table">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th>Category</th>
                                <th>Supplier</th>
                                <th>Date</th>
                                <th class="text-right">Amount</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="exp in costs.expenses" :key="exp.id">
                                <td>{{ exp.description }}</td>
                                <td><span class="badge badge-inactive">{{ exp.category }}</span></td>
                                <td class="text-secondary">{{ exp.supplier_name ?? '—' }}</td>
                                <td class="text-tertiary text-sm">{{ exp.expense_date }}</td>
                                <td class="text-right mono">
                                    £{{ fmt(exp.total) }}
                                    <span v-if="exp.vat_amount > 0" class="vat-note">incl. VAT</span>
                                </td>
                                <td>
                                    <a href="/expenses" class="btn-icon" title="View in expenses">
                                        <IconExternalLink :size="14" stroke-width="2" />
                                    </a>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="cost-table-total">
                                <td colspan="4">Total</td>
                                <td class="text-right mono">£{{ fmt(costs.expense_total) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                    <div v-else class="cost-empty">
                        No expenses linked to this project.
                        <button type="button" class="btn-link" @click="showAddExpense = true">Add one →</button>
                    </div>
                </div>
            </div>

            <!-- ─── FILES TAB ─── -->
            <div v-else-if="activeTab === 'files'" class="project-files">
                <!-- Toolbar -->
                <div class="pf-toolbar">
                    <div class="pf-search">
                        <IconSearch :size="16" stroke-width="1.75" />
                        <input v-model="fileSearch" type="text" placeholder="Search files…">
                    </div>
                    <label class="btn btn-primary btn-sm pf-upload-label" :class="{ 'is-disabled': uploading }">
                        <IconUpload :size="14" stroke-width="1.75" />
                        Upload files
                        <input type="file" hidden multiple :disabled="uploading" @change="uploadFiles">
                    </label>
                </div>

                <!-- Infected warning -->
                <div v-if="file_summary.infected" class="file-infected-banner">
                    <IconAlertTriangle :size="16" stroke-width="1.75" />
                    {{ file_summary.infected }} malicious file(s) detected and blocked. Contact your administrator.
                </div>

                <!-- Upload drop zone -->
                <label
                    class="file-upload-zone"
                    :class="{ dragging: isDragging }"
                    @dragover.prevent="fileDragOver"
                    @dragleave.prevent="fileDragLeave"
                    @drop.prevent="fileDrop"
                >
                    <input type="file" hidden multiple :disabled="uploading" @change="uploadFiles">
                    <IconUpload :size="22" stroke-width="1.5" />
                    <div v-if="uploading" class="pf-uploading"><IconLoader2 :size="16" stroke-width="1.75" class="spin" /> Uploading…</div>
                    <div v-else>Drag &amp; drop files here, or click to browse</div>
                    <div class="file-upload-hint">
                        Max {{ file_max_mb }}MB per file. PDF, Word, Excel, images, ZIP.
                        <template v-if="! clamav_available"> · Malware scanning inactive on this server.</template>
                    </div>
                </label>

                <!-- Files table -->
                <section v-if="filteredFiles.length" class="table-card">
                    <table class="tbl">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Task</th>
                                <th>Size</th>
                                <th>Uploaded by</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="file in filteredFiles"
                                :key="file.id"
                                class="file-row"
                                :class="{
                                    'file-row--infected': file.scan_status === 'infected',
                                    'file-row--pending': file.scan_status === 'pending',
                                }"
                            >
                                <td>
                                    <div class="file-icon-name">
                                        <component :is="fileIcon(file.type_icon)" :size="20" stroke-width="1.75" class="file-type-icon" />
                                        <div>
                                            <a v-if="file.is_downloadable" :href="file.download_url" class="file-name" target="_blank" rel="noopener">{{ file.filename }}</a>
                                            <span v-else class="file-name file-name--blocked">{{ file.filename }}</span>
                                            <span v-if="file.description" class="file-desc">{{ file.description }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span v-if="file.task_title" class="file-task">
                                        <IconCheckbox :size="13" stroke-width="1.75" />
                                        {{ file.task_title }}
                                    </span>
                                    <span v-else class="muted">Project</span>
                                </td>
                                <td class="muted">{{ file.size }}</td>
                                <td class="muted">{{ file.uploaded_by }}</td>
                                <td class="muted" :title="file.uploaded_at_full">{{ file.uploaded_at }}</td>
                                <td>
                                    <span v-if="file.scan_status === 'pending'" class="badge badge-pending">
                                        <IconLoader2 :size="11" stroke-width="2" class="spin" /> Scanning
                                    </span>
                                    <span v-else-if="file.scan_status === 'infected'" class="badge badge-overdue">
                                        <IconVirus :size="11" stroke-width="2" /> Blocked
                                    </span>
                                    <span v-else-if="file.scan_status === 'clean'" class="badge badge-active">
                                        <IconCircleCheck :size="11" stroke-width="2" /> Clean
                                    </span>
                                    <span v-else class="badge badge-inactive">{{ file.scan_status }}</span>
                                </td>
                                <td>
                                    <button
                                        v-if="file.source === 'project'"
                                        type="button"
                                        class="icon-btn pf-delete"
                                        title="Delete file"
                                        @click="askDeleteFile(file.id)"
                                    >
                                        <IconTrash :size="15" stroke-width="1.75" />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <!-- Empty state -->
                <div v-else class="file-empty">
                    <IconFiles :size="36" stroke-width="1.5" />
                    <p>{{ fileSearch ? 'No files match your search.' : 'No files yet.' }}</p>
                    <p v-if="! fileSearch" class="muted">Upload files to this project or attach them to individual tasks.</p>
                </div>
            </div>

            <!-- ─── ACTIVITY TAB ─── -->
            <section v-else-if="activeTab === 'activity'" class="card">
                <div class="card-head"><h3>Activity log</h3></div>
                <div class="card-body activity-feed">
                    <div v-for="a in activity" :key="a.id" class="activity-row">
                        <IconHistory :size="14" stroke-width="2" />
                        <span class="muted small">{{ a.time_ago }}</span>
                        <span><strong>{{ actionLabel(a.action) }}</strong></span>
                    </div>
                    <div v-if="activity.length === 0" class="muted center">No activity yet.</div>
                </div>
            </section>
        </div>

        <!-- ─── Edit project slide-over ─── -->
        <Teleport to="body">
            <div v-if="showEdit" class="slide-over-overlay" v-overlay-dismiss="editGuard.attemptClose">
                <div class="slide-over" style="width: 560px;">
                    <div class="slide-over-head">
                        <h2>Edit project</h2>
                        <button type="button" class="icon-btn" @click="editGuard.attemptClose">
                            <IconX :size="18" stroke-width="2" />
                        </button>
                    </div>
                    <form @submit.prevent="submitEdit" class="slide-over-body">
                        <div class="form-section">
                            <label class="form-label">Title</label>
                            <input v-model="editForm.title" type="text" class="form-input lg" />
                        </div>
                        <div class="form-section">
                            <label class="form-label">Description</label>
                            <textarea v-model="editForm.description" class="form-input" rows="3"></textarea>
                        </div>
                        <div class="form-row-2">
                            <div class="form-section">
                                <label class="form-label">Status</label>
                                <select v-model="editForm.status" class="form-input">
                                    <option value="planning">Planning</option>
                                    <option value="active">Active</option>
                                    <option value="on_hold">On hold</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                            <div class="form-section">
                                <label class="form-label">Priority</label>
                                <select v-model="editForm.priority" class="form-input">
                                    <option value="low">Low</option>
                                    <option value="medium">Medium</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-row-2">
                            <div class="form-section">
                                <label class="form-label">Due date</label>
                                <input v-model="editForm.due_date" type="date" class="form-input" />
                            </div>
                            <div class="form-section">
                                <label class="form-label">Hourly rate (£)</label>
                                <input v-model="editForm.hourly_rate" type="number" min="0" step="0.01" class="form-input" />
                            </div>
                        </div>
                        <div class="form-section">
                            <label class="form-label">Project colour</label>
                            <input v-model="editForm.colour" type="text" maxlength="7" class="form-input sm" />
                        </div>
                        <div class="form-section">
                            <label class="form-label">Project lead</label>
                            <select v-model="editForm.project_lead" class="form-input">
                                <option :value="null">No lead</option>
                                <option v-for="s in staff" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </select>
                        </div>
                        <div class="form-section">
                            <label class="form-label">Customer</label>
                            <select v-model="editForm.customer_id" class="form-input">
                                <option :value="null">— Unassigned —</option>
                                <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                            <div v-if="editForm.errors.customer_id" class="err">{{ editForm.errors.customer_id }}</div>
                        </div>
                    </form>
                    <div class="slide-over-foot">
                        <button type="button" class="btn btn-ghost" @click="editGuard.attemptClose">Cancel</button>
                        <button type="button" class="btn btn-primary" :disabled="editForm.processing" @click="submitEdit">Save</button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- ─── Milestone slide-over ─── -->
        <Teleport to="body">
            <div v-if="showMilestone" class="slide-over-overlay" v-overlay-dismiss="milestoneGuard.attemptClose">
                <div class="slide-over" style="width: 460px;">
                    <div class="slide-over-head">
                        <h2>{{ milestoneForm.id ? 'Edit milestone' : 'Add milestone' }}</h2>
                        <button type="button" class="icon-btn" @click="milestoneGuard.attemptClose">
                            <IconX :size="18" stroke-width="2" />
                        </button>
                    </div>
                    <form @submit.prevent="submitMilestone" class="slide-over-body">
                        <div class="form-section">
                            <label class="form-label">Title</label>
                            <input v-model="milestoneForm.title" type="text" class="form-input" required maxlength="255" />
                        </div>
                        <div class="form-section">
                            <label class="form-label">Description</label>
                            <textarea v-model="milestoneForm.description" class="form-input" rows="2"></textarea>
                        </div>
                        <div class="form-row-2">
                            <div class="form-section">
                                <label class="form-label">Due date</label>
                                <input v-model="milestoneForm.due_date" type="date" class="form-input" />
                            </div>
                            <div v-if="milestoneForm.id" class="form-section">
                                <label class="form-label">Status</label>
                                <select v-model="milestoneForm.status" class="form-input">
                                    <option value="pending">Pending</option>
                                    <option value="in_progress">In progress</option>
                                    <option value="completed">Completed</option>
                                </select>
                            </div>
                        </div>
                    </form>
                    <div class="slide-over-foot">
                        <button v-if="milestoneForm.id" type="button" class="btn btn-ghost danger" @click="deleteMilestone(milestoneForm.id); showMilestone = false">Delete</button>
                        <button type="button" class="btn btn-ghost" @click="milestoneGuard.attemptClose">Cancel</button>
                        <button type="button" class="btn btn-primary" :disabled="milestoneForm.processing" @click="submitMilestone">{{ milestoneForm.id ? 'Save' : 'Add' }}</button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- ─── Log time slide-over ─── -->
        <Teleport to="body">
            <div v-if="showLogTime" class="slide-over-overlay" v-overlay-dismiss="logTimeGuard.attemptClose">
                <div class="slide-over" style="width: 480px;">
                    <div class="slide-over-head">
                        <h2>Log time</h2>
                        <button type="button" class="icon-btn" @click="logTimeGuard.attemptClose">
                            <IconX :size="18" stroke-width="2" />
                        </button>
                    </div>
                    <form @submit.prevent="submitLogTime" class="slide-over-body">
                        <div class="form-section">
                            <label class="form-label">Task</label>
                            <select v-model="logForm.task_id" class="form-input">
                                <option :value="null">Select a task…</option>
                                <option v-for="t in project.tasks" :key="t.id" :value="t.id">{{ t.title }}</option>
                            </select>
                        </div>
                        <div class="form-section">
                            <label class="form-label">Date</label>
                            <input v-model="logForm.logged_at" type="date" class="form-input" />
                        </div>
                        <div class="form-section">
                            <label class="form-label">Time spent</label>
                            <div class="hm-row">
                                <input v-model="logForm.hours" type="number" min="0" max="24" class="form-input sm" />
                                <span>h</span>
                                <input v-model="logForm.minutes" type="number" min="0" max="59" class="form-input sm" />
                                <span>m</span>
                            </div>
                        </div>
                        <div class="form-section">
                            <label class="form-label">Description (optional)</label>
                            <input v-model="logForm.description" type="text" class="form-input" />
                        </div>
                        <div class="form-section">
                            <div class="set-row">
                                <div>
                                    <div class="nm">Billable</div>
                                    <div class="sb">Counts toward invoiceable hours.</div>
                                </div>
                                <button
                                    type="button"
                                    class="toggle"
                                    :class="{ on: logForm.is_billable }"
                                    aria-label="Toggle billable"
                                    @click="logForm.is_billable = !logForm.is_billable"
                                />
                            </div>
                        </div>
                        <div v-if="logForm.is_billable" class="form-section">
                            <label class="form-label">Hourly rate override (£)</label>
                            <input v-model="logForm.hourly_rate" type="number" min="0" step="0.01" class="form-input" :placeholder="`Project rate: £${project.hourly_rate ?? '—'}`" />
                        </div>
                    </form>
                    <div class="slide-over-foot">
                        <button type="button" class="btn btn-ghost" @click="logTimeGuard.attemptClose">Cancel</button>
                        <button type="button" class="btn btn-primary" :disabled="logForm.processing || !logForm.task_id" @click="submitLogTime">Log time</button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- ─── Add expense slide-over (Cost tab) ─── -->
        <Teleport to="body">
            <div v-if="showAddExpense" class="slide-over-overlay" v-overlay-dismiss="expenseGuard.attemptClose">
                <div class="slide-over" style="width: 480px;">
                    <div class="slide-over-head">
                        <h2>Add expense</h2>
                        <button type="button" class="icon-btn" @click="expenseGuard.attemptClose">
                            <IconX :size="18" stroke-width="2" />
                        </button>
                    </div>
                    <form @submit.prevent="submitExpense" class="slide-over-body">
                        <div class="form-section">
                            <label class="form-label">Description</label>
                            <input v-model="expenseForm.description" type="text" maxlength="255" class="form-input" required />
                            <div v-if="expenseForm.errors.description" class="err">{{ expenseForm.errors.description }}</div>
                        </div>
                        <div class="form-section">
                            <label class="form-label">Category</label>
                            <select v-model="expenseForm.category" class="form-input">
                                <option v-for="c in EXPENSE_CATEGORIES" :key="c" :value="c">{{ c }}</option>
                            </select>
                        </div>
                        <div class="form-section">
                            <label class="form-label">Supplier (optional)</label>
                            <select v-model="expenseForm.supplier_id" class="form-input" @change="onExpenseSupplierChange">
                                <option value="">None</option>
                                <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </select>
                        </div>
                        <div class="form-row-2">
                            <div class="form-section">
                                <label class="form-label">Amount (£)</label>
                                <input v-model="expenseForm.amount" type="number" min="0" step="0.01" class="form-input" required />
                                <div v-if="expenseForm.errors.amount" class="err">{{ expenseForm.errors.amount }}</div>
                            </div>
                            <div class="form-section">
                                <label class="form-label">VAT rate (%)</label>
                                <input v-model="expenseForm.vat_rate" type="number" min="0" max="100" step="0.01" class="form-input" />
                            </div>
                        </div>
                        <div class="form-section">
                            <label class="form-label">Date</label>
                            <input v-model="expenseForm.expense_date" type="date" class="form-input" required />
                        </div>
                        <div class="form-section">
                            <label class="form-label">Receipt (optional)</label>
                            <input type="file" accept=".pdf,.jpg,.jpeg,.png" class="form-input" @change="onExpenseReceipt" />
                            <div v-if="expenseForm.errors.receipt" class="err">{{ expenseForm.errors.receipt }}</div>
                        </div>
                    </form>
                    <div class="slide-over-foot">
                        <button type="button" class="btn btn-ghost" @click="expenseGuard.attemptClose">Cancel</button>
                        <button
                            type="button"
                            class="btn btn-primary"
                            :disabled="expenseForm.processing || !expenseForm.description.trim() || !expenseForm.amount"
                            @click="submitExpense"
                        >Add expense</button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- ─── Invoice generation modal ─── -->
        <Teleport to="body">
            <div v-if="showInvoiceModal" class="slide-over-overlay" v-overlay-dismiss="invoiceGuard.attemptClose">
                <div class="slide-over" style="width: 560px;">
                    <div class="slide-over-head">
                        <h2>Generate invoice from time</h2>
                        <button type="button" class="icon-btn" @click="invoiceGuard.attemptClose">
                            <IconX :size="18" stroke-width="2" />
                        </button>
                    </div>
                    <form @submit.prevent="submitInvoice" class="slide-over-body">
                        <div class="form-section">
                            <label class="form-label">Time entries</label>
                            <div class="invoice-entries">
                                <label v-for="e in unbilledEntries" :key="e.id" class="invoice-entry-row">
                                    <input type="checkbox" :checked="invoiceForm.entry_ids.includes(e.id)" @change="toggleInvoiceEntry(e.id)" />
                                    <span>{{ e.logged_at }} · {{ e.user.name }} · {{ e.hours }}h</span>
                                    <strong>£{{ Number(e.billable_amount).toFixed(2) }}</strong>
                                </label>
                            </div>
                            <p class="muted small">Selected: {{ invoiceForm.entry_ids.length }} of {{ unbilledEntries.length }} entries.</p>
                        </div>
                        <div class="form-row-2">
                            <div class="form-section">
                                <label class="form-label">Hourly rate (£)</label>
                                <input v-model="invoiceForm.hourly_rate" type="number" min="0" step="0.01" class="form-input" />
                                <p class="muted small">Override the project's default rate for this invoice.</p>
                            </div>
                            <div class="form-section">
                                <label class="form-label">Billing entity</label>
                                <select v-model="invoiceForm.billing_entity_id" class="form-input">
                                    <option v-for="b in billing_entities" :key="b.id" :value="b.id">{{ b.name }}</option>
                                </select>
                            </div>
                        </div>
                    </form>
                    <div class="slide-over-foot">
                        <button type="button" class="btn btn-ghost" @click="invoiceGuard.attemptClose">Cancel</button>
                        <button type="button" class="btn btn-primary" :disabled="invoiceForm.processing || invoiceForm.entry_ids.length === 0 || !invoiceForm.billing_entity_id" @click="submitInvoice">
                            Generate draft invoice
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- ─── Confirm archive ─── -->
        <ConfirmModal
            v-model:show="showArchiveConfirm"
            variant="danger"
            title="Archive project?"
            message="The project will be hidden from the list but its tasks and time entries are preserved. You can restore it later from the database."
            confirm-label="Archive project"
            @confirm="confirmArchive"
        />

        <!-- ─── Confirm delete file ─── -->
        <ConfirmModal
            v-model:show="showDeleteFileModal"
            variant="danger"
            title="Delete file?"
            message="This file will be permanently removed from the project and from storage. This cannot be undone."
            confirm-label="Delete file"
            @confirm="performDeleteFile"
        />

        <!-- ─── Confirm delete time entry ─── -->
        <ConfirmModal
            v-model:show="showDeleteEntry"
            variant="danger"
            title="Delete time entry?"
            message="This time entry will be permanently removed. If it has been invoiced, you'll need to void the invoice first."
            confirm-label="Delete entry"
            @confirm="confirmDeleteEntry"
        />

        <!-- ─── Blocked-reason modal (replaces window.prompt) ─── -->
        <Teleport to="body">
            <div v-if="showBlockedModal" class="slide-over-overlay" v-overlay-dismiss="blockedGuard.attemptClose">
                <div class="slide-over" style="width: 460px;">
                    <div class="slide-over-head">
                        <h2>What's blocking this task?</h2>
                        <button type="button" class="icon-btn" @click="blockedGuard.attemptClose">
                            <IconX :size="18" stroke-width="2" />
                        </button>
                    </div>
                    <form @submit.prevent="confirmBlocked" class="slide-over-body">
                        <div class="form-section">
                            <label class="form-label">Reason</label>
                            <textarea
                                v-model="blockedReason"
                                class="form-input"
                                rows="3"
                                maxlength="500"
                                placeholder="Waiting on customer approval / missing assets / etc."
                                autofocus
                            ></textarea>
                            <p class="muted small">Visible on the MyWork page so the team can help unblock.</p>
                        </div>
                    </form>
                    <div class="slide-over-foot">
                        <button type="button" class="btn btn-ghost" @click="blockedGuard.attemptClose">Cancel</button>
                        <button type="button" class="btn btn-primary" :disabled="!blockedReason.trim()" @click="confirmBlocked">Mark blocked</button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Edit task slide-over -->
        <Teleport to="body">
            <div v-if="showEditTask" class="slide-over-overlay" v-overlay-dismiss="() => (showEditTask = false)">
                <div class="slide-over" style="width: 480px;">
                    <div class="slide-over-head">
                        <h2>Edit task</h2>
                        <button type="button" class="icon-btn" @click="showEditTask = false">
                            <IconX :size="18" stroke-width="2" />
                        </button>
                    </div>
                    <form class="slide-over-body" @submit.prevent="submitEditTask">
                        <div class="form-section">
                            <label class="form-label">Title</label>
                            <input v-model="editTask.title" type="text" class="form-input" maxlength="500" required />
                        </div>
                        <div class="form-section">
                            <label class="form-label">Type</label>
                            <select v-model="editTask.type" class="form-input">
                                <option value="task">Task</option>
                                <option value="call">Call</option>
                                <option value="email">Email</option>
                                <option value="meeting">Meeting</option>
                                <option value="note">Note</option>
                            </select>
                        </div>
                        <div class="form-section">
                            <label class="form-label">Priority</label>
                            <select v-model="editTask.priority" class="form-input">
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                            </select>
                        </div>
                        <div class="form-section">
                            <label class="form-label">Assigned to</label>
                            <select v-model.number="editTask.assigned_to" class="form-input">
                                <option :value="null">Unassigned</option>
                                <option v-for="u in staff" :key="u.id" :value="u.id">{{ u.name }}</option>
                            </select>
                        </div>
                        <div class="form-section">
                            <label class="form-label">Milestone</label>
                            <select v-model.number="editTask.milestone_id" class="form-input">
                                <option :value="null">Unassigned</option>
                                <option v-for="m in project.milestones" :key="m.id" :value="m.id">{{ m.title }}</option>
                            </select>
                        </div>
                        <div class="form-section">
                            <label class="form-label">Due date</label>
                            <input v-model="editTask.due_at" type="date" class="form-input" />
                        </div>
                        <div class="form-section">
                            <label class="form-label">Estimated hours</label>
                            <input v-model.number="editTask.estimated_hours" type="number" min="0" step="0.25" class="form-input" />
                        </div>
                        <div class="form-section">
                            <label class="form-label">Description</label>
                            <textarea v-model="editTask.description" class="form-input" rows="3" />
                        </div>
                    </form>
                    <div class="slide-over-foot">
                        <button type="button" class="btn btn-ghost" @click="showEditTask = false">Cancel</button>
                        <button type="button" class="btn btn-primary" @click="submitEditTask">Save changes</button>
                    </div>
                </div>
            </div>
        </Teleport>

        <ConfirmModal
            v-model:show="showDeleteTask"
            variant="danger"
            :title="`Delete ${taskToDelete?.title ?? 'task'}?`"
            message="The task and any attached time entries / notes will be removed. This cannot be undone."
            confirm-label="Delete"
            @confirm="confirmDeleteTask"
        />

        <!-- Unsaved-changes discard confirmations (Issue B) -->
        <ConfirmModal
            :show="editGuard.confirmingDiscard"
            variant="warning"
            title="Discard changes?"
            message="You have unsaved changes. Discard them?"
            confirm-label="Discard"
            cancel-label="Keep editing"
            @confirm="editGuard.confirmDiscard"
            @cancel="editGuard.cancelDiscard"
        />
        <ConfirmModal
            :show="milestoneGuard.confirmingDiscard"
            variant="warning"
            title="Discard changes?"
            message="You have unsaved changes. Discard them?"
            confirm-label="Discard"
            cancel-label="Keep editing"
            @confirm="milestoneGuard.confirmDiscard"
            @cancel="milestoneGuard.cancelDiscard"
        />
        <ConfirmModal
            :show="logTimeGuard.confirmingDiscard"
            variant="warning"
            title="Discard changes?"
            message="You have unsaved changes. Discard them?"
            confirm-label="Discard"
            cancel-label="Keep editing"
            @confirm="logTimeGuard.confirmDiscard"
            @cancel="logTimeGuard.cancelDiscard"
        />
        <ConfirmModal
            :show="expenseGuard.confirmingDiscard"
            variant="warning"
            title="Discard changes?"
            message="You have unsaved changes. Discard them?"
            confirm-label="Discard"
            cancel-label="Keep editing"
            @confirm="expenseGuard.confirmDiscard"
            @cancel="expenseGuard.cancelDiscard"
        />
        <ConfirmModal
            :show="invoiceGuard.confirmingDiscard"
            variant="warning"
            title="Discard changes?"
            message="You have unsaved changes. Discard them?"
            confirm-label="Discard"
            cancel-label="Keep editing"
            @confirm="invoiceGuard.confirmDiscard"
            @cancel="invoiceGuard.cancelDiscard"
        />
        <ConfirmModal
            :show="blockedGuard.confirmingDiscard"
            variant="warning"
            title="Discard changes?"
            message="You have unsaved changes. Discard them?"
            confirm-label="Discard"
            cancel-label="Keep editing"
            @confirm="blockedGuard.confirmDiscard"
            @cancel="blockedGuard.cancelDiscard"
        />
    </InternalLayout>
</template>
