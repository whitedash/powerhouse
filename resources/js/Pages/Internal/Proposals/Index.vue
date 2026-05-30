<script setup>
/**
 * Proposals index — the list view + new-proposal slide-over.
 *
 * Server payload:
 *   - proposals: paginated, slim-mapped rows
 *   - summary:   draft/sent/accepted counts + accepted £ total
 *   - filters:   echoed status + search
 *   - customers, billing_entities, products, statuses: form options
 *
 * Creating a proposal posts the full line array; computeLineDiscount()
 * server-side is the only writer of `amount`, so we don't bother
 * sending discount_amount.
 */
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    IconPlus, IconSearch, IconX, IconFileDescription,
    IconChevronLeft, IconChevronRight, IconDots, IconDownload,
    IconSend, IconCheck, IconTrash,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const props = defineProps({
    proposals: { type: Object, required: true },
    summary: { type: Object, required: true },
    filters: { type: Object, required: true },
    customers: { type: Array, default: () => [] },
    billing_entities: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
});

const STATUS_BADGE = {
    draft: 'badge-inactive',
    sent: 'badge-pending',
    accepted: 'badge-active',
    rejected: 'badge-danger',
    expired: 'badge-inactive',
};
const STATUS_LABEL = {
    draft: 'Draft', sent: 'Sent', accepted: 'Accepted',
    rejected: 'Rejected', expired: 'Expired',
};

/* ─── Filters ─── */
const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
let searchTimer = null;
function onSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(navigate, 300);
}
function navigate() {
    router.get('/proposals', {
        search: search.value || undefined,
        status: status.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}
watch(status, navigate);
function clearFilters() { search.value = ''; status.value = ''; }

/* ─── New proposal slide-over ─── */
const showCreate = ref(false);
const form = useForm({
    customer_id: null,
    billing_entity_id: null,
    project_id: null,
    title: '',
    description: '',
    terms: '',
    valid_until: '',
    notes: '',
    lines: [{ description: '', note: '', quantity: 1, unit_price: 0, product_id: null, plan_id: null, discount_type: null, discount_value: 0 }],
});

function openCreate() {
    form.reset();
    form.clearErrors();
    form.lines = [{ description: '', note: '', quantity: 1, unit_price: 0, product_id: null, plan_id: null, discount_type: null, discount_value: 0 }];
    showCreate.value = true;
}

function addLine() {
    form.lines.push({ description: '', note: '', quantity: 1, unit_price: 0, product_id: null, plan_id: null, discount_type: null, discount_value: 0 });
}
function removeLine(i) {
    if (form.lines.length > 1) form.lines.splice(i, 1);
}

function lineGross(line) {
    return Math.round((Number(line.quantity || 0) * Number(line.unit_price || 0)) * 100) / 100;
}
function lineDiscount(line) {
    if (! line.discount_type || ! Number(line.discount_value)) return 0;
    const gross = lineGross(line);
    const val = Number(line.discount_value);
    const raw = line.discount_type === 'percentage' ? gross * (val / 100) : val;
    return Math.min(Math.round(raw * 100) / 100, gross);
}
function lineAmount(line) {
    return Math.round((lineGross(line) - lineDiscount(line)) * 100) / 100;
}

const selectedEntity = computed(() =>
    props.billing_entities.find(e => e.id === form.billing_entity_id) ?? null,
);
const vatRate = computed(() => {
    if (! selectedEntity.value) return 20;
    return selectedEntity.value.vat_registered ? Number(selectedEntity.value.default_vat_rate) : 0;
});
const subtotal = computed(() => form.lines.reduce((s, l) => s + lineAmount(l), 0));
const vatAmount = computed(() => Math.round(subtotal.value * (vatRate.value / 100) * 100) / 100);
const total = computed(() => Math.round((subtotal.value + vatAmount.value) * 100) / 100);

function submit() {
    form.post('/proposals', {
        preserveScroll: false,
        onSuccess: () => { showCreate.value = false; },
    });
}

/* ─── Row actions ─── */
function downloadPdf(id) { window.open(`/proposals/${id}/pdf`, '_blank'); }
function sendProposal(id) {
    router.post(`/proposals/${id}/send`, {}, { preserveScroll: true });
}
function convert(id) {
    router.post(`/proposals/${id}/convert`, {}, { preserveScroll: false });
}

/* ─── Delete confirm ─── */
const showDelete = ref(false);
const toDelete = ref(null);
function askDelete(p) {
    if (p.status !== 'draft') return;
    toDelete.value = p.id;
    showDelete.value = true;
}
function confirmDelete() {
    if (! toDelete.value) return;
    router.delete(`/proposals/${toDelete.value}`, {
        preserveScroll: true,
        onFinish: () => { showDelete.value = false; toDelete.value = null; },
    });
}

/* ─── Pagination ─── */
function go(url) { if (url) router.visit(url, { preserveScroll: true, preserveState: true }); }

function money(n) { return `£${Number(n || 0).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`; }
</script>

<template>
    <Head title="Proposals" />

    <InternalLayout
        title="Proposals"
        active-nav="proposals"
        :breadcrumbs="[{ label: 'Powerhouse', href: '/' }, { label: 'Proposals' }]"
    >
        <div class="proposals-list">
            <div class="page-actions">
                <button type="button" class="btn btn-primary" @click="openCreate">
                    <IconPlus :size="16" stroke-width="2" />
                    New proposal
                </button>
            </div>

            <!-- Summary strip -->
            <div class="summary-strip">
                <div class="stat-pill"><span class="d"></span><span class="n">{{ summary.draft }}</span><span class="l">Draft</span></div>
                <div v-if="summary.sent > 0" class="stat-pill"><span class="d amber"></span><span class="n">{{ summary.sent }}</span><span class="l">Sent</span></div>
                <div v-if="summary.accepted > 0" class="stat-pill"><span class="d green"></span><span class="n">{{ summary.accepted }}</span><span class="l">Accepted</span></div>
                <div class="stat-pill"><span class="d"></span><span class="n">{{ money(summary.total_accepted_value) }}</span><span class="l">Accepted value</span></div>
            </div>

            <!-- Filter bar -->
            <div class="filter-bar">
                <div class="filter-search">
                    <IconSearch :size="16" stroke-width="2" />
                    <input v-model="search" type="text" placeholder="Search reference, title or customer…" @input="onSearch" />
                </div>
                <div class="filter-tabs">
                    <button type="button" class="filter-tab" :class="{ active: status === '' }" @click="status = ''">All</button>
                    <button v-for="s in statuses" :key="s" type="button" class="filter-tab" :class="{ active: status === s }" @click="status = s">{{ STATUS_LABEL[s] }}</button>
                </div>
                <button v-if="search || status" type="button" class="ghost-link" @click="clearFilters">
                    <IconX :size="13" stroke-width="2" /> Clear
                </button>
            </div>

            <!-- Table -->
            <div class="card">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th style="width: 140px;">Reference</th>
                            <th>Title</th>
                            <th style="width: 160px;">Customer</th>
                            <th style="width: 100px;" class="num">Total</th>
                            <th style="width: 130px;">Status</th>
                            <th style="width: 110px;">Valid until</th>
                            <th style="width: 100px;">Sent</th>
                            <th style="width: 80px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in proposals.data" :key="p.id">
                            <td>
                                <Link :href="`/proposals/${p.id}`" class="prop-ref">{{ p.reference }}</Link>
                            </td>
                            <td>
                                <Link :href="`/proposals/${p.id}`" class="prop-title">{{ p.title }}</Link>
                            </td>
                            <td>{{ p.customer_name }}</td>
                            <td class="num"><strong>{{ money(p.total) }}</strong></td>
                            <td>
                                <span class="badge" :class="STATUS_BADGE[p.status]">{{ STATUS_LABEL[p.status] }}</span>
                                <div v-if="p.status === 'sent' && p.valid_until" class="muted small">expires {{ p.valid_until }}</div>
                            </td>
                            <td>{{ p.valid_until ?? '—' }}</td>
                            <td class="muted small">{{ p.sent_at ?? '—' }}</td>
                            <td>
                                <div class="row-actions">
                                    <button type="button" class="icon-btn xs" title="Download PDF" @click="downloadPdf(p.id)">
                                        <IconDownload :size="14" stroke-width="2" />
                                    </button>
                                    <button v-if="p.status === 'draft'" type="button" class="icon-btn xs" title="Send" @click="sendProposal(p.id)">
                                        <IconSend :size="14" stroke-width="2" />
                                    </button>
                                    <button v-if="p.status === 'accepted'" type="button" class="icon-btn xs" title="Convert to contract" @click="convert(p.id)">
                                        <IconCheck :size="14" stroke-width="2" />
                                    </button>
                                    <button v-if="p.status === 'draft'" type="button" class="icon-btn xs danger" title="Delete" @click="askDelete(p)">
                                        <IconTrash :size="14" stroke-width="2" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="proposals.data.length === 0">
                            <td colspan="8" class="muted center">
                                <IconFileDescription :size="32" stroke-width="1.5" />
                                <div>No proposals yet. <button class="ghost-link inline" @click="openCreate">Create one</button>.</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="proposals.data.length > 0" class="pg-foot">
                <span class="pg-info">Showing <strong>{{ proposals.from }}–{{ proposals.to }}</strong> of <strong>{{ proposals.total }}</strong></span>
                <div class="pg-buttons">
                    <button class="pg-btn" :disabled="!proposals.prev_page_url" @click="go(proposals.prev_page_url)">
                        <IconChevronLeft :size="14" stroke-width="2" /> Previous
                    </button>
                    <button class="pg-btn" :disabled="!proposals.next_page_url" @click="go(proposals.next_page_url)">
                        Next <IconChevronRight :size="14" stroke-width="2" />
                    </button>
                </div>
            </div>
        </div>

        <!-- New proposal slide-over -->
        <Teleport to="body">
            <div v-if="showCreate" class="slide-over-overlay" @click.self="showCreate = false">
                <div class="slide-over" style="width: 640px;">
                    <div class="slide-over-head">
                        <h2>New proposal</h2>
                        <button type="button" class="icon-btn" @click="showCreate = false">
                            <IconX :size="18" stroke-width="2" />
                        </button>
                    </div>
                    <form @submit.prevent="submit" class="slide-over-body">
                        <div class="form-section">
                            <label class="form-label">Title <span class="req">*</span></label>
                            <input v-model="form.title" type="text" class="form-input lg" required maxlength="255" placeholder="What's this proposal for?" />
                            <p v-if="form.errors.title" class="form-error">{{ form.errors.title }}</p>
                        </div>

                        <div class="form-row-2">
                            <div class="form-section">
                                <label class="form-label">Customer <span class="req">*</span></label>
                                <select v-model="form.customer_id" class="form-input" required>
                                    <option :value="null">Pick a customer…</option>
                                    <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
                                </select>
                                <p v-if="form.errors.customer_id" class="form-error">{{ form.errors.customer_id }}</p>
                            </div>
                            <div class="form-section">
                                <label class="form-label">Billing entity</label>
                                <select v-model="form.billing_entity_id" class="form-input">
                                    <option :value="null">Decide later</option>
                                    <option v-for="e in billing_entities" :key="e.id" :value="e.id">{{ e.name }}</option>
                                </select>
                                <p v-if="selectedEntity && !selectedEntity.vat_registered" class="muted small">{{ selectedEntity.name }} is not VAT registered — no VAT will be added.</p>
                            </div>
                        </div>

                        <div class="form-section">
                            <label class="form-label">Overview (optional)</label>
                            <textarea v-model="form.description" class="form-input" rows="2" maxlength="5000" placeholder="Executive summary shown at the top of the document." />
                        </div>

                        <div class="form-section">
                            <label class="form-label">Valid until</label>
                            <input v-model="form.valid_until" type="date" class="form-input" />
                        </div>

                        <div class="form-section">
                            <label class="form-label">Line items</label>
                            <div v-for="(line, i) in form.lines" :key="i" class="prop-line-row">
                                <input v-model="line.description" type="text" class="form-input" placeholder="Description" maxlength="500" />
                                <input v-model.number="line.quantity" type="number" min="0.01" step="0.01" class="form-input qty" />
                                <input v-model.number="line.unit_price" type="number" min="0" step="0.01" class="form-input price" />
                                <div class="line-amt">{{ money(lineAmount(line)) }}</div>
                                <button type="button" class="icon-btn xs danger" :disabled="form.lines.length <= 1" @click="removeLine(i)">
                                    <IconX :size="13" stroke-width="2" />
                                </button>
                            </div>
                            <button type="button" class="ghost-link" @click="addLine">
                                <IconPlus :size="13" stroke-width="2" /> Add line
                            </button>
                        </div>

                        <!-- Totals preview -->
                        <div class="prop-totals">
                            <div class="prop-totals-row"><span>Subtotal</span><strong>{{ money(subtotal) }}</strong></div>
                            <div v-if="vatRate > 0" class="prop-totals-row"><span>VAT ({{ vatRate }}%)</span><strong>{{ money(vatAmount) }}</strong></div>
                            <div class="prop-totals-row grand"><span>Total</span><strong>{{ money(total) }}</strong></div>
                        </div>

                        <div class="form-section">
                            <label class="form-label">Terms &amp; conditions (optional)</label>
                            <textarea v-model="form.terms" class="form-input" rows="3" maxlength="10000" placeholder="Shown at the bottom of the PDF." />
                        </div>

                        <div class="form-section">
                            <label class="form-label">Internal notes (not shown to customer)</label>
                            <textarea v-model="form.notes" class="form-input" rows="2" maxlength="2000" />
                        </div>
                    </form>
                    <div class="slide-over-foot">
                        <button type="button" class="btn btn-ghost" @click="showCreate = false">Cancel</button>
                        <button type="button" class="btn btn-primary" :disabled="form.processing" @click="submit">
                            {{ form.processing ? 'Creating…' : 'Create proposal' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <ConfirmModal
            v-model:show="showDelete"
            variant="danger"
            title="Delete draft proposal?"
            message="The draft will be permanently removed. Sent and accepted proposals cannot be deleted from here."
            confirm-label="Delete proposal"
            @confirm="confirmDelete"
        />
    </InternalLayout>
</template>
