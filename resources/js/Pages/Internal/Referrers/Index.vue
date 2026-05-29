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
    IconPlus,
    IconX,
    IconDots,
    IconCheck,
    IconAlertCircle,
    IconCoins,
    IconCircleCheck,
    IconChevronLeft,
    IconChevronRight,
    IconStarFilled,
    IconRotateClockwise,
    IconStar,
    IconReceipt,
    IconCopy,
    IconUser,
    IconFlag,
    IconDownload,
    IconArrowsSort,
    IconArrowDown,
    IconArrowRight,
    IconChevronDown,
    IconEye,
    IconKey,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const props = defineProps({
    referrers: { type: Array, default: () => [] },
    ledger: { type: Object, required: true },
    pending_total: { type: Number, default: 0 },
    pending_breakdown: { type: Array, default: () => [] },
    all_time_total: { type: Number, default: 0 },
    total_customers: { type: Number, default: 0 },
    pending_count: { type: Number, default: 0 },
});

const breadcrumbs = [{ label: 'Referrers' }];

/* ─── Money helpers ─── */
function gbp(n) {
    return new Intl.NumberFormat('en-GB', {
        style: 'currency',
        currency: 'GBP',
        minimumFractionDigits: 2,
    }).format(Number(n || 0));
}
function gbpRound(n) {
    return new Intl.NumberFormat('en-GB', {
        style: 'currency',
        currency: 'GBP',
        maximumFractionDigits: 0,
    }).format(Number(n || 0));
}

/* ─── Date helpers ─── */
function formatDate(iso) {
    if (! iso) return '—';
    return new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}
function formatRelativeDate(iso) {
    if (! iso) return '—';
    const d = new Date(iso);
    const now = new Date();
    const sameDay = d.toDateString() === now.toDateString();
    if (sameDay) return 'Today';
    return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}
function memberSinceLabel(iso) {
    if (! iso) return null;
    return 'Member since ' + new Date(iso).toLocaleDateString('en-GB', { month: 'short', year: 'numeric' });
}

/* ─── Avatar helpers ─── */
function initials(name) {
    const parts = String(name || '').trim().split(/\s+/);
    return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || '?';
}
function avatarStyle(c) {
    return { background: c || '#64748B', color: '#fff' };
}

/* ─── Product chip helpers ─── */
const PRODUCT_CHIP_CLASS = {
    maavelus: 'maa',
    myorderpad: 'opd',
    whitedash_b2b: 'b2b',
    smscube: 'sms',
};
function productChipClass(slug) {
    return 'prod-chip ' + (PRODUCT_CHIP_CLASS[slug] || 'neutral');
}
function productMark(name) {
    return String(name || '?').trim().charAt(0).toUpperCase();
}

/* ─── Ledger icon by trigger ─── */
const LEDGER_ICON = {
    monthly_recurring: { cls: 'info', icon: IconRotateClockwise },
    onboarding: { cls: 'warn', icon: IconStar },
    invoice_paid: { cls: 'success', icon: IconReceipt },
};
function ledgerIcon(entry) {
    if (entry.status === 'paid') return { cls: 'success', icon: IconCircleCheck };
    return LEDGER_ICON[entry.trigger_type] || { cls: 'neutral', icon: IconRotateClockwise };
}

/* ─── Status badge ─── */
const STATUS_BADGE = {
    pending: { label: 'Pending', cls: 'badge-pending', sub: 'Awaiting approval' },
    approved: { label: 'Approved', cls: 'badge-active', sub: null },
    paid: { label: 'Paid', cls: 'badge-active', sub: null },
    voided: { label: 'Voided', cls: 'badge-inactive', sub: null },
};
function ledgerStatusBadge(entry) {
    const base = STATUS_BADGE[entry.status] || STATUS_BADGE.pending;
    if (entry.status === 'paid' && entry.paid_at) {
        return { ...base, sub: 'Paid ' + new Date(entry.paid_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }) };
    }
    return base;
}

/* ─── Add referrer slide-over ─── */
const showAddReferrer = ref(false);
const referrerForm = useForm({ name: '', email: '', commission_note: '' });

function openAddReferrer() {
    referrerForm.reset();
    referrerForm.clearErrors();
    showAddReferrer.value = true;
}
function submitAddReferrer() {
    referrerForm.post('/referrers', {
        preserveScroll: true,
        onSuccess: () => { showAddReferrer.value = false; },
    });
}

/* ─── Copy temp password ─── */
const copyState = ref('idle');
function copyTempPassword() {
    const pw = page.props?.flash?.temp_password;
    if (! pw || ! navigator.clipboard) return;
    navigator.clipboard.writeText(pw).then(() => {
        copyState.value = 'copied';
        setTimeout(() => { copyState.value = 'idle'; }, 1800);
    });
}

/* ─── Approve / mark-paid ─── */
function approveOne(entry) {
    router.post(`/referrers/${entry.id}/approve`, {}, { preserveScroll: true });
}
function markPaid(entry) {
    router.post(`/referrers/${entry.id}/mark-paid`, {}, { preserveScroll: true });
}
function approveForReferrer(referrer) {
    router.post('/referrers/approve-all', { referrer_id: referrer.id }, { preserveScroll: true });
}

/* ─── Edit referrer slide-over ─── */
const showEditReferrer = ref(false);
const editingReferrerId = ref(null);
const editReferrerForm = useForm({
    name: '',
    email: '',
    commission_note: '',
    is_active: true,
});

function openEditReferrer(r) {
    editingReferrerId.value = r.id;
    editReferrerForm.reset();
    editReferrerForm.clearErrors();
    editReferrerForm.name = r.name ?? '';
    editReferrerForm.email = r.email ?? '';
    editReferrerForm.commission_note = '';
    editReferrerForm.is_active = r.is_active ?? true;
    showEditReferrer.value = true;
}

function submitEditReferrer() {
    if (! editingReferrerId.value) return;
    editReferrerForm.put(`/referrers/${editingReferrerId.value}`, {
        preserveScroll: true,
        onSuccess: () => {
            showEditReferrer.value = false;
            editingReferrerId.value = null;
        },
    });
}

/* ─── Preview referrer portal (impersonation) ───
 *
 * We use fetch instead of router.post here because the impersonation
 * endpoint returns JSON (a URL), not an Inertia redirect — router.post
 * would try to follow the response as a page nav. The CSRF token is
 * pulled from the meta tag the blade root layout writes.
 */
async function openReferrerPreview(id) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
    try {
        const res = await fetch(`/impersonate/referrer/${id}`, {
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
            window.alert(data?.error ?? `Preview failed (HTTP ${res.status}).`);

            return;
        }
        if (data?.url) {
            window.open(data.url, '_blank', 'noopener');
        }
    } catch (e) {
        window.alert('Could not open preview.');
    }
}

/* ─── Reset password (ConfirmModal → credentials flash) ─── */
const showResetPasswordModal = ref(false);
const resetPasswordTarget = ref(null);
const resetPasswordProcessing = ref(false);

function askResetPassword(r) {
    resetPasswordTarget.value = r;
    showResetPasswordModal.value = true;
}

function performResetPassword() {
    if (! resetPasswordTarget.value) return;
    resetPasswordProcessing.value = true;
    router.post(
        `/referrers/${resetPasswordTarget.value.id}/reset-password`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                resetPasswordProcessing.value = false;
                showResetPasswordModal.value = false;
                resetPasswordTarget.value = null;
            },
        },
    );
}

const resetPasswordMessage = computed(() =>
    resetPasswordTarget.value
        ? `A new temporary password will be generated for ${resetPasswordTarget.value.name}. Share it securely — it won't be shown again.`
        : '',
);

/* ─── Approve-all confirm ─── */
const showApproveAllModal = ref(false);
const approveAllProcessing = ref(false);
function askApproveAll() {
    if (props.pending_count === 0) return;
    showApproveAllModal.value = true;
}
function handleApproveAll() {
    approveAllProcessing.value = true;
    router.post('/referrers/approve-all', {}, {
        preserveScroll: true,
        onFinish: () => {
            approveAllProcessing.value = false;
            showApproveAllModal.value = false;
        },
    });
}

const approveAllMessage = computed(() => {
    return `This will approve all ${props.pending_count} pending commission ${props.pending_count === 1 ? 'entry' : 'entries'} totalling ${gbp(props.pending_total)}. Each referrer will need to be paid separately.`;
});

// Login URL shown in the credentials card. Resolved at render time
// from window.location so the URL matches whichever host the operator
// is currently viewing (works for both powerhouse.test and prod).
const loginUrl = typeof window !== 'undefined'
    ? `${window.location.protocol}//${window.location.host}/login`
    : '/login';

const subhead = computed(() => {
    const r = props.referrers.length;
    return `${r} active ${r === 1 ? 'referrer' : 'referrers'} · ${gbpRound(props.pending_total)} pending payout · ${props.total_customers} referred customers across all products`;
});

const page = usePage();
</script>

<template>
    <Head title="Referrers" />

    <InternalLayout title="Referrers" :breadcrumbs="breadcrumbs" active-nav="referrers">
        <template #topbar-actions>
            <button type="button" class="btn btn-primary" @click="openAddReferrer">
                <IconPlus :size="15" stroke-width="1.75" />
                Add referrer
            </button>
        </template>

        <div class="referrers">
            <!-- Greeting -->
            <div class="greet">
                <div>
                    <h1>Referrers</h1>
                    <div class="sub">{{ subhead }}</div>
                </div>
            </div>

            <!-- Flash banners -->
            <div
                v-if="page.props.flash?.success"
                style="margin-top: 14px; padding: 10px 14px; background: var(--success-bg); color: #047857; border: 1px solid #A7F3D0; border-radius: var(--radius-md); font: 500 13px/1.4 'Inter', sans-serif; display: flex; align-items: center; gap: 8px;"
            >
                <IconCheck :size="16" stroke-width="2" />{{ page.props.flash.success }}
            </div>
            <div
                v-if="page.props.flash?.error"
                style="margin-top: 14px; padding: 10px 14px; background: var(--danger-bg); color: var(--danger); border: 1px solid #FECACA; border-radius: var(--radius-md); font: 500 13px/1.4 'Inter', sans-serif; display: flex; align-items: center; gap: 8px;"
            >
                <IconAlertCircle :size="16" stroke-width="2" />{{ page.props.flash.error }}
            </div>
            <div v-if="page.props.flash?.temp_password" class="temp-pw-card" style="margin-top: 14px;">
                <div class="hd">
                    <IconKey :size="14" stroke-width="2" style="vertical-align: -2px;" />
                    Credentials for <strong>{{ page.props.flash.temp_password_name || 'referrer' }}</strong>
                    — share securely, they won't be shown again.
                </div>
                <div class="pw-row" style="flex-wrap: wrap;">
                    <div style="display: flex; flex-direction: column; gap: 4px; min-width: 0;">
                        <div style="font: 500 11px/1 'Inter', sans-serif; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .08em;">Login URL</div>
                        <code style="font: 500 13px/1.3 'JetBrains Mono', monospace;">{{ loginUrl }}</code>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 4px; min-width: 0;">
                        <div style="font: 500 11px/1 'Inter', sans-serif; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .08em;">Email</div>
                        <code style="font: 500 13px/1.3 'JetBrains Mono', monospace;">{{ page.props.flash.temp_password_email || '—' }}</code>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 4px; min-width: 0;">
                        <div style="font: 500 11px/1 'Inter', sans-serif; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .08em;">Temporary password</div>
                        <code style="font: 500 13px/1.3 'JetBrains Mono', monospace;">{{ page.props.flash.temp_password }}</code>
                    </div>
                    <button type="button" class="btn btn-secondary btn-sm" style="align-self: flex-end;" @click="copyTempPassword">
                        <IconCopy :size="13" stroke-width="1.75" />
                        {{ copyState === 'copied' ? 'Copied' : 'Copy password' }}
                    </button>
                </div>
                <div class="sb">Referrers sign in at the main Powerhouse login and are auto-redirected to their partner portal.</div>
            </div>

            <!-- Summary strip -->
            <div class="summary-strip">
                <div class="stat-pill">
                    <span class="d gold" />
                    <strong>{{ referrers.length }}</strong>
                    <span class="lbl">active referrers</span>
                </div>
                <div class="stat-pill">
                    <span class="d amber" />
                    <strong>{{ gbpRound(pending_total) }}</strong>
                    <span class="lbl">pending payout</span>
                    <span class="sub">awaiting approval</span>
                </div>
                <div class="stat-pill">
                    <span class="d green" />
                    <strong>{{ gbpRound(all_time_total) }}</strong>
                    <span class="lbl">paid out</span>
                    <span class="sub">all time</span>
                </div>
                <div class="stat-pill">
                    <span class="d blue" />
                    <strong>{{ total_customers }}</strong>
                    <span class="lbl">referred customers</span>
                    <span class="sub">currently active</span>
                </div>
            </div>

            <!-- ═══════════ SECTION 1 — REFERRERS TABLE ═══════════ -->
            <div class="table-card" style="margin-top: 24px;">
                <div class="table-card-head">
                    <div class="title">Active referrers</div>
                    <span class="badge-count">{{ referrers.length }}</span>
                    <div class="right">
                        <button type="button" class="btn btn-ghost btn-sm" style="color: var(--text-secondary);" disabled>
                            <IconArrowsSort :size="14" stroke-width="1.75" />
                            Sort: Pending
                            <IconArrowDown :size="13" stroke-width="1.75" />
                        </button>
                        <button type="button" class="btn btn-ghost btn-sm" style="color: var(--text-secondary);" disabled>
                            <IconDownload :size="14" stroke-width="1.75" />
                            Export
                        </button>
                    </div>
                </div>

                <table class="tbl">
                    <colgroup>
                        <col>
                        <col style="width: 100px;">
                        <col style="width: 200px;">
                        <col style="width: 130px;">
                        <col style="width: 130px;">
                        <col style="width: 130px;">
                        <col style="width: 150px;">
                        <col style="width: 56px;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Referrer</th>
                            <th class="num">Customers</th>
                            <th>Commission model</th>
                            <th class="num">This month</th>
                            <th class="num">Pending</th>
                            <th class="num">All time</th>
                            <th>Status</th>
                            <th />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="! referrers.length">
                            <td colspan="8" style="text-align: center; padding: 32px; color: var(--text-secondary); font-size: 13px;">
                                No referrers yet. Add one to get started.
                            </td>
                        </tr>
                        <tr v-for="r in referrers" :key="r.id">
                            <td>
                                <div class="cell-referrer">
                                    <div class="avatar" :style="avatarStyle(r.avatar_colour)">{{ initials(r.name) }}</div>
                                    <div class="ref-meta">
                                        <div class="ref-name-row">
                                            <span class="ref-name">{{ r.name }}</span>
                                            <span v-if="r.is_top" class="ref-pin" title="Top referrer">
                                                <IconStarFilled :size="11" />
                                            </span>
                                        </div>
                                        <div class="ref-email">{{ r.email }}</div>
                                        <div v-if="memberSinceLabel(r.member_since)" class="ref-since">{{ memberSinceLabel(r.member_since) }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="num">
                                <div class="big-num">{{ r.customer_count }}</div>
                                <div class="big-num-sub">/mo active</div>
                            </td>
                            <td>
                                <div class="cm-stack">
                                    <div v-if="! r.commission_models.length" class="empty">No rules configured</div>
                                    <div v-for="(m, i) in r.commission_models" :key="i" class="cm-row">
                                        <span :class="productChipClass(m.product_slug)">
                                            <span class="mark">{{ productMark(m.product_name) }}</span>
                                            {{ m.product_name || 'Product' }}
                                        </span>
                                        <span class="copy">{{ m.copy }}</span>
                                    </div>
                                </div>
                            </td>
                            <td class="num"><span class="money success">{{ gbp(r.this_month) }}</span></td>
                            <td class="num">
                                <div class="money warning">{{ gbp(r.pending_payout) }}</div>
                                <div v-if="r.pending_months > 0" class="money-sub">
                                    {{ r.pending_months }} {{ r.pending_months === 1 ? 'month' : 'months' }}
                                </div>
                            </td>
                            <td class="num"><span class="money primary">{{ gbp(r.all_time) }}</span></td>
                            <td>
                                <div class="stat-stack">
                                    <span class="badge badge-active">Active</span>
                                    <span class="stat-sub">Pays monthly</span>
                                </div>
                            </td>
                            <td>
                                <Menu as="div" class="dd-menu">
                                    <MenuButton class="icon-btn" aria-label="Actions">
                                        <IconDots :size="16" stroke-width="1.75" />
                                    </MenuButton>
                                    <MenuItems class="dd-popover right-align">
                                        <MenuItem v-slot="{ active }">
                                            <button type="button" :class="['dd-option', { active }]" @click="openReferrerPreview(r.id)">
                                                Preview referrer portal
                                            </button>
                                        </MenuItem>
                                        <MenuItem v-if="r.pending_payout > 0" v-slot="{ active }">
                                            <button type="button" :class="['dd-option', { active }]" @click="approveForReferrer(r)">
                                                Approve pending ({{ gbp(r.pending_payout) }})
                                            </button>
                                        </MenuItem>
                                        <MenuItem v-slot="{ active }">
                                            <button type="button" :class="['dd-option', { active }]" @click="openEditReferrer(r)">
                                                Edit referrer
                                            </button>
                                        </MenuItem>
                                        <MenuItem v-slot="{ active }">
                                            <button type="button" :class="['dd-option', { active }]" @click="askResetPassword(r)">
                                                Reset password
                                            </button>
                                        </MenuItem>
                                    </MenuItems>
                                </Menu>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="tbl-foot">
                    <div class="info">
                        Showing <strong>{{ referrers.length }}</strong> of <strong>{{ referrers.length }}</strong> referrers
                    </div>
                    <div class="right">
                        <button
                            type="button"
                            class="btn btn-primary btn-sm"
                            :disabled="pending_count === 0"
                            :class="{ disabled: pending_count === 0 }"
                            @click="askApproveAll"
                        >
                            <IconCircleCheck :size="14" stroke-width="1.75" />
                            Approve all pending ({{ gbpRound(pending_total) }})
                        </button>
                    </div>
                </div>
            </div>

            <!-- ═══════════ SECTION 2 — COMMISSION LEDGER ═══════════ -->
            <div class="sec-head">
                <div class="title">Commission ledger</div>
                <div class="sub">Recent entries</div>
                <div class="right">
                    <button type="button" class="dd-btn sm" disabled>
                        <IconUser :size="14" stroke-width="1.75" />
                        Filter by referrer
                        <IconChevronDown :size="14" stroke-width="1.75" class="ch" />
                    </button>
                    <button type="button" class="dd-btn sm" disabled>
                        <IconFlag :size="14" stroke-width="1.75" />
                        Filter by status
                        <IconChevronDown :size="14" stroke-width="1.75" class="ch" />
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm" style="color: var(--text-secondary);" disabled>
                        <IconDownload :size="14" stroke-width="1.75" />
                        Export CSV
                    </button>
                </div>
            </div>

            <div class="table-card">
                <table class="tbl">
                    <colgroup>
                        <col>
                        <col style="width: 160px;">
                        <col style="width: 180px;">
                        <col style="width: 120px;">
                        <col style="width: 150px;">
                        <col style="width: 130px;">
                        <col style="width: 56px;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th>Referrer</th>
                            <th>Customer</th>
                            <th class="num">Amount</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="! ledger.data.length">
                            <td colspan="7" style="text-align: center; padding: 32px; color: var(--text-secondary); font-size: 13px;">
                                No commission entries yet.
                            </td>
                        </tr>
                        <tr v-for="entry in ledger.data" :key="entry.id">
                            <td>
                                <div class="cell-desc">
                                    <div :class="['ic-circle', ledgerIcon(entry).cls]">
                                        <component :is="ledgerIcon(entry).icon" :size="18" stroke-width="1.75" />
                                    </div>
                                    <div class="desc-meta">
                                        <div class="desc-title-row">
                                            <span class="desc-title">{{ entry.description }}</span>
                                            <span v-if="entry.product" :class="productChipClass(entry.product.slug)">
                                                <span class="mark">{{ productMark(entry.product.name) }}</span>
                                                {{ entry.product.name }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div v-if="entry.referrer" class="compact-row">
                                    <div class="avatar" :style="avatarStyle(entry.referrer.avatar_colour)">{{ initials(entry.referrer.name) }}</div>
                                    <span class="nm">{{ entry.referrer.name }}</span>
                                </div>
                                <span v-else style="color: var(--text-tertiary); font-size: 13px;">—</span>
                            </td>
                            <td>
                                <div v-if="entry.customer" class="compact-row">
                                    <div class="avatar" :style="avatarStyle('#94A3B8')">{{ initials(entry.customer.name) }}</div>
                                    <span class="nm">{{ entry.customer.name }}</span>
                                </div>
                                <span v-else style="color: var(--text-tertiary); font-size: 13px;">—</span>
                            </td>
                            <td class="num"><span class="money primary">{{ gbp(entry.commission_amount) }}</span></td>
                            <td>
                                <div class="stat-stack">
                                    <span :class="['badge', ledgerStatusBadge(entry).cls]">{{ ledgerStatusBadge(entry).label }}</span>
                                    <span v-if="ledgerStatusBadge(entry).sub" class="stat-sub">{{ ledgerStatusBadge(entry).sub }}</span>
                                </div>
                            </td>
                            <td>
                                <span :class="['date-c', { today: formatRelativeDate(entry.created_at) === 'Today' }]">
                                    {{ formatRelativeDate(entry.created_at) }}
                                </span>
                            </td>
                            <td>
                                <Menu as="div" class="dd-menu">
                                    <MenuButton class="icon-btn" aria-label="Actions">
                                        <IconDots :size="16" stroke-width="1.75" />
                                    </MenuButton>
                                    <MenuItems class="dd-popover right-align">
                                        <MenuItem v-if="entry.status === 'pending'" v-slot="{ active }">
                                            <button type="button" :class="['dd-option', { active }]" @click="approveOne(entry)">
                                                Approve
                                            </button>
                                        </MenuItem>
                                        <MenuItem v-else-if="entry.status === 'approved'" v-slot="{ active }">
                                            <button type="button" :class="['dd-option', { active }]" @click="markPaid(entry)">
                                                Mark as paid
                                            </button>
                                        </MenuItem>
                                        <MenuItem v-else v-slot="{ active }">
                                            <button type="button" :class="['dd-option', { active }]" disabled style="opacity: .55; cursor: not-allowed;">
                                                View entry
                                            </button>
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
                        <Link
                            v-if="ledger.prev_page_url"
                            :href="ledger.prev_page_url"
                            class="pg-btn"
                            preserve-scroll
                        >
                            <IconChevronLeft :size="14" stroke-width="1.75" />
                            Previous
                        </Link>
                        <button v-else type="button" class="pg-btn" disabled>
                            <IconChevronLeft :size="14" stroke-width="1.75" />
                            Previous
                        </button>
                        <Link
                            v-if="ledger.next_page_url"
                            :href="ledger.next_page_url"
                            class="pg-btn"
                            preserve-scroll
                        >
                            Next
                            <IconChevronRight :size="14" stroke-width="1.75" />
                        </Link>
                        <button v-else type="button" class="pg-btn" disabled>
                            Next
                            <IconChevronRight :size="14" stroke-width="1.75" />
                        </button>
                    </div>
                </div>
            </div>

            <!-- ═══════════ PENDING PAYOUT BANNER ═══════════ -->
            <div v-if="pending_total > 0" class="payout-banner">
                <div class="ic">
                    <IconCoins :size="22" stroke-width="1.75" />
                </div>
                <div class="text">
                    <div class="hd">{{ gbp(pending_total) }} pending payout</div>
                    <div class="sb">
                        {{ pending_count }} pending {{ pending_count === 1 ? 'entry' : 'entries' }}
                        <template v-for="(b, i) in pending_breakdown" :key="b.referrer_id">
                            <span> · </span>
                            <strong>{{ b.name.split(' ')[0] }} {{ gbpRound(b.amount) }}</strong>
                        </template>
                    </div>
                </div>
                <div class="right">
                    <button type="button" class="btn btn-ghost btn-sm" style="color: var(--text-secondary);" disabled>
                        View breakdown
                        <IconArrowRight :size="14" stroke-width="1.75" />
                    </button>
                    <button type="button" class="btn btn-primary" @click="askApproveAll">
                        <IconCircleCheck :size="15" stroke-width="1.75" />
                        Approve all &amp; mark for payment
                    </button>
                </div>
            </div>
        </div>

        <!-- Add referrer slide-over -->
        <TransitionRoot as="template" :show="showAddReferrer">
            <Dialog as="div" class="slide-over-dialog" @close="showAddReferrer = false">
                <TransitionChild
                    as="template"
                    enter="transition-opacity ease-out duration-200"
                    enter-from="opacity-0"
                    enter-to="opacity-100"
                    leave="transition-opacity ease-in duration-150"
                    leave-from="opacity-100"
                    leave-to="opacity-0"
                >
                    <div class="slide-over-backdrop" />
                </TransitionChild>
                <TransitionChild
                    as="template"
                    enter="transform transition ease-out duration-200"
                    enter-from="translate-x-full"
                    enter-to="translate-x-0"
                    leave="transform transition ease-in duration-150"
                    leave-from="translate-x-0"
                    leave-to="translate-x-full"
                >
                    <DialogPanel class="slide-over-panel">
                        <form class="slide-over-form" @submit.prevent="submitAddReferrer">
                            <header class="slide-over-header">
                                <h2>Add referrer</h2>
                                <button type="button" class="icon-btn" aria-label="Close" @click="showAddReferrer = false">
                                    <IconX :size="18" stroke-width="1.75" />
                                </button>
                            </header>

                            <div class="slide-over-body">
                                <div class="form-section">
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Full name<span class="req">*</span></label>
                                            <input v-model="referrerForm.name" type="text" :class="{ 'has-err': referrerForm.errors.name }" required>
                                            <div v-if="referrerForm.errors.name" class="err">{{ referrerForm.errors.name }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Email address<span class="req">*</span></label>
                                            <input v-model="referrerForm.email" type="email" :class="{ 'has-err': referrerForm.errors.email }" required>
                                            <div v-if="referrerForm.errors.email" class="err">{{ referrerForm.errors.email }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Note <span style="color: var(--text-tertiary); font-weight: 400;">(internal)</span></label>
                                            <textarea v-model="referrerForm.commission_note" rows="3" placeholder="e.g. introduced via the Christos network — 5% MRR + £50 onboarding agreed." />
                                            <div v-if="referrerForm.errors.commission_note" class="err">{{ referrerForm.errors.commission_note }}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <footer class="slide-over-footer">
                                <button type="button" class="btn btn-secondary" @click="showAddReferrer = false">Cancel</button>
                                <button type="submit" class="btn btn-primary" :disabled="referrerForm.processing">
                                    <IconPlus :size="15" stroke-width="1.75" />
                                    {{ referrerForm.processing ? 'Adding…' : 'Add referrer' }}
                                </button>
                            </footer>
                        </form>
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </TransitionRoot>

        <ConfirmModal
            v-model:show="showApproveAllModal"
            title="Approve all pending commissions?"
            :message="approveAllMessage"
            confirm-label="Approve all"
            variant="primary"
            :loading="approveAllProcessing"
            @confirm="handleApproveAll"
        />

        <ConfirmModal
            v-model:show="showResetPasswordModal"
            :title="resetPasswordTarget ? `Reset password for ${resetPasswordTarget.name}?` : 'Reset password?'"
            :message="resetPasswordMessage"
            confirm-label="Reset password"
            variant="primary"
            :loading="resetPasswordProcessing"
            @confirm="performResetPassword"
        />

        <!-- Edit referrer slide-over -->
        <TransitionRoot as="template" :show="showEditReferrer">
            <Dialog as="div" class="slide-over-dialog" @close="showEditReferrer = false">
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
                        <form class="slide-over-form" @submit.prevent="submitEditReferrer">
                            <header class="slide-over-header">
                                <h2>Edit referrer</h2>
                                <button type="button" class="icon-btn" aria-label="Close" @click="showEditReferrer = false">
                                    <IconX :size="18" stroke-width="1.75" />
                                </button>
                            </header>
                            <div class="slide-over-body">
                                <div class="form-section">
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Full name<span class="req">*</span></label>
                                            <input v-model="editReferrerForm.name" type="text" :class="{ 'has-err': editReferrerForm.errors.name }" required>
                                            <div v-if="editReferrerForm.errors.name" class="err">{{ editReferrerForm.errors.name }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Email address<span class="req">*</span></label>
                                            <input v-model="editReferrerForm.email" type="email" :class="{ 'has-err': editReferrerForm.errors.email }" required>
                                            <div v-if="editReferrerForm.errors.email" class="err">{{ editReferrerForm.errors.email }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Commission note <span style="color: var(--text-tertiary); font-weight: 400;">(internal)</span></label>
                                            <textarea v-model="editReferrerForm.commission_note" rows="3" placeholder="e.g. updated to 7% MRR following our August renegotiation." />
                                            <div v-if="editReferrerForm.errors.commission_note" class="err">{{ editReferrerForm.errors.commission_note }}</div>
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
                                            <button type="button" class="toggle" :class="{ on: editReferrerForm.is_active }" aria-label="Toggle active" @click="editReferrerForm.is_active = ! editReferrerForm.is_active" />
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <footer class="slide-over-footer">
                                <button type="button" class="btn btn-secondary" @click="showEditReferrer = false">Cancel</button>
                                <button type="submit" class="btn btn-primary" :disabled="editReferrerForm.processing">
                                    {{ editReferrerForm.processing ? 'Saving…' : 'Save changes' }}
                                </button>
                            </footer>
                        </form>
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </TransitionRoot>
    </InternalLayout>
</template>
