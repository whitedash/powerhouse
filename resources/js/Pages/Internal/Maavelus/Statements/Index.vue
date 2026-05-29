<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Dialog,
    DialogPanel,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import {
    IconPlus,
    IconFileInvoice,
    IconEye,
    IconDownload,
    IconTrash,
    IconX,
    IconCircleCheck,
    IconClock,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const props = defineProps({
    statements: { type: Array, default: () => [] },
    maavelus_customers: { type: Array, default: () => [] },
});

const breadcrumbs = [
    { label: 'Maavelus' },
    { label: 'Statements' },
];

/* ─── Formatting helpers ─── */
function gbp(n, dp = 2) {
    return '£' + Number(n || 0).toLocaleString('en-GB', { minimumFractionDigits: dp, maximumFractionDigits: dp });
}
function formatDate(iso) {
    if (! iso) return '—';
    return new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}

/* ─── Summary pills ─── */
const summary = computed(() => {
    const confirmed = props.statements.filter((s) => s.status === 'confirmed');
    const drafts = props.statements.filter((s) => s.status === 'draft');
    const totalFees = confirmed.reduce((acc, s) => acc + Number(s.total_fees || 0), 0);

    return { confirmedCount: confirmed.length, draftCount: drafts.length, totalFees };
});

/* ─── New-statement slide-over ─── */
const showCreate = ref(false);

const MONTHS = [
    { value: '01', label: 'January' }, { value: '02', label: 'February' },
    { value: '03', label: 'March' }, { value: '04', label: 'April' },
    { value: '05', label: 'May' }, { value: '06', label: 'June' },
    { value: '07', label: 'July' }, { value: '08', label: 'August' },
    { value: '09', label: 'September' }, { value: '10', label: 'October' },
    { value: '11', label: 'November' }, { value: '12', label: 'December' },
];

const currentYear = new Date().getFullYear();
const YEARS = [currentYear, currentYear - 1, currentYear - 2, currentYear - 3];

// Default to the previous month
function defaultPeriod() {
    const now = new Date();
    now.setDate(1);
    now.setMonth(now.getMonth() - 1);

    return {
        month: String(now.getMonth() + 1).padStart(2, '0'),
        year: now.getFullYear(),
    };
}

const form = useForm({
    month: defaultPeriod().month,
    year: defaultPeriod().year,
    total_orders: '',
    notes: '',
    lines: [
        { customer_id: null, total_fees: '', order_count: '' },
    ],
});

function addLine() {
    form.lines.push({ customer_id: null, total_fees: '', order_count: '' });
}

function removeLine(idx) {
    if (form.lines.length <= 1) return;
    form.lines.splice(idx, 1);
}

const linesTotal = computed(() =>
    form.lines.reduce((acc, l) => acc + Number(l.total_fees || 0), 0),
);

function openCreate() {
    const p = defaultPeriod();
    form.reset();
    form.clearErrors();
    form.month = p.month;
    form.year = p.year;
    form.lines = [{ customer_id: null, total_fees: '', order_count: '' }];
    showCreate.value = true;
}

function submitCreate() {
    form
        .transform((data) => ({
            period_month: `${data.year}-${data.month}`,
            total_orders: data.total_orders === '' ? null : Number(data.total_orders),
            notes: data.notes || null,
            lines: data.lines.map((l) => ({
                customer_id: l.customer_id,
                total_fees: l.total_fees,
                order_count: l.order_count === '' ? null : Number(l.order_count),
            })),
        }))
        .post('/maavelus/statements', {
            onSuccess: () => { showCreate.value = false; },
        });
}

/* ─── Delete confirmation ─── */
const showDeleteModal = ref(false);
const deleteTarget = ref(null);
const deleteProcessing = ref(false);

function askDelete(stmt) {
    deleteTarget.value = stmt;
    showDeleteModal.value = true;
}

function handleDelete() {
    if (! deleteTarget.value) return;
    deleteProcessing.value = true;
    router.delete(`/maavelus/statements/${deleteTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deleteProcessing.value = false;
            showDeleteModal.value = false;
            deleteTarget.value = null;
        },
    });
}
</script>

<template>
    <Head title="Maavelus Statements" />

    <InternalLayout title="Maavelus Statements" :breadcrumbs="breadcrumbs" active-nav="maavelus-statements">
        <template #topbar-actions>
            <button type="button" class="btn btn-primary" @click="openCreate">
                <IconPlus :size="15" stroke-width="1.75" />
                New statement
            </button>
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

        <!-- Summary pills -->
        <div class="stat-pill-row" style="display: flex; gap: 10px; margin-bottom: 18px;">
            <span class="stat-pill">
                <span class="d gold" />
                <span class="val">{{ summary.confirmedCount }}</span>
                <span class="lbl">confirmed</span>
            </span>
            <span class="stat-pill">
                <span class="d amber" />
                <span class="val">{{ summary.draftCount }}</span>
                <span class="lbl">drafts</span>
            </span>
            <span class="stat-pill">
                <span class="d green" />
                <span class="val">{{ gbp(summary.totalFees, 0) }}</span>
                <span class="lbl">total fees · confirmed</span>
            </span>
        </div>

        <!-- Statements table -->
        <div class="card" style="background: #fff; border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden;">
            <table v-if="statements.length" class="tbl" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #FBFCFE; border-bottom: 1px solid var(--border-soft);">
                        <th style="text-align: left; padding: 12px 18px; font: 500 11px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary);">Period</th>
                        <th style="text-align: right; padding: 12px 18px; font: 500 11px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary);">Fees</th>
                        <th style="text-align: right; padding: 12px 18px; font: 500 11px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary);">Orders</th>
                        <th style="text-align: left; padding: 12px 18px; font: 500 11px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary);">Status</th>
                        <th style="text-align: left; padding: 12px 18px; font: 500 11px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary);">Commissions</th>
                        <th style="text-align: left; padding: 12px 18px; font: 500 11px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary);">Confirmed</th>
                        <th style="text-align: right; padding: 12px 18px;" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="s in statements"
                        :key="s.id"
                        style="border-bottom: 1px solid var(--border-soft);"
                    >
                        <td style="padding: 14px 18px;">
                            <Link :href="`/maavelus/statements/${s.id}`" style="font: 600 14px/1.3 'Inter', sans-serif; color: var(--text-primary); text-decoration: none;">{{ s.period_label }}</Link>
                        </td>
                        <td style="padding: 14px 18px; text-align: right; font: 600 14px/1.3 'Inter', sans-serif; font-variant-numeric: tabular-nums;">{{ gbp(s.total_fees) }}</td>
                        <td style="padding: 14px 18px; text-align: right; color: var(--text-secondary); font-variant-numeric: tabular-nums;">{{ s.total_orders ? s.total_orders.toLocaleString('en-GB') : '—' }}</td>
                        <td style="padding: 14px 18px;">
                            <span class="badge" :class="s.status === 'confirmed' ? 'badge-active' : 'badge-inactive'">
                                {{ s.status === 'confirmed' ? 'Confirmed' : 'Draft' }}
                            </span>
                        </td>
                        <td style="padding: 14px 18px;">
                            <template v-if="s.status === 'confirmed' && s.commissions_generated">
                                <span class="badge badge-active badge-sm">Generated</span>
                            </template>
                            <template v-else-if="s.status === 'confirmed'">
                                <span class="badge badge-pending badge-sm">Pending</span>
                            </template>
                            <template v-else>
                                <span style="color: var(--text-tertiary); font-size: 12px;">—</span>
                            </template>
                        </td>
                        <td style="padding: 14px 18px; color: var(--text-secondary); font-size: 13px;">{{ s.confirmed_at ? formatDate(s.confirmed_at) : '—' }}</td>
                        <td style="padding: 14px 18px; text-align: right; white-space: nowrap;">
                            <Link :href="`/maavelus/statements/${s.id}`" class="ghost-link" style="margin-right: 8px;" title="View">
                                <IconEye :size="15" stroke-width="1.75" />
                            </Link>
                            <a :href="s.download_url" target="_blank" rel="noopener" class="ghost-link" style="margin-right: 8px;" title="Download PDF">
                                <IconDownload :size="15" stroke-width="1.75" />
                            </a>
                            <button
                                v-if="s.status === 'draft'"
                                type="button"
                                class="ghost-link danger"
                                title="Delete"
                                @click="askDelete(s)"
                            >
                                <IconTrash :size="15" stroke-width="1.75" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div v-else style="padding: 64px 24px; text-align: center;">
                <div style="color: var(--text-tertiary); display: inline-flex;">
                    <IconFileInvoice :size="48" stroke-width="1.5" />
                </div>
                <h3 style="margin-top: 12px;">No statements yet</h3>
                <p style="color: var(--text-secondary); margin-top: 6px;">Create your first monthly statement to track Maavelus revenue.</p>
                <button type="button" class="btn btn-primary" style="margin-top: 14px;" @click="openCreate">
                    <IconPlus :size="15" stroke-width="1.75" />
                    New statement
                </button>
            </div>
        </div>

        <!-- ═══ New-statement slide-over ═══ -->
        <TransitionRoot as="template" :show="showCreate">
            <Dialog as="div" class="slide-over-dialog" @close="showCreate = false">
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
                        <form class="slide-over-form" @submit.prevent="submitCreate">
                            <header class="slide-over-header">
                                <h2>New statement</h2>
                                <button type="button" class="icon-btn" aria-label="Close" @click="showCreate = false">
                                    <IconX :size="18" stroke-width="1.75" />
                                </button>
                            </header>

                            <div class="slide-over-body">
                                <div class="form-section">
                                    <div class="form-section-title">Statement period</div>
                                    <div class="form-row">
                                        <div class="form-field">
                                            <label>Month</label>
                                            <select v-model="form.month">
                                                <option v-for="m in MONTHS" :key="m.value" :value="m.value">{{ m.label }}</option>
                                            </select>
                                        </div>
                                        <div class="form-field">
                                            <label>Year</label>
                                            <select v-model="form.year">
                                                <option v-for="y in YEARS" :key="y" :value="y">{{ y }}</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div v-if="form.errors.period_month" class="err">{{ form.errors.period_month }}</div>
                                    <p style="font-size: 11.5px; color: var(--text-tertiary); margin-top: 6px;">Each period can only have one statement.</p>
                                </div>

                                <div class="form-section">
                                    <div class="form-section-title">Fees per restaurant</div>
                                    <p style="font-size: 11.5px; color: var(--text-tertiary); margin: -6px 0 10px;">
                                        Enter total Maavelus fees collected from each restaurant this period.
                                    </p>

                                    <div
                                        v-for="(line, idx) in form.lines"
                                        :key="idx"
                                        style="display: grid; grid-template-columns: 1fr 110px 100px 28px; gap: 8px; align-items: start; margin-bottom: 8px;"
                                    >
                                        <div class="form-field" style="margin: 0;">
                                            <select v-model.number="line.customer_id" :class="{ 'has-err': form.errors[`lines.${idx}.customer_id`] }">
                                                <option :value="null" disabled>Select restaurant…</option>
                                                <option v-for="c in maavelus_customers" :key="c.id" :value="c.id">{{ c.name }}</option>
                                            </select>
                                        </div>
                                        <div class="form-field" style="margin: 0; position: relative;">
                                            <span style="position: absolute; left: 9px; top: 9px; color: var(--text-tertiary); font: 500 13px/1 'Inter', sans-serif; z-index: 1;">£</span>
                                            <input
                                                v-model.number="line.total_fees"
                                                type="number"
                                                step="0.01"
                                                min="0.01"
                                                placeholder="0.00"
                                                style="padding-left: 22px;"
                                                :class="{ 'has-err': form.errors[`lines.${idx}.total_fees`] }"
                                            >
                                        </div>
                                        <div class="form-field" style="margin: 0;">
                                            <input
                                                v-model.number="line.order_count"
                                                type="number"
                                                min="0"
                                                placeholder="Orders"
                                            >
                                        </div>
                                        <button
                                            type="button"
                                            class="icon-btn"
                                            aria-label="Remove line"
                                            :disabled="form.lines.length <= 1"
                                            style="margin-top: 4px;"
                                            @click="removeLine(idx)"
                                        >
                                            <IconX :size="15" stroke-width="1.75" />
                                        </button>
                                    </div>

                                    <button type="button" class="ghost-link" style="margin-top: 4px;" @click="addLine">
                                        <IconPlus :size="14" stroke-width="1.75" />
                                        Add restaurant
                                    </button>

                                    <div style="margin-top: 14px; padding-top: 10px; border-top: 1px solid var(--border-soft); display: flex; justify-content: space-between; align-items: center;">
                                        <span style="font: 500 12px/1.4 'Inter', sans-serif; color: var(--text-secondary);">Total</span>
                                        <span style="font: 600 15px/1 'Inter', sans-serif; color: var(--accent); font-variant-numeric: tabular-nums;">{{ gbp(linesTotal) }}</span>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <div class="form-section-title">Optional</div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Total orders across all restaurants</label>
                                            <input v-model.number="form.total_orders" type="number" min="0" placeholder="For record keeping only">
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Notes</label>
                                            <textarea v-model="form.notes" rows="3" placeholder="Optional context" maxlength="1000" />
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <footer class="slide-over-footer">
                                <button type="button" class="btn btn-secondary" @click="showCreate = false">Cancel</button>
                                <button type="submit" class="btn btn-primary" :disabled="form.processing">
                                    <IconPlus :size="15" stroke-width="1.75" />
                                    {{ form.processing ? 'Creating…' : 'Create draft' }}
                                </button>
                            </footer>
                        </form>
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </TransitionRoot>

        <ConfirmModal
            v-model:show="showDeleteModal"
            :title="deleteTarget ? `Delete ${deleteTarget.period_label} statement?` : 'Delete statement?'"
            message="This draft statement will be permanently deleted. No commission records will be touched (they only exist on confirmed statements)."
            confirm-label="Delete statement"
            variant="danger"
            :loading="deleteProcessing"
            @confirm="handleDelete"
        />
    </InternalLayout>
</template>
