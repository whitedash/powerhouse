<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Menu,
    MenuButton,
    MenuItem,
    MenuItems,
} from '@headlessui/vue';
import {
    IconPlus,
    IconFileExport,
    IconSearch,
    IconBuilding,
    IconFlag,
    IconTag,
    IconCalendar,
    IconAdjustmentsHorizontal,
    IconArrowsSort,
    IconArrowDown,
    IconChevronDown,
    IconChevronLeft,
    IconChevronRight,
    IconDots,
    IconReceipt,
    IconClockExclamation,
    IconClock,
    IconBan,
    IconCircleCheckFilled,
    IconBellRinging,
    IconCircleCheck,
    IconDownload,
    IconTrash,
    IconX,
} from '@tabler/icons-vue';
import dayjs from 'dayjs';
import InternalLayout from '@/Layouts/InternalLayout.vue';

const props = defineProps({
    invoices: { type: Object, required: true },
    summary: { type: Object, required: true },
    billing_entities: { type: Array, default: () => [] },
    filters: { type: Object, required: true },
    statuses: { type: Array, default: () => [] },
    types: { type: Array, default: () => [] },
    entity_counts: { type: Array, default: () => [] },
});

const breadcrumbs = [
    { label: 'Powerhouse', href: '/' },
    { label: 'Invoices' },
];

const STATUS_LABELS = {
    draft: 'Draft',
    sent: 'Outstanding',
    paid: 'Paid',
    overdue: 'Overdue',
    void: 'Void',
};

const TYPE_LABELS = {
    subscription: 'Subscription',
    service: 'Service',
};

const SORT_LABELS = {
    created_at: 'Issue date',
    due_date: 'Due date',
    total: 'Amount',
    customer: 'Customer',
};

/* ─── Live search (debounced) ─── */
const searchInput = ref(props.filters.search ?? '');
let searchTimer = null;
watch(searchInput, (v) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        if ((v ?? '') === (props.filters.search ?? '')) return;
        navigate({ search: v, page: 1 });
    }, 400);
});

function navigate(patch) {
    const query = { ...props.filters, ...patch };
    Object.keys(query).forEach((k) => {
        if (query[k] === '' || query[k] === null || query[k] === undefined) delete query[k];
    });
    router.get('/invoices', query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function setFilter(key, value) {
    navigate({ [key]: value, page: 1 });
}

function clearFilters() {
    searchInput.value = '';
    router.get('/invoices', { sort: 'created_at' }, { preserveScroll: true });
}

const hasActiveFilters = computed(() =>
    !!(props.filters.search || props.filters.status || props.filters.billing_entity_id || props.filters.type)
);

/* ─── Display helpers ─── */
function formatGBP(value) {
    const n = Number(value || 0);
    return '£' + n.toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatInt(value) {
    return Number(value || 0).toLocaleString('en-GB');
}

function formatDate(iso) {
    if (!iso) return '—';
    return dayjs(iso).format('D MMM YYYY');
}

function formatShortDate(iso) {
    if (!iso) return '';
    return dayjs(iso).format('D MMM');
}

function initials(name) {
    const parts = String(name || '').trim().split(/\s+/);
    return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || '?';
}

function customerAvatarClass(id) {
    const palette = ['av-1', 'av-2', 'av-3', 'av-5', 'av-teal', 'av-amber', 'av-navy'];
    return palette[(id ?? 0) % palette.length];
}

const ENTITY_PILL_PALETTE = ['maa', 'kon', 'gold', 'blue'];
function entityPillClass(entityId) {
    if (entityId == null) return '';
    return ENTITY_PILL_PALETTE[(entityId - 1) % ENTITY_PILL_PALETTE.length];
}

/* ─── Per-row state classes ─── */
function rowClass(inv) {
    if (inv.status === 'void') return 'row-void';
    if (inv.status === 'overdue') return 'row-overdue';
    if (inv.status === 'sent' && inv.is_due_today) return 'row-due-today';
    return '';
}

function amountClass(inv) {
    if (inv.status === 'void') return 'muted';
    if (inv.status === 'overdue') return 'danger';
    if (inv.status === 'sent') return 'warn';
    return '';
}

function dueClass(inv) {
    if (inv.status === 'void') return 'muted';
    if (inv.status === 'overdue') return 'danger';
    if (inv.status === 'sent' && inv.is_due_today) return 'warn';
    if (inv.status === 'paid') return 'muted';
    return '';
}

/* ─── Bulk selection (local UI state only — actions are stubs) ─── */
const selected = ref(new Set());
function toggleSelect(id) {
    if (selected.value.has(id)) selected.value.delete(id);
    else selected.value.add(id);
    selected.value = new Set(selected.value);
}
function clearSelection() {
    selected.value = new Set();
}
const allSelected = computed(() =>
    props.invoices.data.length > 0 && selected.value.size === props.invoices.data.length
);
const someSelected = computed(() => selected.value.size > 0 && !allSelected.value);
function toggleAll() {
    if (allSelected.value) clearSelection();
    else selected.value = new Set(props.invoices.data.map((i) => i.id));
}

/* ─── Row click → customer detail ─── */
function openCustomer(customerId) {
    if (customerId) router.visit(`/customers/${customerId}`);
}

/* ─── Pagination ─── */
const pageMeta = computed(() => ({
    from: props.invoices.from ?? 0,
    to: props.invoices.to ?? 0,
    total: props.invoices.total ?? 0,
    links: props.invoices.links ?? [],
}));
function navigateToLink(url) {
    if (!url) return;
    router.visit(url, { preserveScroll: true, preserveState: true });
}

/* ─── New invoice button ─── */
function goNew() {
    router.visit('/invoices/new');
}
</script>

<template>
    <Head title="Invoices" />

    <InternalLayout title="Invoices" :breadcrumbs="breadcrumbs" active-nav="invoices">
        <template #topbar-actions>
            <button class="btn btn-secondary" type="button">
                <IconFileExport :size="15" stroke-width="1.75" />
                Export
            </button>
            <button class="btn btn-primary" type="button" @click="goNew">
                <IconPlus :size="15" stroke-width="1.75" />
                New invoice
            </button>
        </template>

        <div class="invoices-list">
            <!-- Greeting -->
            <div class="greet">
                <div>
                    <h1>Invoices</h1>
                    <div class="sub">
                        All entities ·
                        <template v-for="(e, i) in entity_counts" :key="e.id"><span v-if="i">, </span>{{ e.name }}</template>
                        · {{ summary.total_raised }} raised this month
                    </div>
                </div>
            </div>

            <!-- Summary strip -->
            <div class="summary-strip">
                <div class="stat-pill">
                    <span class="d gold" /><strong>{{ formatInt(summary.total_raised) }}</strong>
                    <span class="lbl">raised</span><span class="sub">this month</span>
                </div>
                <div class="stat-pill">
                    <span class="d green" /><strong>{{ formatGBP(summary.total_paid) }}</strong>
                    <span class="lbl">paid</span><span class="sub">this month</span>
                </div>
                <div class="stat-pill">
                    <span class="d amber" /><strong>{{ formatInt(summary.outstanding_count) }}</strong>
                    <span class="lbl">outstanding</span><span class="sub">{{ formatGBP(summary.outstanding_amount) }} due</span>
                </div>
                <div v-if="summary.overdue_count > 0" class="stat-pill">
                    <span class="d red" /><strong>{{ formatInt(summary.overdue_count) }}</strong>
                    <span class="lbl">overdue</span><span class="sub">{{ formatGBP(summary.overdue_amount) }} past due date</span>
                </div>
            </div>

            <!-- Filter bar -->
            <div class="filter-bar">
                <div class="field-search">
                    <span class="search-icon"><IconSearch :size="16" stroke-width="1.75" /></span>
                    <input v-model="searchInput" type="search" placeholder="Search by invoice #, customer, amount…">
                </div>

                <Menu as="div" class="dd-menu">
                    <MenuButton class="dd-btn">
                        <IconBuilding :size="16" stroke-width="1.75" />
                        <span>{{ filters.billing_entity_id ? billing_entities.find(b => b.id === Number(filters.billing_entity_id))?.name : 'All entities' }}</span>
                        <IconChevronDown :size="14" class="ch" stroke-width="1.75" />
                    </MenuButton>
                    <MenuItems class="dd-popover">
                        <MenuItem v-slot="{ active }">
                            <button type="button" :class="['dd-option', { active }]" @click="setFilter('billing_entity_id', null)">All entities</button>
                        </MenuItem>
                        <MenuItem v-for="e in billing_entities" :key="e.id" v-slot="{ active }">
                            <button type="button" :class="['dd-option', { active }]" @click="setFilter('billing_entity_id', e.id)">{{ e.name }}</button>
                        </MenuItem>
                    </MenuItems>
                </Menu>

                <Menu as="div" class="dd-menu">
                    <MenuButton class="dd-btn">
                        <IconFlag :size="16" stroke-width="1.75" />
                        <span>{{ filters.status ? STATUS_LABELS[filters.status] : 'All statuses' }}</span>
                        <IconChevronDown :size="14" class="ch" stroke-width="1.75" />
                    </MenuButton>
                    <MenuItems class="dd-popover">
                        <MenuItem v-slot="{ active }">
                            <button type="button" :class="['dd-option', { active }]" @click="setFilter('status', null)">All statuses</button>
                        </MenuItem>
                        <MenuItem v-for="s in statuses" :key="s" v-slot="{ active }">
                            <button type="button" :class="['dd-option', { active }]" @click="setFilter('status', s)">{{ STATUS_LABELS[s] }}</button>
                        </MenuItem>
                    </MenuItems>
                </Menu>

                <Menu as="div" class="dd-menu">
                    <MenuButton class="dd-btn">
                        <IconTag :size="16" stroke-width="1.75" />
                        <span>{{ filters.type ? TYPE_LABELS[filters.type] : 'All types' }}</span>
                        <IconChevronDown :size="14" class="ch" stroke-width="1.75" />
                    </MenuButton>
                    <MenuItems class="dd-popover">
                        <MenuItem v-slot="{ active }">
                            <button type="button" :class="['dd-option', { active }]" @click="setFilter('type', null)">All types</button>
                        </MenuItem>
                        <MenuItem v-for="t in types" :key="t" v-slot="{ active }">
                            <button type="button" :class="['dd-option', { active }]" @click="setFilter('type', t)">{{ TYPE_LABELS[t] }}</button>
                        </MenuItem>
                    </MenuItems>
                </Menu>

                <button type="button" class="dd-btn active-range">
                    <IconCalendar :size="16" stroke-width="1.75" />
                    {{ dayjs().format('MMMM YYYY') }}
                    <IconChevronDown :size="14" class="ch" stroke-width="1.75" />
                </button>

                <div class="right">
                    <button
                        type="button"
                        class="btn btn-ghost btn-sm"
                        :class="{ 'btn-dot': hasActiveFilters }"
                        style="color: var(--text-secondary);"
                    >
                        <IconAdjustmentsHorizontal :size="14" stroke-width="1.75" />
                        Filters
                    </button>
                    <div class="divider-v" style="height: 20px;" />
                    <Menu as="div" class="dd-menu">
                        <MenuButton class="btn btn-ghost btn-sm" style="color: var(--text-secondary);">
                            <IconArrowsSort :size="14" stroke-width="1.75" />
                            Sort: {{ SORT_LABELS[filters.sort] }}
                            <IconArrowDown :size="13" stroke-width="1.75" />
                        </MenuButton>
                        <MenuItems class="dd-popover right-align">
                            <MenuItem v-for="(label, key) in SORT_LABELS" :key="key" v-slot="{ active }">
                                <button type="button" :class="['dd-option', { active }]" @click="setFilter('sort', key)">{{ label }}</button>
                            </MenuItem>
                        </MenuItems>
                    </Menu>
                </div>
            </div>

            <!-- Bulk action bar (selection-aware) -->
            <div v-if="selected.size > 0" class="bulk-bar">
                <div class="count"><strong>{{ selected.size }}</strong> invoice<template v-if="selected.size !== 1">s</template> selected</div>
                <div class="bulk-divider" />
                <div class="actions">
                    <button type="button" class="bulk-act">
                        <IconBellRinging :size="15" stroke-width="1.75" />
                        Send reminder
                    </button>
                    <button type="button" class="bulk-act">
                        <IconCircleCheck :size="15" stroke-width="1.75" />
                        Mark paid
                    </button>
                    <button type="button" class="bulk-act">
                        <IconDownload :size="15" stroke-width="1.75" />
                        Download PDF
                    </button>
                    <button type="button" class="bulk-act danger">
                        <IconTrash :size="15" stroke-width="1.75" />
                        Delete
                    </button>
                </div>
                <button type="button" class="clear" @click="clearSelection">
                    <IconX :size="13" stroke-width="2" />
                    Clear selection
                </button>
            </div>

            <!-- Table card -->
            <div class="table-card">
                <table v-if="invoices.data.length" class="tbl">
                    <colgroup>
                        <col style="width: 44px;">
                        <col>
                        <col style="width: 220px;">
                        <col style="width: 130px;">
                        <col style="width: 110px;">
                        <col style="width: 220px;">
                        <col style="width: 130px;">
                        <col style="width: 48px;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>
                                <span
                                    class="cb"
                                    :class="{ checked: allSelected, indeterminate: someSelected }"
                                    role="checkbox"
                                    @click="toggleAll"
                                />
                            </th>
                            <th>Invoice</th>
                            <th>Customer</th>
                            <th>Entity</th>
                            <th class="num">Amount</th>
                            <th>Status</th>
                            <th>Due date</th>
                            <th />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="inv in invoices.data"
                            :key="inv.id"
                            :class="[rowClass(inv), { selected: selected.has(inv.id) }]"
                        >
                            <td @click.stop>
                                <span
                                    class="cb"
                                    :class="{ checked: selected.has(inv.id) }"
                                    role="checkbox"
                                    @click="toggleSelect(inv.id)"
                                />
                            </td>

                            <!-- Invoice cell (number / line / type) -->
                            <td>
                                <Link :href="`/invoices/${inv.id}`" style="color: inherit; text-decoration: none;">
                                    <div class="inv-num">{{ inv.number }}</div>
                                    <div class="inv-line">{{ inv.description }}</div>
                                    <div class="inv-type">
                                        <span class="badge badge-sm" :class="inv.type === 'service' ? 'badge-gold' : 'badge-info'">
                                            {{ TYPE_LABELS[inv.type] }}
                                        </span>
                                    </div>
                                </Link>
                            </td>

                            <!-- Customer cell -->
                            <td @click="openCustomer(inv.customer?.id)" style="cursor: pointer;">
                                <div v-if="inv.customer" class="cell-cust">
                                    <div class="avatar" :class="customerAvatarClass(inv.customer.id)">{{ initials(inv.customer.name) }}</div>
                                    <div>
                                        <div class="cust-name">{{ inv.customer.name }}</div>
                                        <div v-if="inv.customer.city" class="cust-loc">{{ inv.customer.city }}, UK</div>
                                    </div>
                                </div>
                                <span v-else style="color: var(--text-tertiary);">—</span>
                            </td>

                            <!-- Entity pill -->
                            <td>
                                <span v-if="inv.billing_entity" class="entity-pill" :class="entityPillClass(inv.billing_entity.id)">
                                    <span class="swatch" />{{ inv.billing_entity.name }}
                                </span>
                                <span v-else style="color: var(--text-tertiary);">—</span>
                            </td>

                            <!-- Amount -->
                            <td class="amt-cell">
                                <span class="amt" :class="amountClass(inv)">{{ formatGBP(inv.total) }}</span>
                            </td>

                            <!-- Status -->
                            <td>
                                <!-- Paid: stacked badge + paid-on date -->
                                <div v-if="inv.status === 'paid'" class="status-stack">
                                    <span class="badge badge-active">Paid</span>
                                    <span v-if="inv.paid_at" class="paid-on">paid {{ formatShortDate(inv.paid_at) }}</span>
                                </div>

                                <!-- Overdue: cluster (badge + days-late warn pill) -->
                                <div v-else-if="inv.status === 'overdue'" class="status-cluster">
                                    <span class="badge badge-overdue">Overdue</span>
                                    <span v-if="inv.days_overdue" class="warn-pill red">
                                        <IconClockExclamation :size="12" stroke-width="1.75" />
                                        {{ inv.days_overdue }}d late
                                    </span>
                                </div>

                                <!-- Sent + due today -->
                                <div v-else-if="inv.status === 'sent' && inv.is_due_today" class="status-cluster">
                                    <span class="badge badge-pending">Outstanding</span>
                                    <span class="warn-pill">
                                        <IconClock :size="12" stroke-width="1.75" />
                                        Due today
                                    </span>
                                </div>

                                <!-- Plain sent -->
                                <span v-else-if="inv.status === 'sent'" class="badge badge-pending">Outstanding</span>

                                <!-- Void with ban icon -->
                                <span v-else-if="inv.status === 'void'" class="badge badge-inactive badge-icon-pre">
                                    <IconBan :size="11" stroke-width="1.75" />
                                    Void
                                </span>

                                <!-- Draft -->
                                <span v-else class="badge badge-inactive">Draft</span>
                            </td>

                            <!-- Due date (with optional "Due today" stack) -->
                            <td>
                                <div v-if="inv.status === 'sent' && inv.is_due_today" class="due-stack">
                                    <span class="due warn">{{ formatDate(inv.due_date) }}</span>
                                    <span class="warn-pill">
                                        <IconClock :size="12" stroke-width="1.75" />
                                        Due today
                                    </span>
                                </div>
                                <span v-else class="due" :class="dueClass(inv)">{{ formatDate(inv.due_date) }}</span>
                            </td>

                            <!-- Per-row actions -->
                            <td @click.stop>
                                <Menu as="div" class="dd-menu">
                                    <MenuButton class="icon-btn" aria-label="Actions">
                                        <IconDots :size="18" stroke-width="1.75" />
                                    </MenuButton>
                                    <MenuItems class="dd-popover right-align">
                                        <MenuItem v-slot="{ active }">
                                            <Link :href="`/invoices/${inv.id}`" :class="['dd-option', { active }]">View invoice</Link>
                                        </MenuItem>
                                        <MenuItem v-slot="{ active }">
                                            <button type="button" :class="['dd-option', { active }]">Send reminder</button>
                                        </MenuItem>
                                        <MenuItem v-slot="{ active }">
                                            <button type="button" :class="['dd-option', { active }]">Mark as paid</button>
                                        </MenuItem>
                                        <MenuItem v-slot="{ active }">
                                            <button type="button" :class="['dd-option', { active }]">Download PDF</button>
                                        </MenuItem>
                                        <div style="height: 1px; background: var(--border-soft); margin: 4px 0;" />
                                        <MenuItem v-slot="{ active }">
                                            <button type="button" :class="['dd-option', { active }]" style="color: var(--danger);">Void invoice</button>
                                        </MenuItem>
                                    </MenuItems>
                                </Menu>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Empty state -->
                <div v-else class="tab-empty" style="padding: 64px 24px;">
                    <div style="color: var(--text-tertiary); display: inline-flex;">
                        <IconReceipt :size="48" stroke-width="1.5" />
                    </div>
                    <h3 style="margin-top: 12px;">{{ hasActiveFilters ? 'No invoices match your filters' : 'No invoices yet' }}</h3>
                    <p>{{ hasActiveFilters ? 'Try adjusting your search.' : 'Create your first invoice to get started.' }}</p>
                    <a v-if="hasActiveFilters" href="#" @click.prevent="clearFilters">Clear filters</a>
                    <button v-else type="button" class="btn btn-primary" @click="goNew">
                        <IconPlus :size="15" stroke-width="1.75" />
                        New invoice
                    </button>
                </div>

                <!-- Pagination -->
                <div v-if="invoices.data.length" class="tbl-foot">
                    <div class="info">
                        Showing <strong style="color: var(--text-primary); font-weight: 600;">{{ pageMeta.from }} – {{ pageMeta.to }}</strong>
                        of <strong style="color: var(--text-primary); font-weight: 600;">{{ pageMeta.total }}</strong> invoices
                    </div>
                    <div class="right">
                        <template v-for="(link, i) in pageMeta.links" :key="i">
                            <button
                                v-if="link.label.includes('Previous')"
                                type="button"
                                class="pg-btn"
                                :disabled="!link.url"
                                @click="navigateToLink(link.url)"
                            >
                                <IconChevronLeft :size="14" stroke-width="1.75" />
                                Previous
                            </button>
                            <button
                                v-else-if="link.label.includes('Next')"
                                type="button"
                                class="pg-btn"
                                :disabled="!link.url"
                                @click="navigateToLink(link.url)"
                            >
                                Next
                                <IconChevronRight :size="14" stroke-width="1.75" />
                            </button>
                            <span v-else-if="link.label === '...'" style="color: var(--text-tertiary); padding: 0 4px;">…</span>
                            <button
                                v-else
                                type="button"
                                class="pg-btn"
                                :class="{ active: link.active }"
                                :disabled="!link.url"
                                @click="navigateToLink(link.url)"
                            >{{ link.label }}</button>
                        </template>
                    </div>
                </div>

                <!-- Entity legend strip -->
                <div v-if="entity_counts.length" class="entity-legend">
                    <span class="lbl">Entities</span>
                    <span v-for="e in entity_counts" :key="e.id" class="legend-item">
                        <span class="entity-pill" :class="entityPillClass(e.id)"><span class="swatch" />{{ e.name }}</span>
                        <span class="num">{{ e.count }}</span> invoices
                    </span>
                    <span class="legend-spacer" />
                    <span class="legend-rec">
                        <IconCircleCheckFilled :size="16" stroke-width="1.75" />
                        {{ formatGBP(summary.total_paid) }} received this month
                    </span>
                </div>
            </div>

            <div class="page-foot">
                <div>Powerhouse v3.2.0 · Whitedash</div>
                <div>Sorted by <strong>{{ SORT_LABELS[filters.sort] }}</strong></div>
            </div>
        </div>
    </InternalLayout>
</template>

<style scoped>
.dd-menu { position: relative; }
.dd-popover {
    position: absolute;
    z-index: 30;
    margin-top: 6px;
    min-width: 180px;
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-md);
    padding: 4px;
    outline: 0;
}
.dd-popover.right-align { right: 0; }
.dd-option {
    width: 100%;
    display: block;
    text-align: left;
    padding: 7px 10px;
    border-radius: 6px;
    background: transparent;
    border: 0;
    cursor: pointer;
    color: var(--text-primary);
    font: 400 13px/1.3 'Inter', sans-serif;
    text-decoration: none;
}
.dd-option.active,
.dd-option:hover { background: var(--neutral-bg); color: var(--accent); }
</style>
