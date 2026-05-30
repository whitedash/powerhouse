<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    IconTrash,
    IconDeviceFloppy,
    IconSend,
    IconCalendar,
    IconSearch,
    IconArrowRight,
    IconPlus,
    IconCirclePlus,
    IconInfoCircle,
    IconX,
    IconEye,
    IconUser,
    IconBuilding,
    IconAdjustments,
    IconAlertCircle,
} from '@tabler/icons-vue';
import dayjs from 'dayjs';
import InternalLayout from '@/Layouts/InternalLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const props = defineProps({
    customers: { type: Array, default: () => [] },
    billing_entities: { type: Array, default: () => [] },
    next_number: { type: String, required: true },
    today: { type: String, required: true },
    default_due_date: { type: String, required: true },
    vat_rates: { type: Array, default: () => [0, 5, 20] },
    payment_terms: { type: Array, default: () => [] },
    types: { type: Array, default: () => [] },
    preselected_customer_id: { type: Number, default: null },
    invoice: { type: Object, default: null },
    mode: { type: String, default: 'create' },
});

const isEdit = computed(() => props.mode === 'edit' && props.invoice);
const pageTitle = computed(() => isEdit.value ? `Edit ${props.invoice.number}` : 'New invoice');
const breadcrumbs = computed(() => isEdit.value
    ? [
        { label: 'Invoices', href: '/invoices' },
        { label: props.invoice.number, href: `/invoices/${props.invoice.id}` },
        { label: 'Edit' },
    ]
    : [
        { label: 'Invoices', href: '/invoices' },
        { label: 'New invoice' },
    ],
);
const canSendFromEdit = computed(() => isEdit.value && props.invoice.status === 'draft');

const PAYMENT_TERM_DAYS = {
    'Net 7': 7,
    'Net 14': 14,
    'Net 30': 30,
    'Due on receipt': 0,
};

const MAX_LINES = 20;

/* ─── Form state ─── */
const initialForm = (() => {
    if (props.mode === 'edit' && props.invoice) {
        return {
            customer_id: props.invoice.customer_id ?? null,
            billing_entity_id: props.invoice.billing_entity_id ?? null,
            type: props.invoice.type ?? 'service',
            issue_date: props.invoice.issue_date ?? props.today,
            due_date: props.invoice.due_date ?? props.default_due_date,
            vat_rate: Number(props.invoice.vat_rate ?? 20),
            notes: props.invoice.notes ?? '',
            lines: (props.invoice.lines ?? []).length
                ? props.invoice.lines.map((l) => ({
                    id: l.id,
                    description: l.description ?? '',
                    note: l.note ?? '',
                    quantity: Number(l.quantity ?? 1),
                    unit_price: Number(l.unit_price ?? 0),
                }))
                : [{ description: '', note: '', quantity: 1, unit_price: 0 }],
            send_after_create: false,
        };
    }

    return {
        customer_id: props.preselected_customer_id ?? null,
        billing_entity_id: null,
        type: 'service',
        issue_date: props.today,
        due_date: props.default_due_date,
        vat_rate: 20,
        notes: '',
        lines: [
            { description: '', note: '', quantity: 1, unit_price: 0 },
        ],
        send_after_create: false,
    };
})();

const form = useForm(initialForm);

const dueDateTouched = ref(isEdit.value);
const currentPaymentTerm = ref('Net 14');

function applyPaymentTerm(term) {
    currentPaymentTerm.value = term;
    const days = PAYMENT_TERM_DAYS[term] ?? 14;
    form.due_date = dayjs(form.issue_date).add(days, 'day').format('YYYY-MM-DD');
    dueDateTouched.value = false;
}

watch(() => form.issue_date, (next, prev) => {
    if (!next || next === prev) return;
    if (dueDateTouched.value) return;
    const days = PAYMENT_TERM_DAYS[currentPaymentTerm.value] ?? 14;
    form.due_date = dayjs(next).add(days, 'day').format('YYYY-MM-DD');
});

watch(() => form.due_date, (next, prev) => {
    if (next && prev && next !== prev) {
        const expected = dayjs(form.issue_date).add(PAYMENT_TERM_DAYS[currentPaymentTerm.value] ?? 14, 'day').format('YYYY-MM-DD');
        if (next !== expected) dueDateTouched.value = true;
    }
});

/* ─── Customer selection ─── */
const customerQuery = ref('');
const recentCustomers = computed(() =>
    [...props.customers]
        .filter((c) => c.created_at)
        .sort((a, b) => dayjs(b.created_at).valueOf() - dayjs(a.created_at).valueOf())
        .slice(0, 5)
);
const filteredCustomers = computed(() => {
    const q = customerQuery.value.trim().toLowerCase();
    if (!q) return recentCustomers.value;
    return props.customers.filter((c) =>
        (c.name?.toLowerCase().includes(q)) || (c.city?.toLowerCase().includes(q))
    ).slice(0, 10);
});
const selectedCustomer = computed(() =>
    props.customers.find((c) => c.id === form.customer_id) ?? null
);
function pickCustomer(c) {
    form.customer_id = c.id;
    customerQuery.value = '';
}
function clearCustomer() {
    form.customer_id = null;
    customerQuery.value = '';
}

/* ─── Billing entity selection ─── */
const selectedEntity = computed(() =>
    props.billing_entities.find((e) => e.id === form.billing_entity_id) ?? null
);

function pickEntity(e) {
    form.billing_entity_id = e.id;
}

function entityAddressLines(address) {
    if (!address) return [];
    if (typeof address === 'string') return [address];
    if (Array.isArray(address)) return address;
    const order = ['line1', 'street', 'address_line1', 'address_line2', 'line2', 'city', 'postcode', 'country'];
    const out = [];
    order.forEach((k) => { if (address[k]) out.push(address[k]); });
    if (out.length === 0) {
        Object.values(address).forEach((v) => { if (typeof v === 'string' && v) out.push(v); });
    }
    return out;
}

function entityLegal(e) {
    if (!e) return null;
    const parts = [e.legal_name || e.name];
    if (e.company_number) parts.push(`Company No. ${e.company_number}`);
    if (e.vat_number) parts.push(`VAT ${e.vat_number}`);
    return parts.join(' · ');
}

/* ─── Line items ─── */
function addLine() {
    if (form.lines.length >= MAX_LINES) return;
    form.lines.push({ description: '', note: '', quantity: 1, unit_price: 0 });
}

function removeLine(index) {
    if (form.lines.length <= 1) return;
    form.lines.splice(index, 1);
}

function lineAmount(line) {
    const q = Number(line.quantity || 0);
    const p = Number(line.unit_price || 0);
    return Math.round(q * p * 100) / 100;
}

/* ─── Totals (live) ─── */
const subtotal = computed(() =>
    form.lines.reduce((sum, l) => sum + lineAmount(l), 0)
);
const vatAmount = computed(() => Math.round(subtotal.value * (Number(form.vat_rate) / 100) * 100) / 100);
const total = computed(() => subtotal.value + vatAmount.value);

/* ─── Display helpers ─── */
function formatGBP(value) {
    const n = Number(value || 0);
    return '£' + n.toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function customerInitials(name) {
    const parts = String(name || '').trim().split(/\s+/);
    return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || '?';
}

function customerAvatarClass(id) {
    const palette = ['av-1', 'av-2', 'av-3', 'av-5', 'av-teal', 'av-amber', 'av-navy'];
    return palette[(id ?? 0) % palette.length];
}

const dueDateIsPast = computed(() => dayjs(form.due_date).isBefore(dayjs(), 'day'));

/* ─── Validity gate for "Send invoice" ─── */
const isValid = computed(() => {
    if (!form.customer_id) return false;
    if (!form.billing_entity_id) return false;
    if (form.lines.length === 0) return false;
    return form.lines.every((l) =>
        String(l.description ?? '').trim() !== ''
        && Number(l.quantity || 0) > 0
        && Number(l.unit_price || 0) >= 0
    );
});

/* ─── Settings toggles (UI-only) ─── */
const settings = ref({
    email_customer: true,
    payment_reminder: true,
    pdf_attached: true,
    vat_invoice: true,
});

/* ─── Submission ─── */
function submitDraft() {
    form.send_after_create = false;
    if (isEdit.value) {
        form.put(`/invoices/${props.invoice.id}`, { preserveScroll: true });
    } else {
        form.post('/invoices', { preserveScroll: true });
    }
}

function submitSend() {
    if (!isValid.value) return;
    form.send_after_create = true;
    if (isEdit.value) {
        if (!canSendFromEdit.value) return;
        form.put(`/invoices/${props.invoice.id}`, { preserveScroll: true });
    } else {
        form.post('/invoices', { preserveScroll: true });
    }
}

const showDiscardModal = ref(false);

function discard() {
    showDiscardModal.value = true;
}

function handleDiscard() {
    showDiscardModal.value = false;
    if (isEdit.value) {
        router.visit(`/invoices/${props.invoice.id}`);

        return;
    }
    router.visit('/invoices');
}
</script>

<template>
    <Head :title="pageTitle" />

    <InternalLayout :title="pageTitle" :breadcrumbs="breadcrumbs" active-nav="invoices">
        <template #topbar-actions>
            <template v-if="isEdit">
                <button type="button" class="btn btn-ghost" @click="discard">
                    <IconX :size="15" stroke-width="1.75" />
                    Cancel
                </button>
                <button type="button" class="btn btn-secondary" :disabled="form.processing" @click="submitDraft">
                    <IconDeviceFloppy :size="15" stroke-width="1.75" />
                    {{ form.processing && !form.send_after_create ? 'Saving…' : 'Save changes' }}
                </button>
                <button
                    v-if="canSendFromEdit"
                    type="button"
                    class="btn btn-primary"
                    :class="{ disabled: !isValid }"
                    :disabled="!isValid || form.processing"
                    @click="submitSend"
                >
                    <IconSend :size="15" stroke-width="1.75" />
                    {{ form.processing && form.send_after_create ? 'Sending…' : 'Save & send' }}
                </button>
            </template>
            <template v-else>
                <button type="button" class="btn btn-ghost danger" @click="discard">
                    <IconTrash :size="15" stroke-width="1.75" />
                    Discard
                </button>
                <button type="button" class="btn btn-secondary" :disabled="form.processing" @click="submitDraft">
                    <IconDeviceFloppy :size="15" stroke-width="1.75" />
                    {{ form.processing && !form.send_after_create ? 'Saving…' : 'Save as draft' }}
                </button>
                <button
                    type="button"
                    class="btn btn-primary"
                    :class="{ disabled: !isValid }"
                    :disabled="!isValid || form.processing"
                    @click="submitSend"
                >
                    <IconSend :size="15" stroke-width="1.75" />
                    {{ form.processing && form.send_after_create ? 'Sending…' : 'Send invoice' }}
                </button>
            </template>
        </template>

        <div class="new-invoice">
            <!-- Flash + validation banner -->
            <div
                v-if="$page.props.flash?.error || Object.keys(form.errors).length"
                style="margin-bottom: 12px; padding: 10px 14px; background: var(--danger-bg); color: var(--danger); border: 1px solid #FECACA; border-radius: var(--radius-md); font: 500 13px/1.4 'Inter', sans-serif; display: flex; align-items: flex-start; gap: 8px;"
            >
                <IconAlertCircle :size="16" stroke-width="2" style="flex-shrink: 0; margin-top: 1px;" />
                <div>
                    <div v-if="$page.props.flash?.error">{{ $page.props.flash.error }}</div>
                    <ul v-if="Object.keys(form.errors).length" style="margin: 0; padding-left: 16px;">
                        <li v-for="(msg, field) in form.errors" :key="field">{{ msg }}</li>
                    </ul>
                </div>
            </div>

            <div class="new-invoice-content">
                <!-- ═══ LEFT — DOCUMENT ═══ -->
                <section class="doc-card">
                    <!-- Header -->
                    <div class="doc-head">
                        <div class="doc-head-left">
                            <div class="doc-brand-row">
                                <div class="doc-brand-mark">W</div>
                                <div style="flex: 1; min-width: 0;">
                                    <select
                                        v-model="form.billing_entity_id"
                                        class="entity-select"
                                        :class="{ filled: selectedEntity }"
                                        style="appearance: none; -webkit-appearance: none;"
                                    >
                                        <option :value="null" disabled>Select billing entity…</option>
                                        <option v-for="e in billing_entities" :key="e.id" :value="e.id">{{ e.name }}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="doc-addr" :class="{ placeholder: !selectedEntity }">
                                <template v-if="selectedEntity">
                                    <div v-for="(line, i) in entityAddressLines(selectedEntity.address)" :key="i" class="line">{{ line }}</div>
                                    <div v-if="!entityAddressLines(selectedEntity.address).length" class="line">(no address on file)</div>
                                </template>
                                <template v-else>
                                    <div class="line" />
                                    <div class="line" />
                                    <div class="line" />
                                </template>
                            </div>
                        </div>

                        <div class="doc-head-right">
                            <div class="doc-title">INVOICE</div>
                            <div class="doc-num">
                                {{ next_number }}
                                <template v-if="!isEdit">
                                    <IconInfoCircle :size="13" stroke-width="1.75" />
                                    <span class="tip">Auto-assigned on save</span>
                                </template>
                            </div>
                            <span
                                class="badge badge-lg"
                                :class="isEdit && invoice.status !== 'draft' ? 'badge-pending' : 'badge-inactive'"
                            >{{ isEdit ? (invoice.status.charAt(0).toUpperCase() + invoice.status.slice(1)) : 'Draft' }}</span>
                        </div>
                    </div>

                    <!-- Bill to / details -->
                    <div class="doc-meta-row">
                        <div class="meta-block">
                            <div class="meta-label">Bill to</div>
                            <template v-if="selectedCustomer">
                                <div style="font: 600 14px/1.3 'Inter', sans-serif; color: var(--text-primary);">{{ selectedCustomer.name }}</div>
                                <div v-if="selectedCustomer.city" style="font: 400 13px/1.5 'Inter', sans-serif; color: var(--text-secondary); margin-top: 4px;">
                                    {{ selectedCustomer.city }}<template v-if="selectedCustomer.country">, {{ selectedCustomer.country }}</template>
                                </div>
                                <div v-if="selectedCustomer.billing_email" style="margin-top: 6px; color: var(--accent); font: 500 13px/1.4 'Inter', sans-serif; word-break: break-all;">
                                    {{ selectedCustomer.billing_email }}
                                </div>
                                <button type="button" class="meta-link" style="margin-top: 8px;" @click="clearCustomer">
                                    Change customer<IconArrowRight :size="11" stroke-width="1.75" />
                                </button>
                            </template>
                            <template v-else>
                                <div class="meta-search">
                                    <IconSearch :size="16" stroke-width="1.75" />
                                    <input v-model="customerQuery" class="inline-edit" placeholder="Search customer…">
                                </div>
                                <button type="button" class="meta-link" @click="customerQuery = ''">
                                    or create new customer<IconArrowRight :size="11" stroke-width="1.75" />
                                </button>
                            </template>
                        </div>

                        <div class="meta-block">
                            <div class="meta-label">Invoice date</div>
                            <label class="meta-date">
                                <input v-model="form.issue_date" type="date" required>
                                <IconCalendar :size="15" stroke-width="1.75" />
                            </label>
                            <div class="meta-label sub">Due date</div>
                            <label class="meta-date">
                                <input v-model="form.due_date" type="date" required :style="dueDateIsPast ? { color: 'var(--danger)' } : {}">
                                <IconCalendar :size="15" stroke-width="1.75" />
                            </label>
                        </div>

                        <div class="meta-block">
                            <div class="meta-label">Payment terms</div>
                            <select v-model="currentPaymentTerm" class="meta-select" style="appearance: none; -webkit-appearance: none;" @change="applyPaymentTerm(currentPaymentTerm)">
                                <option v-for="term in payment_terms" :key="term" :value="term">{{ term }}</option>
                            </select>
                            <div class="meta-label sub">Payment ref</div>
                            <div class="meta-auto">{{ next_number }}</div>
                            <div class="meta-auto-note">Auto-assigned</div>
                        </div>
                    </div>

                    <!-- Line items -->
                    <div class="doc-items">
                        <div class="li-head">
                            <span>Description</span>
                            <span class="num">Qty</span>
                            <span class="num">Unit price</span>
                            <span class="num">Amount</span>
                            <span class="x" />
                        </div>

                        <div v-for="(line, idx) in form.lines" :key="idx" class="li-row">
                            <div class="li-desc">
                                <input
                                    v-model="line.description"
                                    type="text"
                                    class="inline-edit main"
                                    placeholder="Service or product description…"
                                    maxlength="500"
                                >
                                <input
                                    v-model="line.note"
                                    type="text"
                                    class="inline-edit sub"
                                    placeholder="Add a note or billing period (optional)"
                                    maxlength="500"
                                >
                            </div>
                            <div class="li-qty">
                                <input
                                    v-model.number="line.quantity"
                                    type="number"
                                    class="inline-edit center"
                                    step="0.01"
                                    min="0.001"
                                    max="9999"
                                >
                            </div>
                            <div class="li-price">
                                <span class="gbp">£</span>
                                <input
                                    v-model.number="line.unit_price"
                                    type="number"
                                    class="inline-edit"
                                    step="0.01"
                                    min="0"
                                    max="999999"
                                >
                            </div>
                            <div class="li-amt">{{ formatGBP(lineAmount(line)) }}</div>
                            <button
                                type="button"
                                class="li-x"
                                :disabled="form.lines.length <= 1"
                                :aria-label="`Remove line ${idx + 1}`"
                                @click="removeLine(idx)"
                            >
                                <IconX :size="16" stroke-width="1.75" />
                            </button>
                        </div>

                        <button
                            type="button"
                            class="li-add"
                            :disabled="form.lines.length >= MAX_LINES"
                            @click="addLine"
                        >
                            <IconPlus :size="16" stroke-width="1.75" />
                            {{ form.lines.length >= MAX_LINES ? `Maximum ${MAX_LINES} line items` : 'Add line item' }}
                        </button>
                    </div>

                    <!-- Totals -->
                    <div class="doc-totals">
                        <div class="totals-left">
                            <button type="button" class="add-discount">
                                <IconCirclePlus :size="14" stroke-width="1.75" />
                                Add discount
                            </button>
                        </div>
                        <div class="totals-grid">
                            <div class="total-row">
                                <span class="lbl">Subtotal</span>
                                <span class="val">{{ formatGBP(subtotal) }}</span>
                            </div>
                            <div class="total-row vat">
                                <span class="lbl">
                                    VAT
                                    <select v-model.number="form.vat_rate" class="vat-select" style="appearance: none; -webkit-appearance: none;">
                                        <option v-for="rate in vat_rates" :key="rate" :value="rate">{{ rate }}%</option>
                                    </select>
                                </span>
                                <span class="val">{{ formatGBP(vatAmount) }}</span>
                            </div>
                            <div class="totals-divider" />
                            <div class="total-row grand">
                                <span class="lbl">Total</span>
                                <span class="val">{{ formatGBP(total) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="doc-notes">
                        <div class="meta-label">Notes (optional)</div>
                        <textarea
                            v-model="form.notes"
                            rows="3"
                            placeholder="Add payment instructions or a personal note to your customer…"
                            maxlength="1000"
                        />
                    </div>

                    <!-- Footer -->
                    <div class="doc-foot">
                        <div class="legal" :class="{ placeholder: !selectedEntity }">
                            <template v-if="selectedEntity">{{ entityLegal(selectedEntity) }}</template>
                            <template v-else>— · Company No. — · VAT —</template>
                        </div>
                        <div class="gen">Generated by Powerhouse · Whitedash Holdings</div>
                    </div>
                </section>

                <!-- ═══ RIGHT — CONFIG ═══ -->
                <aside class="config">
                    <!-- Card 1: Send to -->
                    <section class="cfg-card shadow-md">
                        <header class="cfg-head">
                            <div class="h-ic gold"><IconUser :size="16" stroke-width="1.75" /></div>
                            <div class="h-title">Send to</div>
                            <span class="badge badge-overdue badge-sm required">Required</span>
                        </header>
                        <div class="cfg-body">
                            <div v-if="selectedCustomer" class="cust-row selected" style="cursor: default;">
                                <div class="avatar" :class="customerAvatarClass(selectedCustomer.id)">{{ customerInitials(selectedCustomer.name) }}</div>
                                <div class="meta">
                                    <div class="nm">{{ selectedCustomer.name }}</div>
                                    <div class="sm">{{ selectedCustomer.billing_email || 'No email on file' }}</div>
                                </div>
                                <button type="button" class="clear" aria-label="Clear customer" @click="clearCustomer">
                                    <IconX :size="14" stroke-width="1.75" />
                                </button>
                            </div>

                            <template v-else>
                                <div class="cust-search">
                                    <IconSearch :size="18" stroke-width="1.75" />
                                    <input v-model="customerQuery" type="search" placeholder="Search customers…">
                                </div>

                                <div class="cust-label">{{ customerQuery ? 'Matching' : 'Recent' }}</div>
                                <div class="cust-list">
                                    <button
                                        v-for="c in filteredCustomers"
                                        :key="c.id"
                                        type="button"
                                        class="cust-row"
                                        @click="pickCustomer(c)"
                                    >
                                        <div class="avatar" :class="customerAvatarClass(c.id)">{{ customerInitials(c.name) }}</div>
                                        <div class="meta">
                                            <div class="nm">{{ c.name }}</div>
                                            <div class="sm">{{ c.city || '—' }}<template v-if="c.country">, {{ c.country }}</template></div>
                                        </div>
                                    </button>
                                    <div v-if="!filteredCustomers.length" style="padding: 10px 8px; font: 400 12px/1.4 'Inter', sans-serif; color: var(--text-tertiary); font-style: italic;">
                                        No customers match.
                                    </div>
                                </div>

                                <button type="button" class="cust-create">
                                    or create new customer<IconArrowRight :size="11" stroke-width="1.75" />
                                </button>
                            </template>
                        </div>
                    </section>

                    <!-- Card 2: Invoice from -->
                    <section class="cfg-card">
                        <header class="cfg-head">
                            <div class="h-ic"><IconBuilding :size="16" stroke-width="1.75" /></div>
                            <div class="h-title">Invoice from</div>
                            <span class="badge badge-overdue badge-sm required">Required</span>
                        </header>
                        <div class="cfg-body">
                            <div class="ent-stack">
                                <button
                                    v-for="e in billing_entities"
                                    :key="e.id"
                                    type="button"
                                    class="ent-opt"
                                    :class="{ selected: form.billing_entity_id === e.id }"
                                    @click="pickEntity(e)"
                                >
                                    <div class="radio" />
                                    <div>
                                        <div class="ent-name">
                                            <span class="nm">{{ e.name }}</span>
                                            <span v-if="e.company_number" class="co">Company No. {{ e.company_number }}</span>
                                        </div>
                                        <div class="ent-use">{{ e.legal_name || e.name }}</div>
                                    </div>
                                </button>
                            </div>
                        </div>
                    </section>

                    <!-- Card 3: Settings (UI only) -->
                    <section class="cfg-card">
                        <header class="cfg-head">
                            <div class="h-ic"><IconAdjustments :size="16" stroke-width="1.75" /></div>
                            <div class="h-title">Settings</div>
                        </header>
                        <div class="cfg-body">
                            <!--
                              The Subscription/Service toggle was removed once
                              line items started carrying product links — the
                              distinction stopped affecting any downstream
                              report. form.type still ships as 'service' so the
                              column is non-null on insert; the controller
                              accepts it transparently.
                            -->

                            <div class="set-row">
                                <div>
                                    <div class="nm">Send email to customer</div>
                                    <div class="sb">Auto-sends when you click Send invoice</div>
                                </div>
                                <button type="button" class="toggle" :class="{ on: settings.email_customer }" aria-label="Toggle send email" @click="settings.email_customer = !settings.email_customer" />
                            </div>
                            <div class="set-row">
                                <div>
                                    <div class="nm">Payment reminder</div>
                                    <div class="sb">Auto-chase if unpaid after due date</div>
                                </div>
                                <button type="button" class="toggle" :class="{ on: settings.payment_reminder }" aria-label="Toggle payment reminder" @click="settings.payment_reminder = !settings.payment_reminder" />
                            </div>
                            <div class="set-row">
                                <div>
                                    <div class="nm">PDF attached to email</div>
                                    <div class="sb">Customer can download invoice PDF</div>
                                </div>
                                <button type="button" class="toggle" :class="{ on: settings.pdf_attached }" aria-label="Toggle PDF attached" @click="settings.pdf_attached = !settings.pdf_attached" />
                            </div>
                            <div class="set-row">
                                <div>
                                    <div class="nm">VAT invoice</div>
                                    <div class="sb">Show VAT breakdown on document</div>
                                </div>
                                <button type="button" class="toggle" :class="{ on: settings.vat_invoice }" aria-label="Toggle VAT invoice" @click="settings.vat_invoice = !settings.vat_invoice" />
                            </div>
                        </div>
                    </section>

                    <!-- Card 4: Send actions -->
                    <section class="cfg-card send-card">
                        <div class="cfg-body">
                            <button
                                v-if="!isEdit || canSendFromEdit"
                                type="button"
                                class="btn btn-primary send-btn"
                                :class="{ disabled: !isValid }"
                                :disabled="!isValid || form.processing"
                                @click="submitSend"
                            >
                                <IconSend :size="15" stroke-width="1.75" />
                                <template v-if="isEdit">{{ form.processing && form.send_after_create ? 'Sending…' : 'Save & send' }}</template>
                                <template v-else>{{ form.processing && form.send_after_create ? 'Sending…' : 'Send invoice' }}</template>
                            </button>
                            <div v-if="!isEdit || canSendFromEdit" class="send-divider" />

                            <button
                                type="button"
                                class="btn btn-secondary save-btn"
                                :disabled="form.processing"
                                @click="submitDraft"
                            >
                                <IconDeviceFloppy :size="15" stroke-width="1.75" />
                                <template v-if="isEdit">{{ form.processing && !form.send_after_create ? 'Saving…' : 'Save changes' }}</template>
                                <template v-else>{{ form.processing && !form.send_after_create ? 'Saving…' : 'Save as draft' }}</template>
                            </button>
                            <a
                                v-if="isEdit"
                                :href="`/invoices/${invoice.id}/preview-pdf`"
                                target="_blank"
                                rel="noopener"
                                class="preview-link"
                            >
                                <IconEye :size="15" stroke-width="1.75" />
                                Preview PDF
                            </a>
                            <button
                                v-else
                                type="button"
                                class="preview-link disabled"
                                disabled
                                title="Save the invoice first to preview PDF"
                            >
                                <IconEye :size="15" stroke-width="1.75" />
                                Preview PDF
                            </button>

                            <div v-if="!isEdit" class="send-micro">Invoice number {{ next_number }} will be assigned on save</div>
                            <div v-else class="send-micro">Editing {{ invoice.number }}</div>
                        </div>
                    </section>
                </aside>
            </div>
        </div>

        <ConfirmModal
            v-model:show="showDiscardModal"
            :title="isEdit ? 'Discard changes?' : 'Discard invoice?'"
            :message="isEdit
                ? 'All unsaved changes will be lost.'
                : 'All unsaved changes will be lost and you will be returned to the invoices list.'"
            confirm-label="Discard"
            variant="warning"
            @confirm="handleDiscard"
        />
    </InternalLayout>
</template>
