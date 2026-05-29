<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
    IconDownload,
    IconEye,
    IconCircleCheck,
    IconTrash,
    IconCoins,
    IconReceipt,
    IconArrowRight,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const props = defineProps({
    statement: { type: Object, required: true },
    commissions: { type: Array, default: () => [] },
});

const breadcrumbs = computed(() => [
    { label: 'Maavelus' },
    { label: 'Statements', href: '/maavelus/statements' },
    { label: props.statement.period_label },
]);

function gbp(n, dp = 2) {
    return '£' + Number(n || 0).toLocaleString('en-GB', { minimumFractionDigits: dp, maximumFractionDigits: dp });
}
function formatDate(iso) {
    if (! iso) return '—';
    return new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}
function formatRange() {
    const s = new Date(props.statement.period_start);
    const e = new Date(props.statement.period_end);

    return `${s.getDate()}–${e.getDate()} ${e.toLocaleString('en-GB', { month: 'long' })} ${e.getFullYear()}`;
}

const isDraft = computed(() => props.statement.status === 'draft');
const isConfirmed = computed(() => props.statement.status === 'confirmed');

const totalCommissions = computed(() =>
    props.commissions.reduce((acc, c) => acc + Number(c.commission_amount || 0), 0),
);
const netRevenue = computed(() => Number(props.statement.total_fees) - totalCommissions.value);

/* ─── Confirm flow ─── */
const showConfirmModal = ref(false);
const confirmProcessing = ref(false);

function askConfirm() { showConfirmModal.value = true; }
function handleConfirm() {
    confirmProcessing.value = true;
    router.post(`/maavelus/statements/${props.statement.id}/confirm`, {}, {
        preserveScroll: false,
        onFinish: () => {
            confirmProcessing.value = false;
            showConfirmModal.value = false;
        },
    });
}

/* ─── Delete (draft only) ─── */
const showDeleteModal = ref(false);
const deleteProcessing = ref(false);
function askDelete() { showDeleteModal.value = true; }
function handleDelete() {
    deleteProcessing.value = true;
    router.delete(`/maavelus/statements/${props.statement.id}`, {
        onFinish: () => {
            deleteProcessing.value = false;
            showDeleteModal.value = false;
        },
    });
}

function previewPdf() {
    window.open(props.statement.download_url, '_blank', 'noopener');
}

const statusBadgeClass = computed(() => isConfirmed.value ? 'badge-active' : 'badge-inactive');
const statusLabel = computed(() => isConfirmed.value ? 'Confirmed' : 'Draft');

const dataSourceLabel = computed(() => props.statement.data_source === 'api'
    ? 'Maavelus Control Panel API'
    : 'Manual entry');

/* ─── Commission status badges ─── */
const COMMISSION_STATUS = {
    pending: { label: 'Pending', cls: 'badge-pending' },
    approved: { label: 'Approved', cls: 'badge-active' },
    paid: { label: 'Paid', cls: 'badge-active' },
    voided: { label: 'Voided', cls: 'badge-inactive' },
};
</script>

<template>
    <Head :title="`${statement.period_label} Statement`" />

    <InternalLayout :title="`${statement.period_label} Statement`" :breadcrumbs="breadcrumbs" active-nav="maavelus-statements">
        <template #topbar-actions>
            <template v-if="isDraft">
                <button type="button" class="btn btn-ghost danger" @click="askDelete">
                    <IconTrash :size="15" stroke-width="1.75" />
                    Delete
                </button>
                <button type="button" class="btn btn-secondary" @click="previewPdf">
                    <IconEye :size="15" stroke-width="1.75" />
                    Preview PDF
                </button>
                <button type="button" class="btn btn-primary" @click="askConfirm">
                    <IconCircleCheck :size="15" stroke-width="1.75" />
                    Confirm statement
                </button>
            </template>
            <template v-else>
                <button type="button" class="btn btn-secondary" @click="previewPdf">
                    <IconDownload :size="15" stroke-width="1.75" />
                    Download PDF
                </button>
            </template>
        </template>

        <!-- Flash banners -->
        <div
            v-if="$page.props.flash?.success"
            style="margin-bottom: 12px; padding: 10px 14px; background: var(--success-bg); color: #047857; border: 1px solid #A7F3D0; border-radius: var(--radius-md); font: 500 13px/1.4 'Inter', sans-serif;"
        >{{ $page.props.flash.success }}</div>
        <div
            v-if="$page.props.flash?.error"
            style="margin-bottom: 12px; padding: 10px 14px; background: var(--danger-bg); color: var(--danger); border: 1px solid #FECACA; border-radius: var(--radius-md); font: 500 13px/1.4 'Inter', sans-serif;"
        >{{ $page.props.flash.error }}</div>

        <div class="inv-detail">
            <div class="inv-detail-grid">
                <!-- ═══ LEFT — STATEMENT DOCUMENT ═══ -->
                <div class="inv-doc">
                    <header class="inv-doc-head">
                        <div>
                            <div class="inv-doc-brand">
                                <div class="brand-mark">M</div>
                                <div class="inv-doc-brand-name">Maavelus</div>
                            </div>
                            <div class="inv-doc-meta">Internal revenue statement</div>
                        </div>
                        <div class="inv-doc-head-right">
                            <div class="inv-doc-title">REVENUE STATEMENT</div>
                            <div class="inv-doc-number-mono">{{ statement.period_label }}</div>
                            <span class="badge inv-doc-status-big" :class="statusBadgeClass">{{ statusLabel }}</span>
                        </div>
                    </header>

                    <!-- Period band -->
                    <section style="background: var(--neutral-bg); padding: 14px 24px;">
                        <div style="display: grid; grid-template-columns: 160px 1fr; gap: 10px 14px;">
                            <span style="font: 500 11px/1.2 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary);">Period</span>
                            <span style="font: 500 13px/1.3 'Inter', sans-serif;">{{ statement.period_label }} ({{ formatRange() }})</span>
                            <span style="font: 500 11px/1.2 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary);">Data source</span>
                            <span style="font: 500 13px/1.3 'Inter', sans-serif;">{{ dataSourceLabel }}</span>
                        </div>
                    </section>

                    <!-- Restaurant fees table -->
                    <section class="inv-doc-lines">
                        <table>
                            <thead>
                                <tr>
                                    <th>Restaurant</th>
                                    <th class="num">Fees collected</th>
                                    <th class="num">Orders</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="line in statement.lines" :key="line.id">
                                    <td>{{ line.customer_name }}</td>
                                    <td class="num amt">{{ gbp(line.total_fees) }}</td>
                                    <td class="num">{{ line.order_count !== null ? line.order_count.toLocaleString('en-GB') : '—' }}</td>
                                </tr>
                                <tr v-if="statement.lines.length === 0">
                                    <td colspan="3" class="lines-empty">No restaurant lines.</td>
                                </tr>
                            </tbody>
                        </table>
                    </section>

                    <!-- Totals -->
                    <section class="inv-doc-totals">
                        <div class="summary-side">
                            <div v-if="statement.total_orders" class="paid-row">Total orders: {{ statement.total_orders.toLocaleString('en-GB') }}</div>
                            <div v-else class="paid-row" style="color: var(--text-tertiary);">Orders not recorded</div>
                        </div>
                        <div class="totals-side">
                            <div>Platform fees</div>
                            <div class="total-divider" />
                            <div class="total-final">Total {{ gbp(statement.total_fees) }}</div>
                        </div>
                    </section>

                    <!-- Commissions section -->
                    <section v-if="statement.commissions_generated" style="padding: 18px 24px; border-top: 1px solid var(--border-soft);">
                        <div class="section-label" style="margin-bottom: 12px;">Referral commissions</div>
                        <template v-if="commissions.length">
                            <table style="width: 100%; border-collapse: collapse; font: 400 13px/1.5 'Inter', sans-serif;">
                                <thead>
                                    <tr style="background: #FBFCFE; border-bottom: 1px solid var(--border-soft);">
                                        <th style="text-align: left; padding: 8px 10px; font: 500 10px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary);">Referrer</th>
                                        <th style="text-align: left; padding: 8px 10px; font: 500 10px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary);">Customer</th>
                                        <th style="text-align: right; padding: 8px 10px; font: 500 10px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary);">Gross</th>
                                        <th style="text-align: right; padding: 8px 10px; font: 500 10px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary);">Commission</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="c in commissions" :key="c.id" style="border-bottom: 1px solid var(--border-soft);">
                                        <td style="padding: 10px;">{{ c.referrer_name }}</td>
                                        <td style="padding: 10px; color: var(--text-secondary);">{{ c.customer_name }}</td>
                                        <td style="padding: 10px; text-align: right; font-variant-numeric: tabular-nums;">{{ gbp(c.gross_amount) }}</td>
                                        <td style="padding: 10px; text-align: right; font: 600 13px/1 'Inter', sans-serif; color: var(--danger); font-variant-numeric: tabular-nums;">{{ gbp(c.commission_amount) }}</td>
                                    </tr>
                                </tbody>
                            </table>

                            <!-- Net revenue panel -->
                            <div style="background: #F8FAFC; border: 1px solid var(--border); border-left: 4px solid var(--accent); padding: 14px 16px; margin-top: 18px;">
                                <div style="display: flex; justify-content: space-between; padding: 4px 0;">
                                    <span>Total platform fees</span>
                                    <span style="font-variant-numeric: tabular-nums;">{{ gbp(statement.total_fees) }}</span>
                                </div>
                                <div style="display: flex; justify-content: space-between; padding: 4px 0; color: var(--danger);">
                                    <span>Less referral commissions</span>
                                    <span style="font-variant-numeric: tabular-nums;">({{ gbp(totalCommissions) }})</span>
                                </div>
                                <div style="border-top: 1px solid var(--border-soft); margin-top: 8px; padding-top: 10px; display: flex; justify-content: space-between; font: 600 15px/1.3 'Inter', sans-serif;">
                                    <span>Net Maavelus revenue</span>
                                    <span style="color: #047857; font-variant-numeric: tabular-nums;">{{ gbp(netRevenue) }}</span>
                                </div>
                            </div>
                        </template>
                        <p v-else style="color: var(--text-tertiary); font-style: italic; padding: 10px 0;">
                            No referrer commissions for this period.
                        </p>
                    </section>

                    <!-- Notes -->
                    <section v-if="statement.notes" style="padding: 16px 24px; border-top: 1px solid var(--border-soft);">
                        <div class="section-label">Notes</div>
                        <div style="white-space: pre-wrap; font: 400 13px/1.6 'Inter', sans-serif; color: var(--text-secondary); margin-top: 6px;">{{ statement.notes }}</div>
                    </section>

                    <!-- Footer -->
                    <footer class="inv-doc-foot">
                        <div>Generated by Powerhouse · Whitedash Holdings</div>
                        <div>
                            <template v-if="isConfirmed">
                                Confirmed by {{ statement.confirmed_by_name }} on {{ formatDate(statement.confirmed_at) }}
                            </template>
                            <template v-else>
                                DRAFT — not yet confirmed
                            </template>
                        </div>
                    </footer>
                </div>

                <!-- ═══ RIGHT — STATUS PANEL ═══ -->
                <div class="inv-panel-col">
                    <!-- Status card -->
                    <section class="card inv-status-card">
                        <header class="card-header">
                            <div class="h-icon"><IconReceipt :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Status</h3>
                                <div class="sub">Current state & next actions</div>
                            </div>
                            <div class="right" style="font: 500 12px/1 'JetBrains Mono', monospace; color: var(--text-tertiary);">{{ statement.period_label }}</div>
                        </header>

                        <div class="inv-status-body">
                            <span class="badge big-badge" :class="statusBadgeClass">{{ statusLabel }}</span>
                        </div>

                        <div class="inv-stat-row">
                            <span class="k">Period</span>
                            <span class="v">{{ statement.period_label }}</span>
                        </div>
                        <div class="inv-stat-row">
                            <span class="k">Total fees</span>
                            <span class="v amount-due" style="color: var(--accent);">{{ gbp(statement.total_fees) }}</span>
                        </div>
                        <div class="inv-stat-row">
                            <span class="k">Commissions</span>
                            <span class="v" :class="{ danger: statement.commissions_generated && totalCommissions > 0 }">
                                {{ statement.commissions_generated ? gbp(totalCommissions) : 'Pending' }}
                            </span>
                        </div>
                        <div v-if="statement.commissions_generated" class="inv-stat-row">
                            <span class="k">Net revenue</span>
                            <span class="v" style="color: #047857; font-weight: 600;">{{ gbp(netRevenue) }}</span>
                        </div>
                        <div class="inv-stat-row">
                            <span class="k">Orders</span>
                            <span class="v">{{ statement.total_orders ? statement.total_orders.toLocaleString('en-GB') : 'Not recorded' }}</span>
                        </div>
                        <div class="inv-stat-row">
                            <span class="k">Created</span>
                            <span class="v">{{ formatDate(statement.created_at) }} by {{ statement.created_by_name }}</span>
                        </div>
                        <div v-if="isConfirmed" class="inv-stat-row">
                            <span class="k">Confirmed</span>
                            <span class="v">{{ formatDate(statement.confirmed_at) }} by {{ statement.confirmed_by_name }}</span>
                        </div>

                        <div class="inv-actions">
                            <template v-if="isDraft">
                                <button type="button" class="btn btn-primary" @click="askConfirm">
                                    <IconCircleCheck :size="15" stroke-width="1.75" />
                                    Confirm statement
                                </button>
                                <button type="button" class="btn btn-secondary" @click="previewPdf">
                                    <IconEye :size="15" stroke-width="1.75" />
                                    Preview PDF
                                </button>
                                <button type="button" class="btn btn-ghost danger" @click="askDelete">
                                    <IconTrash :size="15" stroke-width="1.75" />
                                    Delete
                                </button>
                            </template>
                            <template v-else>
                                <button type="button" class="btn btn-primary" @click="previewPdf">
                                    <IconDownload :size="15" stroke-width="1.75" />
                                    Download PDF
                                </button>
                            </template>
                        </div>
                    </section>

                    <!-- Commissions breakdown -->
                    <section class="card">
                        <header class="card-header">
                            <div class="h-icon gold"><IconCoins :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Commissions</h3>
                                <div class="sub">{{ statement.commissions_generated ? 'Generated' : 'Pending confirmation' }}</div>
                            </div>
                        </header>

                        <div v-if="! statement.commissions_generated" style="padding: 28px 18px; text-align: center; color: var(--text-tertiary); font-style: italic; font: 400 13px/1.5 'Inter', sans-serif;">
                            Commissions will be calculated when the statement is confirmed.
                        </div>
                        <div v-else-if="commissions.length === 0" style="padding: 28px 18px; text-align: center; color: var(--text-tertiary); font: 400 13px/1.5 'Inter', sans-serif;">
                            No referrer commissions for this period.
                        </div>
                        <div v-else>
                            <div
                                v-for="c in commissions"
                                :key="c.id"
                                style="padding: 12px 18px; border-bottom: 1px solid var(--border-soft); display: flex; justify-content: space-between; align-items: center; gap: 12px;"
                            >
                                <div style="min-width: 0;">
                                    <div style="font: 500 13px/1.3 'Inter', sans-serif;">{{ c.referrer_name }}</div>
                                    <div style="font: 400 11.5px/1.3 'Inter', sans-serif; color: var(--text-secondary); margin-top: 2px;">{{ c.customer_name }}</div>
                                </div>
                                <div style="text-align: right; white-space: nowrap;">
                                    <div style="font: 600 13.5px/1 'Inter', sans-serif; color: #047857; font-variant-numeric: tabular-nums;">{{ gbp(c.commission_amount) }}</div>
                                    <span class="badge badge-sm" :class="(COMMISSION_STATUS[c.status] || COMMISSION_STATUS.pending).cls" style="margin-top: 4px;">
                                        {{ (COMMISSION_STATUS[c.status] || COMMISSION_STATUS.pending).label }}
                                    </span>
                                </div>
                            </div>
                            <div style="padding: 11px 18px; border-top: 1px solid var(--border-soft); background: #FBFCFE; display: flex; justify-content: flex-end;">
                                <a href="/referrers" class="foot-link" style="font: 500 12.5px/1 'Inter', sans-serif; color: var(--text-primary); text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                                    View in Referrers
                                    <IconArrowRight :size="14" stroke-width="1.75" />
                                </a>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </div>

        <ConfirmModal
            v-model:show="showConfirmModal"
            :title="`Confirm ${statement.period_label} statement?`"
            message="This will lock the statement, generate referral commissions in the ledger, and create a snapshot PDF. This cannot be undone."
            confirm-label="Confirm statement"
            variant="primary"
            :loading="confirmProcessing"
            @confirm="handleConfirm"
        />

        <ConfirmModal
            v-model:show="showDeleteModal"
            :title="`Delete ${statement.period_label} statement?`"
            message="This draft statement will be permanently deleted. No commission records will be touched (those only exist on confirmed statements)."
            confirm-label="Delete statement"
            variant="danger"
            :loading="deleteProcessing"
            @confirm="handleDelete"
        />
    </InternalLayout>
</template>
