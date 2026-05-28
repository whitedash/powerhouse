<script setup>
import { computed, nextTick, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    IconDownload,
    IconMail,
    IconCheck,
    IconReceipt,
    IconCoins,
    IconUser,
    IconArrowRight,
    IconActivity,
    IconSend,
    IconPencil,
    IconBan,
    IconCircleCheck,
    IconCircleCheckFilled,
    IconFilePlus,
    IconClock,
    IconAlertCircle,
} from '@tabler/icons-vue';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
import InternalLayout from '@/Layouts/InternalLayout.vue';

dayjs.extend(relativeTime);

const props = defineProps({
    invoice: { type: Object, required: true },
    payment_methods: { type: Array, default: () => [] },
});

const breadcrumbs = computed(() => [
    { label: 'Invoices', href: '/invoices' },
    { label: props.invoice.number },
]);

const STATUS_LABELS = {
    draft: 'Draft',
    sent: 'Outstanding',
    paid: 'Paid',
    overdue: 'Overdue',
    void: 'Void',
};

const STATUS_BADGE_CLASS = {
    draft: 'badge-inactive',
    sent: 'badge-pending',
    paid: 'badge-active',
    overdue: 'badge-overdue',
    void: 'badge-inactive',
};

const PAYMENT_METHOD_LABELS = {
    bank_transfer: 'Bank transfer',
    card: 'Card',
    direct_debit: 'Direct debit',
    other: 'Other',
};

const ACTION_LABELS = {
    'invoice.created': 'Invoice created',
    'invoice.sent': 'Invoice sent to customer',
    'invoice.marked_paid': 'Payment recorded',
    'invoice.voided': 'Invoice voided',
    'invoice.updated': 'Invoice updated',
};

const inv = computed(() => props.invoice);
const be = computed(() => props.invoice.billing_entity);
const customer = computed(() => props.invoice.customer);
const isDueToday = computed(() => inv.value.due_date && dayjs(inv.value.due_date).isSame(dayjs(), 'day'));

/* ─── Formatting ─── */
function formatGBP(value) {
    const n = Number(value || 0);
    return '£' + n.toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatDate(iso) {
    if (!iso) return '—';
    return dayjs(iso).format('D MMM YYYY');
}

function timeAgo(iso) {
    if (!iso) return '';
    return dayjs(iso).fromNow();
}

function formatQuantity(q) {
    return Number(q).toLocaleString('en-GB', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
}

function customerInitials(name) {
    const parts = String(name || '').trim().split(/\s+/);
    return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || '?';
}

function customerAvatarClass(id) {
    const palette = ['av-1', 'av-2', 'av-3', 'av-5', 'av-teal', 'av-amber', 'av-navy'];
    return palette[(id ?? 0) % palette.length];
}

function entityAddressLines(address) {
    if (!address) return [];
    if (typeof address === 'string') return [address];
    if (Array.isArray(address)) return address;
    const order = ['line1', 'street', 'address_line1', 'address_line2', 'line2', 'city', 'postcode', 'country'];
    const out = [];
    order.forEach((k) => {
        if (address[k]) out.push(address[k]);
    });
    if (out.length === 0) {
        // unknown shape — just dump values
        Object.values(address).forEach((v) => { if (typeof v === 'string' && v) out.push(v); });
    }
    return out;
}

function customerAddressLines() {
    const c = customer.value;
    if (!c) return [];
    const lines = [];
    if (c.address_line1) lines.push(c.address_line1);
    if (c.address_line2) lines.push(c.address_line2);
    const cityLine = [c.city, c.postcode].filter(Boolean).join(' ');
    if (cityLine) lines.push(cityLine);
    if (c.country && c.country.length > 2) lines.push(c.country);
    return lines;
}

/* ─── Status badge label (with days overdue inline) ─── */
const documentStatusLabel = computed(() => {
    if (inv.value.status === 'overdue') {
        return inv.value.days_overdue
            ? `Overdue · ${inv.value.days_overdue} days`
            : 'Overdue';
    }
    return STATUS_LABELS[inv.value.status];
});

const documentStatusBadgeClass = computed(() => STATUS_BADGE_CLASS[inv.value.status] ?? 'badge-inactive');

const dueDateClass = computed(() => {
    if (inv.value.status === 'overdue') return 'val-danger';
    if (isDueToday.value) return 'val-warn';
    return 'val-strong';
});

const showPaymentDetails = computed(() => !['paid', 'void'].includes(inv.value.status));
const showRecordPaymentCard = computed(() => ['sent', 'overdue'].includes(inv.value.status));
const showMarkPaidInTopbar = computed(() => ['sent', 'overdue'].includes(inv.value.status));

/* ─── Record payment form ─── */
const recordPaymentRef = ref(null);
const paymentForm = useForm({
    amount_received: Number(inv.value.amount_due ?? inv.value.total).toFixed(2),
    payment_date: dayjs().format('YYYY-MM-DD'),
    payment_method: 'bank_transfer',
    reference: '',
});

function scrollToRecord() {
    nextTick(() => {
        recordPaymentRef.value?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        recordPaymentRef.value?.querySelector('input')?.focus({ preventScroll: true });
    });
}

function submitPayment() {
    paymentForm.post(`/invoices/${inv.value.id}/mark-paid`, {
        preserveScroll: true,
    });
}

/* ─── Other actions ─── */
function sendInvoice() {
    router.post(`/invoices/${inv.value.id}/send`, {}, { preserveScroll: true });
}

function voidInvoice() {
    if (!confirm(`Void invoice ${inv.value.number}? This cannot be undone.`)) return;
    router.post(`/invoices/${inv.value.id}/void`, {}, { preserveScroll: true });
}

function sendReminder() {
    // Reuses the send endpoint until a dedicated reminder flow ships
    router.post(`/invoices/${inv.value.id}/send`, {}, { preserveScroll: true });
}

function downloadPdf() {
    // Stub for future Postmark/PDF sprint
}

function gotoCustomer() {
    if (customer.value) router.visit(`/customers/${customer.value.id}`);
}

function gotoEdit() {
    // Stub — invoice edit form lands in a later sprint
}

/* ─── Activity row icon / colour mapping ─── */
function activityIcon(action) {
    return {
        'invoice.sent': 'IconSend',
        'invoice.marked_paid': 'IconCircleCheck',
        'invoice.voided': 'IconBan',
        'invoice.created': 'IconFilePlus',
    }[action] || 'IconClock';
}

function activityIconClass(action) {
    return {
        'invoice.sent': 'blue',
        'invoice.marked_paid': 'green',
        'invoice.voided': 'grey',
        'invoice.created': 'grey',
        'invoice.updated': 'grey',
    }[action] || 'grey';
}

function activityLabel(action) {
    return ACTION_LABELS[action] ?? action;
}

const icons = {
    IconSend, IconCircleCheck, IconBan, IconFilePlus, IconClock,
};
</script>

<template>
    <Head :title="`Invoice ${invoice.number}`" />

    <InternalLayout :title="invoice.number" :breadcrumbs="breadcrumbs" active-nav="invoices">
        <template #topbar-actions>
            <button type="button" class="btn btn-secondary" @click="downloadPdf">
                <IconDownload :size="15" stroke-width="1.75" />
                Download PDF
            </button>
            <button
                v-if="['sent', 'overdue'].includes(invoice.status)"
                type="button"
                class="btn btn-secondary"
                :class="{ warn: invoice.status === 'overdue' }"
                @click="sendReminder"
            >
                <IconMail :size="15" stroke-width="1.75" />
                Send reminder
            </button>
            <button
                v-if="showMarkPaidInTopbar"
                type="button"
                class="btn btn-primary"
                @click="scrollToRecord"
            >
                <IconCheck :size="15" stroke-width="1.75" />
                Mark as paid
            </button>
        </template>

        <!-- Flash success banner -->
        <div
            v-if="$page.props.flash?.success"
            style="margin-bottom: 12px; padding: 10px 14px; background: var(--success-bg); color: #047857; border: 1px solid #A7F3D0; border-radius: var(--radius-md); font: 500 13px/1.4 'Inter', sans-serif; display: flex; align-items: center; gap: 8px;"
        >
            <IconCheck :size="16" stroke-width="2" />
            {{ $page.props.flash.success }}
        </div>
        <div
            v-if="$page.props.flash?.error"
            style="margin-bottom: 12px; padding: 10px 14px; background: var(--danger-bg); color: var(--danger); border: 1px solid #FECACA; border-radius: var(--radius-md); font: 500 13px/1.4 'Inter', sans-serif; display: flex; align-items: center; gap: 8px;"
        >
            <IconAlertCircle :size="16" stroke-width="2" />
            {{ $page.props.flash.error }}
        </div>

        <div class="inv-detail">
            <div class="inv-detail-grid">
                <!-- ═══ LEFT — INVOICE DOCUMENT ═══ -->
                <div class="inv-doc">
                    <!-- Document header -->
                    <header class="inv-doc-head">
                        <div>
                            <div class="inv-doc-brand">
                                <div class="brand-mark">W</div>
                                <div class="inv-doc-brand-name">{{ be?.name || 'Whitedash' }}</div>
                            </div>
                            <div v-if="be" class="inv-doc-meta">
                                <template v-if="be.company_number">Company No. {{ be.company_number }}<br></template>
                                <template v-if="be.vat_number">VAT No. {{ be.vat_number }}</template>
                            </div>
                            <div v-if="be?.address" class="inv-doc-address">
                                <template v-for="(line, i) in entityAddressLines(be.address)" :key="i">
                                    {{ line }}<br>
                                </template>
                            </div>
                        </div>

                        <div class="inv-doc-head-right">
                            <div class="inv-doc-title">INVOICE</div>
                            <div class="inv-doc-number-mono">{{ invoice.number }}</div>
                            <span
                                class="badge inv-doc-status-big"
                                :class="documentStatusBadgeClass"
                            >{{ documentStatusLabel }}</span>
                        </div>
                    </header>

                    <!-- Bill-to / dates / payment ref -->
                    <section class="inv-doc-billto">
                        <div>
                            <div class="col-label">Bill to</div>
                            <div v-if="customer" class="col-value">
                                <strong>{{ customer.name }}</strong>
                                <div v-for="(line, i) in customerAddressLines()" :key="i">{{ line }}</div>
                                <a v-if="customer.billing_email" :href="`mailto:${customer.billing_email}`" style="margin-top: 4px; display: inline-block;">
                                    {{ customer.billing_email }}
                                </a>
                            </div>
                            <div v-else class="col-value">No customer linked</div>
                        </div>
                        <div>
                            <div class="stack">
                                <div>
                                    <div class="col-label">Invoice date</div>
                                    <div class="col-value val-strong">{{ formatDate(invoice.issue_date) }}</div>
                                </div>
                                <div>
                                    <div class="col-label">Due date</div>
                                    <div class="col-value" :class="dueDateClass">{{ formatDate(invoice.due_date) }}</div>
                                </div>
                            </div>
                        </div>
                        <div>
                            <div class="stack">
                                <div>
                                    <div class="col-label">Payment ref</div>
                                    <div class="col-value val-strong mono">{{ invoice.number }}</div>
                                </div>
                                <div>
                                    <div class="col-label">Payment terms</div>
                                    <div class="col-value">Net 14 days</div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Line items -->
                    <section class="inv-doc-lines">
                        <table>
                            <thead>
                                <tr>
                                    <th>Description</th>
                                    <th class="num">Qty</th>
                                    <th class="num">Unit price</th>
                                    <th class="num">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="line in invoice.lines" :key="line.id">
                                    <td>
                                        {{ line.description }}
                                        <div v-if="line.note" class="note">{{ line.note }}</div>
                                    </td>
                                    <td class="num">{{ formatQuantity(line.quantity) }}</td>
                                    <td class="num">{{ formatGBP(line.unit_price) }}</td>
                                    <td class="num amt">{{ formatGBP(line.amount) }}</td>
                                </tr>
                                <tr v-if="invoice.lines.length === 0">
                                    <td colspan="4" class="lines-empty">No line items added.</td>
                                </tr>
                            </tbody>
                        </table>
                    </section>

                    <!-- Totals -->
                    <section class="inv-doc-totals">
                        <div class="summary-side">
                            <template v-if="invoice.status === 'paid'">
                                <div class="paid-in-full">
                                    <IconCircleCheckFilled :size="18" stroke-width="1.75" />
                                    Paid in full
                                </div>
                            </template>
                            <template v-else-if="invoice.amount_paid > 0">
                                <div class="paid-row">Amount paid: {{ formatGBP(invoice.amount_paid) }}</div>
                                <div class="due-row">Amount due: {{ formatGBP(invoice.amount_due) }}</div>
                            </template>
                            <template v-else>
                                <div class="due-row">Amount due: {{ formatGBP(invoice.total) }}</div>
                            </template>
                        </div>
                        <div class="totals-side">
                            <div>Subtotal: {{ formatGBP(invoice.subtotal) }}</div>
                            <div>VAT ({{ Math.round(invoice.vat_rate) }}%): {{ formatGBP(invoice.vat_amount) }}</div>
                            <div class="total-divider" />
                            <div class="total-final">Total {{ formatGBP(invoice.total) }}</div>
                        </div>
                    </section>

                    <!-- Bank details (hidden when paid/void) -->
                    <section v-if="showPaymentDetails && be" class="inv-doc-bank">
                        <div class="section-label">Payment details</div>
                        <div class="bank-grid">
                            <div v-if="be.bank_name" class="bank-row">
                                <span class="k">Bank:</span>
                                <span class="v">{{ be.bank_name }}</span>
                            </div>
                            <div v-if="be.account_name" class="bank-row">
                                <span class="k">Account name:</span>
                                <span class="v">{{ be.account_name }}</span>
                            </div>
                            <div v-if="be.sort_code" class="bank-row">
                                <span class="k">Sort code:</span>
                                <span class="v mono">{{ be.sort_code }}</span>
                            </div>
                            <div v-if="be.account_number" class="bank-row">
                                <span class="k">Account no:</span>
                                <span class="v mono">{{ be.account_number }}</span>
                            </div>
                        </div>
                    </section>

                    <!-- Notes -->
                    <section v-if="invoice.notes" class="inv-doc-notes">
                        <div class="section-label">Notes</div>
                        <div class="note-body">{{ invoice.notes }}</div>
                    </section>

                    <!-- Footer -->
                    <footer class="inv-doc-foot">
                        <div v-if="be">
                            {{ be.legal_name || be.name }}<template v-if="be.company_number"> · Company No. {{ be.company_number }}</template><template v-if="be.vat_number"> · VAT {{ be.vat_number }}</template>
                        </div>
                        <div>Generated by Powerhouse · Whitedash Holdings</div>
                    </footer>
                </div>

                <!-- ═══ RIGHT — ACTION + STATUS PANEL ═══ -->
                <div class="inv-panel-col">
                    <!-- Card 1: Status -->
                    <section class="card inv-status-card" :class="`status-${invoice.status}`">
                        <header class="card-header">
                            <div class="h-icon"><IconReceipt :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Status</h3>
                                <div class="sub">Current state &amp; next actions</div>
                            </div>
                            <div class="right" style="font: 500 12px/1 'JetBrains Mono', monospace; color: var(--text-tertiary);">
                                {{ invoice.number }}
                            </div>
                        </header>

                        <div class="inv-status-body">
                            <span class="badge big-badge" :class="documentStatusBadgeClass">{{ documentStatusLabel }}</span>
                        </div>

                        <div class="inv-stat-row">
                            <span class="k">Issued</span>
                            <span class="v">{{ formatDate(invoice.issue_date) }}</span>
                        </div>
                        <div class="inv-stat-row">
                            <span class="k">Due</span>
                            <span class="v" :class="{ danger: invoice.status === 'overdue' }">{{ formatDate(invoice.due_date) }}</span>
                        </div>
                        <div v-if="invoice.status === 'overdue' && invoice.days_overdue" class="inv-stat-row">
                            <span class="k">Outstanding</span>
                            <span class="v danger">{{ invoice.days_overdue }} days</span>
                        </div>
                        <div v-if="!['paid', 'void'].includes(invoice.status)" class="inv-stat-row">
                            <span class="k">Amount due</span>
                            <span class="v amount-due">{{ formatGBP(invoice.amount_due) }}</span>
                        </div>
                        <div v-if="invoice.status === 'paid' && invoice.paid_at" class="inv-stat-row">
                            <span class="k">Paid</span>
                            <span class="v">{{ formatDate(invoice.paid_at) }}</span>
                        </div>
                        <div v-if="invoice.status === 'paid' && invoice.payment_method" class="inv-stat-row">
                            <span class="k">Method</span>
                            <span class="v">{{ PAYMENT_METHOD_LABELS[invoice.payment_method] || invoice.payment_method }}</span>
                        </div>

                        <!-- Action buttons (per-status) -->
                        <div class="inv-actions">
                            <template v-if="invoice.status === 'draft'">
                                <button type="button" class="btn btn-primary" @click="sendInvoice">
                                    <IconSend :size="15" stroke-width="1.75" />
                                    Send invoice
                                </button>
                                <button type="button" class="btn btn-secondary" @click="gotoEdit">
                                    <IconPencil :size="15" stroke-width="1.75" />
                                    Edit invoice
                                </button>
                                <button type="button" class="btn btn-secondary" @click="downloadPdf">
                                    <IconDownload :size="15" stroke-width="1.75" />
                                    Download PDF
                                </button>
                                <button type="button" class="btn btn-ghost danger" @click="voidInvoice">
                                    <IconBan :size="15" stroke-width="1.75" />
                                    Void invoice
                                </button>
                            </template>

                            <template v-else-if="['sent', 'overdue'].includes(invoice.status)">
                                <button type="button" class="btn btn-primary" @click="scrollToRecord">
                                    <IconCheck :size="15" stroke-width="1.75" />
                                    Mark as paid
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-secondary"
                                    :class="{ warn: invoice.status === 'overdue' }"
                                    @click="sendReminder"
                                >
                                    <IconMail :size="15" stroke-width="1.75" />
                                    Send reminder
                                </button>
                                <button type="button" class="btn btn-secondary" @click="downloadPdf">
                                    <IconDownload :size="15" stroke-width="1.75" />
                                    Download PDF
                                </button>
                                <button type="button" class="btn btn-ghost" @click="gotoEdit">
                                    <IconPencil :size="15" stroke-width="1.75" />
                                    Edit invoice
                                </button>
                                <button type="button" class="btn btn-ghost danger" @click="voidInvoice">
                                    <IconBan :size="15" stroke-width="1.75" />
                                    Void invoice
                                </button>
                            </template>

                            <template v-else-if="invoice.status === 'paid'">
                                <button type="button" class="btn btn-primary" @click="downloadPdf">
                                    <IconDownload :size="15" stroke-width="1.75" />
                                    Download PDF
                                </button>
                                <button type="button" class="btn btn-secondary" @click="gotoCustomer">
                                    <IconUser :size="15" stroke-width="1.75" />
                                    View customer
                                </button>
                            </template>

                            <template v-else-if="invoice.status === 'void'">
                                <button type="button" class="btn btn-secondary" @click="gotoCustomer">
                                    <IconUser :size="15" stroke-width="1.75" />
                                    View customer
                                </button>
                            </template>
                        </div>
                    </section>

                    <!-- Card 2: Record payment -->
                    <section v-if="showRecordPaymentCard" ref="recordPaymentRef" class="card">
                        <header class="card-header">
                            <div class="h-icon gold"><IconCoins :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Record payment</h3>
                                <div class="sub">Manually record a payment received outside the platform</div>
                            </div>
                        </header>

                        <form class="inv-record-body" @submit.prevent="submitPayment">
                            <div class="form-field">
                                <label>Amount received<span class="req">*</span></label>
                                <div class="input-prefix">
                                    <span class="prefix">£</span>
                                    <input
                                        v-model="paymentForm.amount_received"
                                        type="number"
                                        step="0.01"
                                        min="0.01"
                                        :max="invoice.total"
                                        required
                                        :class="{ 'has-err': paymentForm.errors.amount_received }"
                                    >
                                </div>
                                <div v-if="paymentForm.errors.amount_received" class="err">{{ paymentForm.errors.amount_received }}</div>
                            </div>

                            <div class="form-row">
                                <div class="form-field">
                                    <label>Payment date<span class="req">*</span></label>
                                    <input
                                        v-model="paymentForm.payment_date"
                                        type="date"
                                        :max="dayjs().format('YYYY-MM-DD')"
                                        required
                                        :class="{ 'has-err': paymentForm.errors.payment_date }"
                                    >
                                    <div v-if="paymentForm.errors.payment_date" class="err">{{ paymentForm.errors.payment_date }}</div>
                                </div>

                                <div class="form-field">
                                    <label>Payment method<span class="req">*</span></label>
                                    <select v-model="paymentForm.payment_method" required>
                                        <option v-for="m in payment_methods" :key="m" :value="m">
                                            {{ PAYMENT_METHOD_LABELS[m] || m }}
                                        </option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-field">
                                <label>Reference</label>
                                <input
                                    v-model="paymentForm.reference"
                                    type="text"
                                    placeholder="e.g. bank ref, cheque no."
                                    maxlength="255"
                                >
                            </div>

                            <button type="submit" class="btn btn-primary" :disabled="paymentForm.processing">
                                <IconCheck :size="15" stroke-width="1.75" />
                                {{ paymentForm.processing ? 'Recording…' : 'Record payment' }}
                            </button>

                            <div class="micro">
                                This will mark the invoice as paid and update the customer's account.
                            </div>
                        </form>
                    </section>

                    <!-- Card 3: Customer snapshot -->
                    <section v-if="customer" class="card">
                        <header class="card-header">
                            <div class="h-icon"><IconUser :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Customer</h3>
                                <div class="sub">Billing party for this invoice</div>
                            </div>
                            <div class="right">
                                <Link :href="`/customers/${customer.id}`" class="ghost-link">
                                    View full record
                                    <IconArrowRight :size="14" stroke-width="1.75" />
                                </Link>
                            </div>
                        </header>
                        <div class="inv-cust-snap">
                            <div class="avatar" :class="customerAvatarClass(customer.id)">{{ customerInitials(customer.name) }}</div>
                            <div>
                                <div class="name">{{ customer.name }}</div>
                                <div class="meta">
                                    <template v-if="customer.city">{{ customer.city }}<template v-if="customer.country">, {{ customer.country }}</template></template>
                                    <template v-else>—</template>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Card 4: Activity -->
                    <section class="card">
                        <header class="card-header">
                            <div class="h-icon"><IconActivity :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Activity</h3>
                                <div class="sub">Audit trail for this invoice</div>
                            </div>
                        </header>
                        <div v-if="invoice.activity.length">
                            <div v-for="a in invoice.activity" :key="a.id" class="act-row">
                                <div class="act-ic" :class="activityIconClass(a.action)">
                                    <component :is="icons[activityIcon(a.action)]" :size="16" stroke-width="1.75" />
                                </div>
                                <div class="act-text">{{ activityLabel(a.action) }}</div>
                                <div class="act-time">{{ timeAgo(a.created_at) }}</div>
                            </div>
                        </div>
                        <div v-else class="act-empty">
                            Invoice created · No further activity yet
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </InternalLayout>
</template>
