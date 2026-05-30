<script setup>
import { computed, ref } from 'vue';
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
    IconArrowRight,
    IconChevronLeft,
    IconChevronRight,
    IconCircleCheck,
    IconCoins,
    IconDots,
    IconEye,
    IconKey,
    IconPencil,
    IconPercentage,
    IconReceipt,
    IconRotateClockwise,
    IconStar,
    IconUsers,
    IconX,
    IconCurrencyPound,
    IconTrendingUp,
    IconClock,
    IconChartBar,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const props = defineProps({
    referrer: { type: Object, required: true },
    kpis: { type: Object, required: true },
    ledger: { type: Object, required: true },
    customers: { type: Array, default: () => [] },
    rules: { type: Array, default: () => [] },
    trend: { type: Array, default: () => [] },
});

const page = usePage();

const breadcrumbs = computed(() => [
    { label: 'Referrers', href: '/referrers' },
    { label: props.referrer.name },
]);

/* ─── Formatting helpers ─── */
function gbp(n) {
    return new Intl.NumberFormat('en-GB', {
        style: 'currency', currency: 'GBP', minimumFractionDigits: 2,
    }).format(Number(n || 0));
}
function gbpRound(n) {
    return new Intl.NumberFormat('en-GB', {
        style: 'currency', currency: 'GBP', maximumFractionDigits: 0,
    }).format(Number(n || 0));
}
function initials(name) {
    const parts = String(name || '').trim().split(/\s+/);
    return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || '?';
}
function avatarStyle(c) {
    return { background: c || '#64748B', color: '#fff' };
}

/* ─── Ledger row metadata ─── */
const LEDGER_ICON = {
    monthly_recurring: { cls: 'info', icon: IconRotateClockwise, label: 'Monthly recurring' },
    onboarding: { cls: 'warn', icon: IconStar, label: 'Onboarding' },
    invoice_paid: { cls: 'success', icon: IconReceipt, label: 'Invoice commission' },
};
function ledgerIcon(entry) {
    if (entry.status === 'paid') return { cls: 'success', icon: IconCircleCheck, label: 'Paid' };
    return LEDGER_ICON[entry.trigger_type] ?? { cls: 'neutral', icon: IconRotateClockwise, label: entry.trigger_type };
}
const STATUS_BADGE = {
    pending:  { label: 'Pending',  cls: 'badge-pending' },
    approved: { label: 'Approved', cls: 'badge-active' },
    paid:     { label: 'Paid',     cls: 'badge-active' },
    voided:   { label: 'Voided',   cls: 'badge-inactive' },
};
function statusBadge(entry) {
    return STATUS_BADGE[entry.status] ?? STATUS_BADGE.pending;
}
function statusSub(entry) {
    if (entry.status === 'paid' && entry.paid_at) return `Paid ${entry.paid_at}`;
    if (entry.status === 'approved' && entry.approved_at) return `Approved ${entry.approved_at}`;
    return null;
}

/* ─── Status filter (client-side, simple) ─── */
const statusFilter = ref('all');
const filteredLedgerData = computed(() => {
    if (statusFilter.value === 'all') return props.ledger.data;
    return props.ledger.data.filter((e) => e.status === statusFilter.value);
});

/* ─── Approve / mark-paid (re-uses controller endpoints) ─── */
function approveOne(entry) {
    router.post(`/referrers/${entry.id}/approve`, {}, { preserveScroll: true });
}
function markPaid(entry) {
    router.post(`/referrers/${entry.id}/mark-paid`, {}, { preserveScroll: true });
}

/* ─── Approve-all (scoped to this referrer) ─── */
const showApproveAllModal = ref(false);
const approveAllProcessing = ref(false);
const approveAllMessage = computed(() => {
    const pendingCount = props.ledger.data.filter((e) => e.status === 'pending').length;
    return `Approve all pending commission entries for ${props.referrer.name} totalling ${gbp(props.kpis.pending_commission)}? You will need to mark each as paid once the payout completes.`;
});
function askApproveAll() {
    if ((props.kpis.pending_commission ?? 0) <= 0) return;
    showApproveAllModal.value = true;
}
function handleApproveAll() {
    approveAllProcessing.value = true;
    router.post('/referrers/approve-all', { referrer_id: props.referrer.id }, {
        preserveScroll: true,
        onFinish: () => {
            approveAllProcessing.value = false;
            showApproveAllModal.value = false;
        },
    });
}

/* ─── Edit slide-over ─── */
const showEdit = ref(false);
const editForm = useForm({
    name: '',
    email: '',
    commission_note: '',
    is_active: true,
});
function openEdit() {
    editForm.reset();
    editForm.clearErrors();
    editForm.name = props.referrer.name;
    editForm.email = props.referrer.email;
    editForm.is_active = props.referrer.is_active;
    showEdit.value = true;
}
function submitEdit() {
    editForm.put(`/referrers/${props.referrer.id}`, {
        preserveScroll: true,
        onSuccess: () => { showEdit.value = false; },
    });
}

/* ─── Reset password (ConfirmModal → flash credentials) ─── */
const showResetModal = ref(false);
const resetProcessing = ref(false);
function askReset() { showResetModal.value = true; }
function performReset() {
    resetProcessing.value = true;
    router.post(`/referrers/${props.referrer.id}/reset-password`, {}, {
        preserveScroll: true,
        onFinish: () => {
            resetProcessing.value = false;
            showResetModal.value = false;
        },
    });
}
const resetMessage = computed(() =>
    `A new temporary password will be generated for ${props.referrer.name}. Share it securely — it won't be shown again.`,
);

/* ─── Preview portal (same impersonation fetch pattern as Index) ─── */
async function openReferrerPreview() {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
    try {
        const res = await fetch(`/impersonate/referrer/${props.referrer.id}`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });
        const data = await res.json().catch(() => ({}));
        if (! res.ok) {
            return;
        }
        if (data?.url) window.open(data.url, '_blank', 'noopener');
    } catch (e) {
        // Silent — the toast system will surface a flash from a 422 retry.
    }
}

/* ─── 6-month trend bar scale ─── */
const maxCommission = computed(() => Math.max(1, ...props.trend.map((t) => t.commission)));
const maxCustomers = computed(() => Math.max(1, ...props.trend.map((t) => t.new_customers)));

/* ─── Pagination ─── */
function navigateLedger(url) {
    if (! url) return;
    router.visit(url, { preserveScroll: true, preserveState: true });
}

/* ─── Customer products ─── */
const PRODUCT_STATUS_LABEL = { active: 'Active', trial: 'Trial' };
function productBadgeStyle(p) {
    return p.colour ? { background: p.colour, color: '#fff' } : { background: '#64748B', color: '#fff' };
}

const visibleCustomers = computed(() => props.customers.slice(0, 8));
const hasMoreCustomers = computed(() => props.customers.length > 8);
</script>

<template>
    <Head :title="referrer.name" />

    <InternalLayout :title="referrer.name" :breadcrumbs="breadcrumbs" active-nav="referrers">
        <template #topbar-actions>
            <button type="button" class="btn btn-ghost btn-sm" @click="openReferrerPreview">
                <IconEye :size="14" stroke-width="1.75" />
                Preview portal
            </button>
            <button type="button" class="btn btn-secondary btn-sm" @click="openEdit">
                <IconPencil :size="14" stroke-width="1.75" />
                Edit
            </button>
            <button type="button" class="btn btn-ghost btn-sm" @click="askReset">
                <IconKey :size="14" stroke-width="1.75" />
                Reset password
            </button>
        </template>

        <div class="ref-show">
            <!-- ═══ HEADER ═══ -->
            <div class="ref-show-head">
                <div class="avatar lg" :style="avatarStyle(referrer.avatar_colour)">{{ initials(referrer.name) }}</div>
                <div class="ref-show-head-text">
                    <div class="ref-show-name-row">
                        <h1>{{ referrer.name }}</h1>
                        <span
                            class="badge badge-sm"
                            :class="referrer.is_active ? 'badge-active' : 'badge-inactive'"
                        >{{ referrer.is_active ? 'Active' : 'Inactive' }}</span>
                    </div>
                    <div class="ref-show-meta">
                        <span>{{ referrer.email }}</span>
                        <span v-if="referrer.member_since" class="sep">·</span>
                        <span v-if="referrer.member_since" class="muted">Member since {{ referrer.member_since }}</span>
                    </div>
                </div>
            </div>

            <!-- ═══ KPI CARDS ═══ -->
            <div class="ref-show-kpis">
                <div class="kpi-card teal">
                    <div class="kpi-top"><IconUsers :size="16" stroke-width="1.75" /></div>
                    <div class="kpi-value">{{ kpis.active_customers }}</div>
                    <div class="kpi-label">Active customers</div>
                    <div class="kpi-trend">{{ kpis.total_customers }} total ever</div>
                </div>
                <div class="kpi-card gold">
                    <div class="kpi-top"><IconCurrencyPound :size="16" stroke-width="1.75" /></div>
                    <div class="kpi-value">{{ gbpRound(kpis.this_month) }}</div>
                    <div class="kpi-label">Earned this month</div>
                </div>
                <div class="kpi-card green">
                    <div class="kpi-top"><IconCircleCheck :size="16" stroke-width="1.75" /></div>
                    <div class="kpi-value">{{ gbpRound(kpis.paid_all_time) }}</div>
                    <div class="kpi-label">Paid all time</div>
                </div>
                <div class="kpi-card amber">
                    <div class="kpi-top"><IconClock :size="16" stroke-width="1.75" /></div>
                    <div class="kpi-value">{{ gbpRound(kpis.pending_commission) }}</div>
                    <div class="kpi-label">Pending payout</div>
                    <button
                        v-if="kpis.pending_commission > 0"
                        type="button"
                        class="kpi-action"
                        @click="askApproveAll"
                    >
                        Approve all
                        <IconArrowRight :size="12" stroke-width="2" />
                    </button>
                </div>
                <div class="kpi-card blue">
                    <div class="kpi-top"><IconTrendingUp :size="16" stroke-width="1.75" /></div>
                    <div class="kpi-value">{{ gbpRound(kpis.approved_commission) }}</div>
                    <div class="kpi-label">Approved</div>
                    <div class="kpi-trend">ready to pay</div>
                </div>
                <div class="kpi-card grey">
                    <div class="kpi-top"><IconChartBar :size="16" stroke-width="1.75" /></div>
                    <div class="kpi-value">{{ gbpRound(kpis.paid_this_year) }}</div>
                    <div class="kpi-label">Paid this year</div>
                </div>
            </div>

            <!-- ═══ 65/35 GRID ═══ -->
            <div class="ref-show-grid">
                <!-- LEFT: COMMISSION LEDGER -->
                <div class="col">
                    <div class="card">
                        <div class="card-header">
                            <div class="h-icon gold"><IconCoins :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Commission ledger</h3>
                                <div class="sub">{{ ledger.total }} {{ ledger.total === 1 ? 'entry' : 'entries' }}</div>
                            </div>
                            <div class="right">
                                <div class="ref-show-filters">
                                    <button
                                        v-for="opt in [
                                            { v: 'all', l: 'All' },
                                            { v: 'pending', l: 'Pending' },
                                            { v: 'approved', l: 'Approved' },
                                            { v: 'paid', l: 'Paid' },
                                        ]"
                                        :key="opt.v"
                                        type="button"
                                        class="ref-show-filter-btn"
                                        :class="{ active: statusFilter === opt.v }"
                                        @click="statusFilter = opt.v"
                                    >{{ opt.l }}</button>
                                </div>
                            </div>
                        </div>

                        <div v-if="filteredLedgerData.length === 0" class="ref-show-empty">
                            No commission entries{{ statusFilter !== 'all' ? ` with status "${statusFilter}"` : '' }}.
                        </div>

                        <table v-else class="tbl ref-show-ledger">
                            <colgroup>
                                <col>
                                <col style="width: 180px;">
                                <col style="width: 110px;">
                                <col style="width: 140px;">
                                <col style="width: 110px;">
                                <col style="width: 48px;">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th>Description</th>
                                    <th>Customer</th>
                                    <th class="num">Amount</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="entry in filteredLedgerData" :key="entry.id">
                                    <td>
                                        <div class="ref-show-ledger-desc">
                                            <div :class="['ic-circle', ledgerIcon(entry).cls]">
                                                <component :is="ledgerIcon(entry).icon" :size="14" stroke-width="1.75" />
                                            </div>
                                            <div>
                                                <div class="ref-show-ledger-title">{{ ledgerIcon(entry).label }}</div>
                                                <div v-if="entry.product_name" class="ref-show-ledger-sub">
                                                    <span class="ref-show-product-dot" :style="{ background: entry.product_colour || '#64748B' }" />
                                                    {{ entry.product_name }}
                                                </div>
                                                <div v-if="entry.period_start" class="ref-show-ledger-period">
                                                    {{ entry.period_start }}<template v-if="entry.period_end"> – {{ entry.period_end }}</template>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="ref-show-cust-cell">
                                        <Link
                                            v-if="entry.customer_name"
                                            :href="`/customers?search=${encodeURIComponent(entry.customer_name)}`"
                                            class="ref-show-ledger-cust"
                                        >{{ entry.customer_name }}</Link>
                                        <span v-else class="muted">—</span>
                                    </td>
                                    <td class="num">
                                        <div class="ref-show-amount">{{ gbp(entry.commission_amount) }}</div>
                                        <div v-if="entry.gross_amount > 0" class="ref-show-amount-sub">on {{ gbp(entry.gross_amount) }}</div>
                                    </td>
                                    <td>
                                        <span :class="['badge', statusBadge(entry).cls]">{{ statusBadge(entry).label }}</span>
                                        <div v-if="statusSub(entry)" class="ref-show-status-sub">{{ statusSub(entry) }}</div>
                                    </td>
                                    <td><span class="muted">{{ entry.created_at }}</span></td>
                                    <td>
                                        <Menu as="div" class="dd-menu">
                                            <MenuButton class="icon-btn" aria-label="Actions">
                                                <IconDots :size="16" stroke-width="1.75" />
                                            </MenuButton>
                                            <MenuItems class="dd-popover right-align">
                                                <MenuItem v-if="entry.status === 'pending'" v-slot="{ active }">
                                                    <button type="button" :class="['dd-option', { active }]" @click="approveOne(entry)">Approve</button>
                                                </MenuItem>
                                                <MenuItem v-else-if="entry.status === 'approved'" v-slot="{ active }">
                                                    <button type="button" :class="['dd-option', { active }]" @click="markPaid(entry)">Mark as paid</button>
                                                </MenuItem>
                                                <MenuItem v-else v-slot="{ active }">
                                                    <button type="button" :class="['dd-option', { active }]" disabled style="opacity: .55; cursor: not-allowed;">View entry</button>
                                                </MenuItem>
                                            </MenuItems>
                                        </Menu>
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <div class="tbl-foot">
                            <div class="info">
                                Showing <strong>{{ ledger.from || 0 }} – {{ ledger.to || 0 }}</strong> of <strong>{{ ledger.total }}</strong> entries
                            </div>
                            <div class="right">
                                <button
                                    v-if="kpis.pending_commission > 0"
                                    type="button"
                                    class="btn btn-primary btn-sm"
                                    @click="askApproveAll"
                                >
                                    <IconCircleCheck :size="14" stroke-width="1.75" />
                                    Approve all pending ({{ gbpRound(kpis.pending_commission) }})
                                </button>
                                <button
                                    type="button"
                                    class="pg-btn"
                                    :disabled="!ledger.prev_page_url"
                                    @click="navigateLedger(ledger.prev_page_url)"
                                >
                                    <IconChevronLeft :size="14" stroke-width="1.75" />Previous
                                </button>
                                <button
                                    type="button"
                                    class="pg-btn"
                                    :disabled="!ledger.next_page_url"
                                    @click="navigateLedger(ledger.next_page_url)"
                                >
                                    Next<IconChevronRight :size="14" stroke-width="1.75" />
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- RIGHT: RULES / TREND / CUSTOMERS -->
                <div class="col">
                    <!-- Commission rules -->
                    <div class="card">
                        <div class="card-header">
                            <div class="h-icon gold"><IconPercentage :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Commission rules</h3>
                                <div class="sub">{{ rules.length }} active</div>
                            </div>
                        </div>
                        <div v-if="rules.length === 0" class="ref-show-empty small">
                            No commission rules set.
                            <div class="muted-sub">Rules are managed globally in Settings.</div>
                        </div>
                        <div v-else class="ref-show-rule-list">
                            <div v-for="rule in rules" :key="rule.id" class="ref-show-rule">
                                <div class="ref-show-rule-head">
                                    <span class="ref-show-rule-product">{{ rule.product_name ?? '—' }}</span>
                                </div>
                                <div class="ref-show-rule-desc">{{ rule.description }}</div>
                                <div class="ref-show-rule-validity">
                                    Valid from {{ rule.valid_from ?? '—' }}
                                    <template v-if="rule.valid_until"> · Expires {{ rule.valid_until }}</template>
                                    <template v-else> · No expiry</template>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 6-month trend -->
                    <div class="card">
                        <div class="card-header">
                            <div class="h-icon"><IconChartBar :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>6-month performance</h3>
                                <div class="sub">Commission &amp; new customers</div>
                            </div>
                        </div>
                        <div class="ref-show-trend">
                            <div v-for="(m, i) in trend" :key="i" class="ref-show-trend-month">
                                <div class="ref-show-trend-bars">
                                    <div
                                        class="ref-show-trend-bar gold"
                                        :style="{ height: `${(m.commission / maxCommission) * 100}%` }"
                                        :title="`${gbpRound(m.commission)} commission`"
                                    />
                                    <div
                                        class="ref-show-trend-bar teal"
                                        :style="{ height: `${(m.new_customers / maxCustomers) * 100}%` }"
                                        :title="`${m.new_customers} new customers`"
                                    />
                                </div>
                                <div class="ref-show-trend-label">{{ m.month }}</div>
                            </div>
                        </div>
                        <div class="ref-show-trend-legend">
                            <span><span class="dot gold" />Commission</span>
                            <span><span class="dot teal" />New customers</span>
                        </div>
                    </div>

                    <!-- Referred customers -->
                    <div class="card">
                        <div class="card-header">
                            <div class="h-icon"><IconUsers :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Referred customers</h3>
                                <div class="sub">{{ customers.length }} {{ customers.length === 1 ? 'total' : 'total' }}</div>
                            </div>
                            <div v-if="hasMoreCustomers" class="right">
                                <Link :href="`/customers?referrer=${referrer.id}`" class="foot-link">
                                    View all<IconArrowRight :size="14" stroke-width="1.75" />
                                </Link>
                            </div>
                        </div>
                        <div v-if="customers.length === 0" class="ref-show-empty small">
                            No customers referred yet.
                            <div class="muted-sub">Share the referral link to get started.</div>
                        </div>
                        <div v-else class="ref-show-cust-list">
                            <Link
                                v-for="c in visibleCustomers"
                                :key="c.customer_id"
                                :href="`/customers/${c.customer_id}`"
                                class="ref-show-cust-row"
                            >
                                <div class="avatar sm" :style="avatarStyle('#94A3B8')">{{ initials(c.customer_name) }}</div>
                                <div class="ref-show-cust-meta">
                                    <div class="ref-show-cust-name">{{ c.customer_name }}</div>
                                    <div class="ref-show-cust-sub">
                                        <template v-if="c.customer_city">{{ c.customer_city }} · </template>
                                        Attributed {{ c.attributed_at }}
                                    </div>
                                    <div v-if="c.products.length" class="ref-show-cust-products">
                                        <span
                                            v-for="(p, i) in c.products"
                                            :key="i"
                                            class="ref-show-cust-product-badge"
                                            :style="productBadgeStyle(p)"
                                        >{{ p.name }}<template v-if="p.status === 'trial'"> · trial</template></span>
                                    </div>
                                </div>
                                <div class="ref-show-cust-commission">{{ gbp(c.total_commission) }}</div>
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ EDIT SLIDE-OVER ═══ -->
        <TransitionRoot as="template" :show="showEdit">
            <Dialog as="div" class="slide-over-dialog" @close="showEdit = false">
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
                    <DialogPanel class="slide-over-panel" style="width: 480px;">
                        <form class="slide-over-form" @submit.prevent="submitEdit">
                            <header class="slide-over-header">
                                <h2>Edit referrer</h2>
                                <button type="button" class="icon-btn" aria-label="Close" @click="showEdit = false">
                                    <IconX :size="18" stroke-width="1.75" />
                                </button>
                            </header>
                            <div class="slide-over-body">
                                <div class="form-section">
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Full name<span class="req">*</span></label>
                                            <input v-model="editForm.name" type="text" :class="{ 'has-err': editForm.errors.name }" required>
                                            <div v-if="editForm.errors.name" class="err">{{ editForm.errors.name }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Email address<span class="req">*</span></label>
                                            <input v-model="editForm.email" type="email" :class="{ 'has-err': editForm.errors.email }" required>
                                            <div v-if="editForm.errors.email" class="err">{{ editForm.errors.email }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Commission note <span style="color: var(--text-tertiary); font-weight: 400;">(internal)</span></label>
                                            <textarea v-model="editForm.commission_note" rows="3" placeholder="e.g. updated to 7% MRR following our August renegotiation." />
                                        </div>
                                    </div>
                                </div>
                                <div class="form-section">
                                    <h3>Status</h3>
                                    <div class="status-rows">
                                        <div class="set-row">
                                            <div>
                                                <div class="nm">Active</div>
                                                <div class="sb">Inactive referrers stay on the books but can't sign in.</div>
                                            </div>
                                            <button type="button" class="toggle" :class="{ on: editForm.is_active }" aria-label="Toggle active" @click="editForm.is_active = ! editForm.is_active" />
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <footer class="slide-over-footer">
                                <button type="button" class="btn btn-secondary" @click="showEdit = false">Cancel</button>
                                <button type="submit" class="btn btn-primary" :disabled="editForm.processing">
                                    {{ editForm.processing ? 'Saving…' : 'Save changes' }}
                                </button>
                            </footer>
                        </form>
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </TransitionRoot>

        <ConfirmModal
            v-model:show="showResetModal"
            :title="`Reset password for ${referrer.name}?`"
            :message="resetMessage"
            confirm-label="Reset password"
            variant="primary"
            :loading="resetProcessing"
            @confirm="performReset"
        />

        <ConfirmModal
            v-model:show="showApproveAllModal"
            title="Approve all pending commissions?"
            :message="approveAllMessage"
            confirm-label="Approve all"
            variant="primary"
            :loading="approveAllProcessing"
            @confirm="handleApproveAll"
        />
    </InternalLayout>
</template>
