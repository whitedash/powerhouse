<script setup>
/**
 * Expenses — the cost ledger landing page.
 *
 * Server hands us:
 *   - expenses: paginated, server-mapped slim payload
 *   - summary:  KPI numbers + by-category breakdown
 *   - filters:  echoed-back current filter state
 *   - projects, customers, categories, statuses
 *
 * Receipt upload sits on the create slide-over and uses the standard
 * FormData multipart pattern — useForm handles it natively.
 */
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    IconPlus, IconX, IconChevronLeft, IconChevronRight,
    IconReceipt2, IconCoins, IconDeviceDesktop, IconServer,
    IconSpeakerphone, IconBuilding, IconCar, IconTag, IconBox,
    IconAd, IconPaperclip, IconCircleCheck, IconCircleDashed,
    IconDownload, IconAlertTriangle, IconBuildingFactory2,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const props = defineProps({
    expenses: { type: Object, required: true },
    summary: { type: Object, required: true },
    filters: { type: Object, required: true },
    projects: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
});

/* ─── Category metadata ─── */
const CATEGORY_META = {
    referral_commission: { label: 'Referral commission', icon: IconCoins, tone: 'gold' },
    software:            { label: 'Software',            icon: IconDeviceDesktop, tone: 'info' },
    hosting:             { label: 'Hosting',             icon: IconServer, tone: 'teal' },
    travel:              { label: 'Travel',              icon: IconCar, tone: 'amber' },
    office:              { label: 'Office',              icon: IconBuilding, tone: 'muted' },
    marketing:           { label: 'Marketing',           icon: IconSpeakerphone, tone: 'purple' },
    advertising:         { label: 'Advertising',         icon: IconAd, tone: 'purple' },
    equipment:           { label: 'Equipment',           icon: IconBox, tone: 'muted' },
    other:               { label: 'Other',               icon: IconTag, tone: 'muted' },
};
function categoryMeta(c) { return CATEGORY_META[c] ?? CATEGORY_META.other; }
function categoryLabel(c) { return categoryMeta(c).label; }

const STATUS_LABEL = { pending: 'Pending', approved: 'Approved', paid: 'Paid' };

/* ─── Filter state (with debounce on dates) ─── */
const category = ref(props.filters.category ?? '');
const status = ref(props.filters.status ?? '');
const projectId = ref(props.filters.project_id ?? '');
const dateFrom = ref(props.filters.date_from ?? '');
const dateTo = ref(props.filters.date_to ?? '');

function navigate() {
    router.get('/expenses', {
        category: category.value || undefined,
        status: status.value || undefined,
        project_id: projectId.value || undefined,
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}
watch([category, status, projectId, dateFrom, dateTo], navigate);

function clearFilters() {
    category.value = '';
    status.value = '';
    projectId.value = '';
    dateFrom.value = '';
    dateTo.value = '';
}

/* ─── Add / Edit expense slide-over ─── */
const showForm = ref(false);
const editingId = ref(null);
const form = useForm({
    category: 'other',
    description: '',
    supplier_id: null,
    amount: '',
    vat_rate: 0,
    expense_date: new Date().toISOString().slice(0, 10),
    status: 'pending',
    is_reimbursable: false,
    project_id: null,
    customer_id: null,
    notes: '',
    receipt: null,
});

function openCreate() {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    form.expense_date = new Date().toISOString().slice(0, 10);
    showForm.value = true;
}
function openEdit(e) {
    editingId.value = e.id;
    form.category = e.category;
    form.description = e.description;
    form.supplier_id = e.supplier_id ?? null;
    form.amount = e.amount;
    form.vat_rate = e.vat_rate;
    form.expense_date = e.expense_date_raw;
    form.status = e.status;
    form.is_reimbursable = e.is_reimbursable;
    form.project_id = e.project?.id ?? null;
    form.customer_id = e.customer?.id ?? null;
    form.notes = e.notes ?? '';
    form.receipt = null;
    form.clearErrors();
    showForm.value = true;
}

/* Live VAT + total calc */
const vatAmountCalc = computed(() => {
    const a = Number(form.amount || 0);
    const r = Number(form.vat_rate || 0);
    return Math.round(a * r) / 100;
});
const totalCalc = computed(() => Math.round((Number(form.amount || 0) + vatAmountCalc.value) * 100) / 100);

/* When a supplier is picked, pull its defaults into the form: category
 * (only if not already set), VAT rate, and description (only if empty). */
function onSupplierSelected() {
    const supplier = props.suppliers.find((s) => s.id === form.supplier_id);
    if (! supplier) return;

    if (! form.category || form.category === 'other') {
        form.category = supplier.default_expense_category ?? form.category;
    }
    if (supplier.default_vat_rate !== null && supplier.default_vat_rate !== undefined) {
        form.vat_rate = Number(supplier.default_vat_rate);
    }
    if (! form.description) {
        form.description = supplier.name;
    }
}

function submit() {
    if (editingId.value) {
        form.put(`/expenses/${editingId.value}`, {
            preserveScroll: true,
            onSuccess: () => { showForm.value = false; },
        });
    } else {
        // Multipart so the receipt rides along on the same request.
        form.post('/expenses', {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => { showForm.value = false; },
        });
    }
}

/* ─── Approve / mark paid / delete ─── */
function approve(id) { router.post(`/expenses/${id}/approve`, {}, { preserveScroll: true }); }
function markPaid(id) { router.post(`/expenses/${id}/mark-paid`, {}, { preserveScroll: true }); }
const showDelete = ref(false);
const toDelete = ref(null);
function askDelete(id) { toDelete.value = id; showDelete.value = true; }
function confirmDelete() {
    if (! toDelete.value) return;
    router.delete(`/expenses/${toDelete.value}`, {
        preserveScroll: true,
        onFinish: () => { showDelete.value = false; toDelete.value = null; },
    });
}

/* ─── Pagination ─── */
function go(url) { if (url) router.visit(url, { preserveScroll: true, preserveState: true }); }

function moneyGBP(value) {
    return `£${Number(value || 0).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}
</script>

<template>
    <Head title="Expenses" />

    <InternalLayout
        title="Expenses"
        active-nav="expenses"
        :breadcrumbs="[{ label: 'Powerhouse', href: '/' }, { label: 'Expenses' }]"
    >
        <div class="expenses-list">
            <div class="page-actions">
                <button type="button" class="btn btn-primary" @click="openCreate">
                    <IconPlus :size="16" stroke-width="2" />
                    Add expense
                </button>
            </div>

            <!-- ─── Summary strip ─── -->
            <div class="summary-strip">
                <div class="stat-pill">
                    <span class="d"></span>
                    <span class="n">{{ moneyGBP(summary.total_this_month) }}</span>
                    <span class="l">This month</span>
                </div>
                <div v-if="summary.pending_approval > 0" class="stat-pill">
                    <span class="d amber"></span>
                    <span class="n">{{ moneyGBP(summary.pending_approval) }}</span>
                    <span class="l">Pending approval</span>
                </div>
                <div v-if="summary.reimbursable_outstanding > 0" class="stat-pill">
                    <span class="d"></span>
                    <span class="n">{{ moneyGBP(summary.reimbursable_outstanding) }}</span>
                    <span class="l">Reimbursable</span>
                </div>
            </div>

            <!-- ─── Filter bar ─── -->
            <div class="filter-bar exp-filters">
                <select v-model="category" class="filter-select">
                    <option value="">All categories</option>
                    <option v-for="c in categories" :key="c" :value="c">{{ categoryLabel(c) }}</option>
                </select>
                <select v-model="status" class="filter-select">
                    <option value="">All statuses</option>
                    <option v-for="s in statuses" :key="s" :value="s">{{ STATUS_LABEL[s] }}</option>
                </select>
                <select v-model="projectId" class="filter-select">
                    <option value="">All projects</option>
                    <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.title }}</option>
                </select>
                <input v-model="dateFrom" type="date" class="filter-select" />
                <span class="muted">→</span>
                <input v-model="dateTo" type="date" class="filter-select" />
                <button type="button" class="ghost-link" @click="clearFilters">
                    <IconX :size="13" stroke-width="2" />
                    Clear
                </button>
            </div>

            <!-- ─── Table ─── -->
            <div class="card">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th style="width: 110px;">Date</th>
                            <th>Description</th>
                            <th style="width: 160px;">Category</th>
                            <th style="width: 140px;">Supplier</th>
                            <th style="width: 90px;" class="num">Net</th>
                            <th style="width: 90px;" class="num">VAT</th>
                            <th style="width: 100px;" class="num">Total</th>
                            <th style="width: 100px;">Status</th>
                            <th style="width: 60px;">Receipt</th>
                            <th style="width: 50px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="e in expenses.data" :key="e.id">
                            <td>{{ e.expense_date }}</td>
                            <td>
                                <strong class="exp-desc">{{ e.description }}</strong>
                                <div v-if="e.is_reimbursable" class="muted small">Reimbursable</div>
                            </td>
                            <td>
                                <span class="cat-chip" :class="`tone-${categoryMeta(e.category).tone}`">
                                    <component :is="categoryMeta(e.category).icon" :size="13" stroke-width="2" />
                                    {{ categoryLabel(e.category) }}
                                </span>
                            </td>
                            <td>
                                <Link v-if="e.supplier_id" :href="`/suppliers?search=${encodeURIComponent(e.supplier_name)}`" class="supplier-badge">
                                    <IconBuildingFactory2 :size="13" stroke-width="2" />
                                    {{ e.supplier_name }}
                                </Link>
                                <span v-else-if="e.supplier_name" class="supplier-badge plain">
                                    <IconBuildingFactory2 :size="13" stroke-width="2" />
                                    {{ e.supplier_name }}
                                </span>
                                <span v-else class="muted">—</span>
                            </td>
                            <td class="num">{{ moneyGBP(e.amount) }}</td>
                            <td class="num muted small">{{ moneyGBP(e.vat_amount) }}</td>
                            <td class="num"><strong>{{ moneyGBP(e.total) }}</strong></td>
                            <td>
                                <span v-if="e.status === 'paid'" class="badge badge-active">Paid</span>
                                <span v-else-if="e.status === 'approved'" class="badge badge-pending">Approved</span>
                                <span v-else class="badge">Pending</span>
                            </td>
                            <td>
                                <a v-if="e.has_receipt" :href="`/expenses/${e.id}/receipt`" class="receipt-link" :title="e.receipt_name">
                                    <IconPaperclip :size="15" stroke-width="2" />
                                </a>
                                <span v-else class="muted">—</span>
                            </td>
                            <td>
                                <div class="row-actions">
                                    <button v-if="e.status === 'pending'" type="button" class="icon-btn xs" title="Approve" @click="approve(e.id)">
                                        <IconCircleCheck :size="14" stroke-width="2" />
                                    </button>
                                    <button v-if="e.status === 'approved'" type="button" class="icon-btn xs" title="Mark paid" @click="markPaid(e.id)">
                                        <IconCoins :size="14" stroke-width="2" />
                                    </button>
                                    <button type="button" class="icon-btn xs" title="Edit" @click="openEdit(e)">
                                        <IconCircleDashed :size="14" stroke-width="2" />
                                    </button>
                                    <button type="button" class="icon-btn xs danger" title="Delete" @click="askDelete(e.id)">
                                        <IconX :size="14" stroke-width="2" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="expenses.data.length === 0">
                            <td colspan="10" class="muted center">
                                No expenses found. <button class="ghost-link inline" @click="openCreate">Add the first one</button>.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ─── Pagination ─── -->
            <div v-if="expenses.data.length > 0" class="pg-foot">
                <span class="pg-info">
                    Showing <strong>{{ expenses.from }}–{{ expenses.to }}</strong> of <strong>{{ expenses.total }}</strong>
                </span>
                <div class="pg-buttons">
                    <button class="pg-btn" :disabled="!expenses.prev_page_url" @click="go(expenses.prev_page_url)">
                        <IconChevronLeft :size="14" stroke-width="2" /> Previous
                    </button>
                    <button class="pg-btn" :disabled="!expenses.next_page_url" @click="go(expenses.next_page_url)">
                        Next <IconChevronRight :size="14" stroke-width="2" />
                    </button>
                </div>
            </div>
        </div>

        <!-- ─── Add/Edit slide-over ─── -->
        <Teleport to="body">
            <div v-if="showForm" class="slide-over-overlay" @click.self="showForm = false">
                <div class="slide-over" style="width: 520px;">
                    <div class="slide-over-head">
                        <h2>{{ editingId ? 'Edit expense' : 'Add expense' }}</h2>
                        <button type="button" class="icon-btn" @click="showForm = false">
                            <IconX :size="18" stroke-width="2" />
                        </button>
                    </div>
                    <form @submit.prevent="submit" class="slide-over-body">
                        <div class="form-section">
                            <label class="form-label">Category</label>
                            <select v-model="form.category" class="form-input">
                                <option v-for="c in categories" :key="c" :value="c">{{ categoryLabel(c) }}</option>
                            </select>
                        </div>

                        <div class="form-section">
                            <label class="form-label">Description <span class="req">*</span></label>
                            <input v-model="form.description" type="text" class="form-input" required maxlength="255" placeholder="e.g. Adobe CC subscription" />
                            <p v-if="form.errors.description" class="form-error">{{ form.errors.description }}</p>
                        </div>

                        <div class="form-section">
                            <label class="form-label">Supplier</label>
                            <select v-model="form.supplier_id" class="form-input" @change="onSupplierSelected">
                                <option :value="null">Ad-hoc (no supplier)</option>
                                <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </select>
                            <p class="field-help">
                                <Link href="/suppliers" target="_blank">Manage suppliers →</Link>
                            </p>
                            <p v-if="form.errors.supplier_id" class="form-error">{{ form.errors.supplier_id }}</p>
                        </div>

                        <div class="form-row-2">
                            <div class="form-section">
                                <label class="form-label">Net amount (£)</label>
                                <input v-model="form.amount" type="number" min="0" step="0.01" class="form-input" required />
                            </div>
                            <div class="form-section">
                                <label class="form-label">VAT rate (%)</label>
                                <div class="vat-row">
                                    <button type="button" class="pill" :class="{ active: Number(form.vat_rate) === 0 }" @click="form.vat_rate = 0">0</button>
                                    <button type="button" class="pill" :class="{ active: Number(form.vat_rate) === 5 }" @click="form.vat_rate = 5">5</button>
                                    <button type="button" class="pill" :class="{ active: Number(form.vat_rate) === 20 }" @click="form.vat_rate = 20">20</button>
                                    <input v-model="form.vat_rate" type="number" min="0" max="100" step="0.01" class="form-input sm" />
                                </div>
                            </div>
                        </div>

                        <div class="exp-total-bar">
                            <span class="muted small">VAT: {{ moneyGBP(vatAmountCalc) }}</span>
                            <span class="exp-total">{{ moneyGBP(totalCalc) }}</span>
                        </div>

                        <div class="form-section">
                            <label class="form-label">Date</label>
                            <input v-model="form.expense_date" type="date" class="form-input" required />
                        </div>

                        <div class="form-row-2">
                            <div class="form-section">
                                <label class="form-label">Project</label>
                                <select v-model="form.project_id" class="form-input">
                                    <option :value="null">—</option>
                                    <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.title }}</option>
                                </select>
                            </div>
                            <div class="form-section">
                                <label class="form-label">Customer</label>
                                <select v-model="form.customer_id" class="form-input">
                                    <option :value="null">—</option>
                                    <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-section">
                            <label class="reimburse-row">
                                <input type="checkbox" v-model="form.is_reimbursable" />
                                <span>Reimbursable to a team member</span>
                            </label>
                        </div>

                        <div v-if="!editingId" class="form-section">
                            <label class="form-label">Receipt (PDF or image, max 5 MB)</label>
                            <input type="file" accept=".pdf,.jpg,.jpeg,.png" class="form-input" @change="form.receipt = $event.target.files[0]" />
                            <p v-if="form.errors.receipt" class="form-error">{{ form.errors.receipt }}</p>
                        </div>

                        <div class="form-section">
                            <label class="form-label">Notes</label>
                            <textarea v-model="form.notes" class="form-input" rows="2" maxlength="2000"></textarea>
                        </div>
                    </form>
                    <div class="slide-over-foot">
                        <button type="button" class="btn btn-ghost" @click="showForm = false">Cancel</button>
                        <button type="button" class="btn btn-primary" :disabled="form.processing" @click="submit">
                            {{ form.processing ? 'Saving…' : (editingId ? 'Save' : 'Record expense') }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <ConfirmModal
            v-model:show="showDelete"
            variant="danger"
            title="Delete expense?"
            message="This expense will be permanently removed. The receipt file will also be deleted."
            confirm-label="Delete expense"
            @confirm="confirmDelete"
        />
    </InternalLayout>
</template>
