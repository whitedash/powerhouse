<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
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
    IconPlus,
    IconX,
    IconSearch,
    IconFlag,
    IconAlertTriangle,
    IconChevronDown,
    IconChevronLeft,
    IconChevronRight,
    IconDots,
    IconCheck,
    IconAlertCircle,
    IconHeadset,
    IconClock,
    IconUserCheck,
    IconCircleDashed,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';

const props = defineProps({
    tickets: { type: Object, required: true },
    summary: { type: Object, required: true },
    staff: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    priorities: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const page = usePage();
const breadcrumbs = [{ label: 'Support' }];

/* ─── Labels ─── */
const STATUS_LABELS = {
    open: 'Open',
    in_progress: 'In progress',
    awaiting_customer: 'Awaiting customer',
    resolved: 'Resolved',
    closed: 'Closed',
};
const PRIORITY_LABELS = {
    urgent: 'Urgent', high: 'High', medium: 'Medium', low: 'Low',
};

function statusBadgeClass(s) {
    return s === 'open' ? 'badge-overdue'
        : s === 'in_progress' ? 'badge-pending'
        : s === 'awaiting_customer' ? 'badge-info'
        : s === 'resolved' ? 'badge-active'
        : 'badge-inactive';
}
function priorityBadgeClass(p) {
    return p === 'urgent' ? 'badge-overdue'
        : p === 'high' ? 'badge-pending'
        : p === 'medium' ? 'badge-info'
        : 'badge-inactive';
}
function priorityDotClass(p) {
    return p === 'urgent' ? 'red'
        : p === 'high' ? 'amber'
        : p === 'medium' ? 'blue'
        : 'grey';
}

/* ─── Avatar ─── */
function initials(name) {
    const parts = String(name || '').trim().split(/\s+/);
    return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || '?';
}
function avatarColour(id) {
    const palette = ['#0D9488', '#F59E0B', '#3B82F6', '#10B981', '#7C3AED', '#EF4444', '#06B6D4', '#6366F1'];
    return palette[Number(id) % palette.length];
}

/* ─── Dates ─── */
function timeAgo(iso) {
    if (! iso) return '—';
    const diffMs = new Date() - new Date(iso);
    const sec = Math.floor(diffMs / 1000);
    if (sec < 60) return 'just now';
    const min = Math.floor(sec / 60);
    if (min < 60) return `${min}m ago`;
    const hr = Math.floor(min / 60);
    if (hr < 24) return `${hr}h ago`;
    const day = Math.floor(hr / 24);
    if (day < 30) return `${day}d ago`;
    const mo = Math.floor(day / 30);
    if (mo < 12) return `${mo}mo ago`;
    return `${Math.floor(day / 365)}y ago`;
}

/*
 * Always compute SLA from the raw sla_breach_at ISO. The server-side
 * is_breached / hours_until_breach are useful flags but Carbon's
 * diffInHours returns a float in Carbon 3 — without rounding the cell
 * was rendering "-3.452347234" days. Recomputing client-side keeps the
 * number nice and lets us bucket by hour vs day automatically.
 */
function slaCellLabel(ticket) {
    if (! ticket.sla_breach_at) return { label: '—', cls: 'muted' };

    const breachMs = new Date(ticket.sla_breach_at).getTime();
    const diffMs = breachMs - Date.now();
    const diffHours = Math.round(diffMs / 3600000);

    if (diffMs < 0) {
        const hoursAgo = Math.abs(diffHours);
        if (hoursAgo < 24) return { label: `Breached ${hoursAgo}h ago`, cls: 'breached' };
        const daysAgo = Math.round(hoursAgo / 24);

        return { label: `Breached ${daysAgo}d ago`, cls: 'breached' };
    }

    if (diffHours <= 4) return { label: `${diffHours}h left`, cls: 'urgent' };
    if (diffHours <= 24) return { label: `${diffHours}h left`, cls: 'normal' };
    const days = Math.round(diffHours / 24);

    return { label: `${days}d left`, cls: 'normal' };
}

/* ─── Filters ─── */
const searchInput = ref(props.filters.search ?? '');
const statusFilter = ref(props.filters.status ?? '');
const priorityFilter = ref(props.filters.priority ?? '');

let searchTimeout = null;
watch(searchInput, (v) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => applyFilters({ search: v || null }), 300);
});

function applyFilters(patch = {}) {
    router.get('/support', {
        search: searchInput.value || null,
        status: statusFilter.value || null,
        priority: priorityFilter.value || null,
        ...patch,
    }, { preserveScroll: true, preserveState: true, replace: true });
}
function setStatus(s) {
    statusFilter.value = statusFilter.value === s ? '' : s;
    applyFilters();
}
function setPriority(p) {
    priorityFilter.value = priorityFilter.value === p ? '' : p;
    applyFilters();
}

/* ─── New ticket slide-over ─── */
const showNewTicket = ref(false);
const newForm = useForm({
    customer_id: null,
    subject: '',
    message: '',
    priority: 'medium',
    assigned_to: null,
});
const customerQuery = ref('');
const customerListOpen = ref(false);

const filteredCustomers = computed(() => {
    const q = customerQuery.value.trim().toLowerCase();
    if (! q) return props.customers.slice(0, 8);
    return props.customers
        .filter((c) => c.name.toLowerCase().includes(q) || (c.city ?? '').toLowerCase().includes(q))
        .slice(0, 8);
});
const selectedCustomer = computed(() => props.customers.find((c) => c.id === newForm.customer_id) ?? null);

function pickCustomer(c) {
    newForm.customer_id = c.id;
    customerQuery.value = c.name;
    customerListOpen.value = false;
}
function clearCustomer() {
    newForm.customer_id = null;
    customerQuery.value = '';
    customerListOpen.value = true;
}
function openNewTicket() {
    newForm.reset();
    newForm.clearErrors();
    customerQuery.value = '';
    customerListOpen.value = true;
    showNewTicket.value = true;
}
function submitNewTicket() {
    newForm.post('/support', {
        preserveScroll: true,
        onSuccess: () => { showNewTicket.value = false; },
    });
}

/* ─── Quick actions ─── */
function assignToMe(ticket) {
    const me = page.props.auth?.user?.id;
    if (! me) return;
    router.post(`/support/${ticket.id}/status`, {
        status: ticket.status,
        assigned_to: me,
    }, { preserveScroll: true });
}
function changeStatusTo(ticket, status) {
    router.post(`/support/${ticket.id}/status`, {
        status,
        assigned_to: ticket.assigned_to_id ?? null,
    }, { preserveScroll: true });
}

/* ─── Pagination URLs ─── */
const prevUrl = computed(() => props.tickets.prev_page_url);
const nextUrl = computed(() => props.tickets.next_page_url);
</script>

<template>
    <Head title="Support" />

    <InternalLayout title="Support" :breadcrumbs="breadcrumbs" active-nav="support">
        <template #topbar-actions>
            <button type="button" class="btn btn-primary" @click="openNewTicket">
                <IconPlus :size="15" stroke-width="1.75" />
                New ticket
            </button>
        </template>

        <div class="support-list">
            <!-- Flash banners -->
            <div
                v-if="page.props.flash?.success"
                style="margin-bottom: 14px; padding: 10px 14px; background: var(--success-bg); color: #047857; border: 1px solid #A7F3D0; border-radius: var(--radius-md); font: 500 13px/1.4 'Inter', sans-serif; display: flex; align-items: center; gap: 8px;"
            >
                <IconCheck :size="16" stroke-width="2" />{{ page.props.flash.success }}
            </div>
            <div
                v-if="page.props.flash?.error"
                style="margin-bottom: 14px; padding: 10px 14px; background: var(--danger-bg); color: var(--danger); border: 1px solid #FECACA; border-radius: var(--radius-md); font: 500 13px/1.4 'Inter', sans-serif; display: flex; align-items: center; gap: 8px;"
            >
                <IconAlertCircle :size="16" stroke-width="2" />{{ page.props.flash.error }}
            </div>

            <!-- Summary strip -->
            <div class="summary-strip">
                <div class="stat-pill"><span class="d red" /><strong>{{ summary.sla_breached }}</strong><span class="lbl">SLA breached</span></div>
                <div class="stat-pill"><span class="d amber" /><strong>{{ summary.open }}</strong><span class="lbl">Open</span></div>
                <div class="stat-pill"><span class="d blue" /><strong>{{ summary.in_progress }}</strong><span class="lbl">In progress</span></div>
                <div class="stat-pill"><span class="d grey" /><strong>{{ summary.awaiting_customer }}</strong><span class="lbl">Awaiting customer</span></div>
                <div class="stat-pill"><span class="d green" /><strong>{{ summary.resolved_today }}</strong><span class="lbl">Resolved today</span></div>
            </div>

            <!-- Filter bar -->
            <div class="filter-bar">
                <div class="field-search">
                    <span class="search-icon"><IconSearch :size="16" stroke-width="1.75" /></span>
                    <input v-model="searchInput" type="text" placeholder="Search subject or customer…">
                </div>

                <Menu as="div" class="dd-menu">
                    <MenuButton class="dd-btn">
                        <IconFlag :size="14" stroke-width="1.75" />
                        {{ statusFilter ? STATUS_LABELS[statusFilter] : 'Status' }}
                        <IconChevronDown :size="14" stroke-width="1.75" class="ch" />
                    </MenuButton>
                    <MenuItems class="dd-popover">
                        <MenuItem v-for="s in statuses" :key="s" v-slot="{ active }">
                            <button type="button" :class="['dd-option', { active, current: statusFilter === s }]" @click="setStatus(s)">{{ STATUS_LABELS[s] }}</button>
                        </MenuItem>
                    </MenuItems>
                </Menu>

                <Menu as="div" class="dd-menu">
                    <MenuButton class="dd-btn">
                        <IconAlertTriangle :size="14" stroke-width="1.75" />
                        {{ priorityFilter ? PRIORITY_LABELS[priorityFilter] : 'Priority' }}
                        <IconChevronDown :size="14" stroke-width="1.75" class="ch" />
                    </MenuButton>
                    <MenuItems class="dd-popover">
                        <MenuItem v-for="p in priorities" :key="p" v-slot="{ active }">
                            <button type="button" :class="['dd-option', { active, current: priorityFilter === p }]" @click="setPriority(p)">{{ PRIORITY_LABELS[p] }}</button>
                        </MenuItem>
                    </MenuItems>
                </Menu>
            </div>

            <!-- Tickets table -->
            <div class="table-card" style="margin-top: 12px;">
                <table class="tbl">
                    <colgroup>
                        <col>
                        <col style="width: 110px;">
                        <col style="width: 150px;">
                        <col style="width: 160px;">
                        <col style="width: 130px;">
                        <col style="width: 130px;">
                        <col style="width: 48px;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Ticket</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>SLA</th>
                            <th>Assigned</th>
                            <th>Last activity</th>
                            <th />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="! tickets.data.length">
                            <td colspan="7" style="text-align: center; padding: 40px 16px; color: var(--text-secondary); font: 400 13px/1.4 'Inter', sans-serif;">
                                <IconHeadset :size="32" stroke-width="1.5" style="color: var(--text-tertiary); margin-bottom: 8px;" />
                                <div>No tickets found</div>
                            </td>
                        </tr>
                        <tr v-for="t in tickets.data" :key="t.id">
                            <td>
                                <Link :href="`/support/${t.id}`" class="ticket-cell">
                                    <span :class="['pri-dot', priorityDotClass(t.priority)]" />
                                    <div>
                                        <div class="ticket-subject">{{ t.subject }}</div>
                                        <div class="ticket-meta">
                                            <span class="ticket-id">#{{ t.id }}</span>
                                            <span v-if="t.customer_name"> · {{ t.customer_name }}</span>
                                            <span> · {{ t.message_count }} message{{ t.message_count === 1 ? '' : 's' }}</span>
                                        </div>
                                    </div>
                                </Link>
                            </td>
                            <td><span :class="['badge', 'badge-sm', priorityBadgeClass(t.priority)]">{{ PRIORITY_LABELS[t.priority] }}</span></td>
                            <td><span :class="['badge', 'badge-sm', statusBadgeClass(t.status)]">{{ STATUS_LABELS[t.status] }}</span></td>
                            <td>
                                <span :class="['sla-cell', slaCellLabel(t).cls]">
                                    <span v-if="slaCellLabel(t).cls === 'urgent'" class="sla-pulse" />
                                    {{ slaCellLabel(t).label }}
                                </span>
                            </td>
                            <td>
                                <template v-if="t.assigned_to_name">
                                    <div class="assigned-cell">
                                        <div class="avatar avatar-xs" :style="{ background: avatarColour(t.assigned_to_id), color: '#fff' }">{{ initials(t.assigned_to_name) }}</div>
                                        <span>{{ t.assigned_to_name }}</span>
                                    </div>
                                </template>
                                <span v-else style="font: 400 12px/1.3 'Inter', sans-serif; color: var(--text-tertiary); font-style: italic;">Unassigned</span>
                            </td>
                            <td>
                                <span style="font: 400 12px/1.3 'Inter', sans-serif; color: var(--text-secondary);">{{ timeAgo(t.last_reply_at) }}</span>
                            </td>
                            <td>
                                <Menu as="div" class="dd-menu">
                                    <MenuButton class="icon-btn" aria-label="Actions">
                                        <IconDots :size="16" stroke-width="1.75" />
                                    </MenuButton>
                                    <MenuItems class="dd-popover right-align">
                                        <MenuItem v-slot="{ active }">
                                            <Link :class="['dd-option', { active }]" :href="`/support/${t.id}`">View ticket</Link>
                                        </MenuItem>
                                        <MenuItem v-slot="{ active }">
                                            <button type="button" :class="['dd-option', { active }]" @click="assignToMe(t)">Assign to me</button>
                                        </MenuItem>
                                        <div style="height: 1px; background: var(--border-soft); margin: 4px 0;" />
                                        <MenuItem v-if="t.status !== 'resolved'" v-slot="{ active }">
                                            <button type="button" :class="['dd-option', { active }]" @click="changeStatusTo(t, 'resolved')">Mark resolved</button>
                                        </MenuItem>
                                        <MenuItem v-if="t.status !== 'closed'" v-slot="{ active }">
                                            <button type="button" :class="['dd-option', { active }]" style="color: var(--danger);" @click="changeStatusTo(t, 'closed')">Close ticket</button>
                                        </MenuItem>
                                    </MenuItems>
                                </Menu>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="tbl-foot">
                    <div class="info">
                        Showing <strong>{{ tickets.from || 0 }} – {{ tickets.to || 0 }}</strong> of <strong>{{ tickets.total }}</strong> tickets
                    </div>
                    <div class="right">
                        <Link v-if="prevUrl" :href="prevUrl" class="pg-btn" preserve-scroll>
                            <IconChevronLeft :size="14" stroke-width="1.75" />
                            Previous
                        </Link>
                        <button v-else type="button" class="pg-btn" disabled><IconChevronLeft :size="14" stroke-width="1.75" />Previous</button>
                        <Link v-if="nextUrl" :href="nextUrl" class="pg-btn" preserve-scroll>
                            Next
                            <IconChevronRight :size="14" stroke-width="1.75" />
                        </Link>
                        <button v-else type="button" class="pg-btn" disabled>Next<IconChevronRight :size="14" stroke-width="1.75" /></button>
                    </div>
                </div>
            </div>
        </div>

        <!-- New ticket slide-over -->
        <TransitionRoot as="template" :show="showNewTicket">
            <Dialog as="div" class="slide-over-dialog" @close="showNewTicket = false">
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
                        <form class="slide-over-form" @submit.prevent="submitNewTicket">
                            <header class="slide-over-header">
                                <h2>New support ticket</h2>
                                <button type="button" class="icon-btn" aria-label="Close" @click="showNewTicket = false">
                                    <IconX :size="18" stroke-width="1.75" />
                                </button>
                            </header>
                            <div class="slide-over-body">
                                <div class="form-section">
                                    <h3>Customer</h3>
                                    <div class="form-row single">
                                        <div class="form-field" style="position: relative;">
                                            <label>Customer<span class="req">*</span></label>
                                            <div v-if="selectedCustomer" style="display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: var(--neutral-bg); border-radius: var(--radius-md);">
                                                <div class="avatar avatar-xs" :style="{ background: avatarColour(selectedCustomer.id), color: '#fff' }">{{ initials(selectedCustomer.name) }}</div>
                                                <div>
                                                    <div style="font: 600 13px/1.3 'Inter', sans-serif;">{{ selectedCustomer.name }}</div>
                                                    <div v-if="selectedCustomer.city" style="font: 400 11.5px/1.3 'Inter', sans-serif; color: var(--text-secondary);">{{ selectedCustomer.city }}</div>
                                                </div>
                                                <button type="button" class="icon-btn" style="margin-left: auto;" aria-label="Clear" @click="clearCustomer">
                                                    <IconX :size="16" stroke-width="1.75" />
                                                </button>
                                            </div>
                                            <template v-else>
                                                <input
                                                    v-model="customerQuery"
                                                    type="text"
                                                    placeholder="Search customers…"
                                                    @focus="customerListOpen = true"
                                                >
                                                <div v-if="customerListOpen" style="margin-top: 6px; max-height: 220px; overflow-y: auto; border: 1px solid var(--border); border-radius: var(--radius-md); background: #fff;">
                                                    <div v-if="filteredCustomers.length === 0" style="padding: 12px; color: var(--text-tertiary); font: 400 12.5px/1.4 'Inter', sans-serif;">
                                                        No matches
                                                    </div>
                                                    <button
                                                        v-for="c in filteredCustomers"
                                                        :key="c.id"
                                                        type="button"
                                                        style="display: flex; align-items: center; gap: 10px; padding: 10px 12px; width: 100%; background: transparent; border: 0; border-bottom: 1px solid var(--border-soft); cursor: pointer; text-align: left;"
                                                        @click="pickCustomer(c)"
                                                    >
                                                        <div class="avatar avatar-xs" :style="{ background: avatarColour(c.id), color: '#fff' }">{{ initials(c.name) }}</div>
                                                        <div>
                                                            <div style="font: 500 13px/1.3 'Inter', sans-serif;">{{ c.name }}</div>
                                                            <div v-if="c.city" style="font: 400 11px/1.3 'Inter', sans-serif; color: var(--text-tertiary);">{{ c.city }}</div>
                                                        </div>
                                                    </button>
                                                </div>
                                            </template>
                                            <div v-if="newForm.errors.customer_id" class="err">{{ newForm.errors.customer_id }}</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <h3>Ticket</h3>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Subject<span class="req">*</span></label>
                                            <input v-model="newForm.subject" type="text" maxlength="255" :class="{ 'has-err': newForm.errors.subject }" required>
                                            <div v-if="newForm.errors.subject" class="err">{{ newForm.errors.subject }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Priority<span class="req">*</span></label>
                                            <select v-model="newForm.priority" required>
                                                <option v-for="p in priorities" :key="p" :value="p">{{ PRIORITY_LABELS[p] }}</option>
                                            </select>
                                            <div class="field-help">
                                                SLA budget: Urgent 4h · High 8h · Medium 24h · Low 72h
                                            </div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Assign to</label>
                                            <select v-model="newForm.assigned_to">
                                                <option :value="null">Unassigned</option>
                                                <option v-for="s in staff" :key="s.id" :value="s.id">{{ s.name }}</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Initial message<span class="req">*</span></label>
                                            <textarea v-model="newForm.message" rows="5" maxlength="5000" required />
                                            <div v-if="newForm.errors.message" class="err">{{ newForm.errors.message }}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <footer class="slide-over-footer">
                                <button type="button" class="btn btn-secondary" @click="showNewTicket = false">Cancel</button>
                                <button type="submit" class="btn btn-primary" :disabled="newForm.processing || ! newForm.customer_id">
                                    <IconPlus :size="15" stroke-width="1.75" />
                                    {{ newForm.processing ? 'Creating…' : 'Create ticket' }}
                                </button>
                            </footer>
                        </form>
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </TransitionRoot>
    </InternalLayout>
</template>
