<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Dialog, DialogPanel, TransitionRoot, TransitionChild,
} from '@headlessui/vue';
import {
    IconPlus, IconX, IconPencil, IconTrash, IconAlertCircle, IconInfoCircle,
    IconChevronLeft, IconChevronRight, IconToggleLeft, IconToggleRight,
} from '@tabler/icons-vue';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const props = defineProps({
    rules: { type: Object, required: true },
    products: { type: Array, default: () => [] },
    referrers: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

/* ─── Filters ─── */
const productFilter = ref(props.filters.product_id ?? '');
const referrerFilter = ref(props.filters.referrer_id ?? '');
const activeFilter = ref(props.filters.active ?? '');
function applyFilters() {
    router.get('/settings/commission-rules', {
        product_id: productFilter.value || undefined,
        referrer_id: referrerFilter.value || undefined,
        active: activeFilter.value !== '' ? activeFilter.value : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

/* ─── Create / edit slide-over ─── */
const showForm = ref(false);
const editingId = ref(null);

function blankForm() {
    return {
        product_id: '',
        referrer_id: '',
        mode: 'one_off',
        rate_kind: 'percentage',
        percentage: '',
        flat_amount: '',
        recurring_percentage: '',
        duration: 'lifetime',
        recurring_months: '',
        valid_from: new Date().toISOString().slice(0, 10),
        valid_until: '',
        is_active: true,
    };
}

const form = useForm(blankForm());

function openCreate() {
    editingId.value = null;
    form.clearErrors();
    form.defaults(blankForm());
    form.reset();
    showForm.value = true;
}

function openEdit(rule) {
    editingId.value = rule.id;
    form.clearErrors();
    form.product_id = rule.product_id;
    form.referrer_id = rule.referrer_id ?? '';
    form.mode = rule.mode;
    form.rate_kind = rule.rate_kind;
    form.percentage = rule.percentage ?? '';
    form.flat_amount = rule.flat_amount ?? '';
    form.recurring_percentage = rule.recurring_percentage ?? '';
    form.duration = rule.duration;
    form.recurring_months = rule.recurring_months ?? '';
    form.valid_from = rule.valid_from ?? '';
    form.valid_until = rule.valid_until ?? '';
    form.is_active = rule.is_active;
    showForm.value = true;
}

function submit() {
    const opts = {
        preserveScroll: true,
        onSuccess: () => { showForm.value = false; },
    };
    if (editingId.value) {
        form.put(`/settings/commission-rules/${editingId.value}`, opts);
    } else {
        form.post('/settings/commission-rules', opts);
    }
}

function toggle(rule) {
    router.post(`/settings/commission-rules/${rule.id}/toggle`, {}, { preserveScroll: true });
}

/* ─── Delete ─── */
const showDelete = ref(false);
const toDelete = ref(null);
function askDelete(rule) { toDelete.value = rule; showDelete.value = true; }
function confirmDelete() {
    if (! toDelete.value) return;
    router.delete(`/settings/commission-rules/${toDelete.value.id}`, {
        preserveScroll: true,
        onFinish: () => { showDelete.value = false; toDelete.value = null; },
    });
}

const isEditing = computed(() => editingId.value !== null);
</script>

<template>
    <Head title="Commission rules" />
    <SettingsLayout title="Commission rules" active-section="commission-rules">
        <div class="cr-head">
            <div>
                <h2 class="cr-title">Commission rules</h2>
                <p class="cr-sub">How referral commissions are calculated per product. A referrer-specific rule overrides the product-wide default.</p>
            </div>
            <button type="button" class="btn btn-primary" @click="openCreate">
                <IconPlus :size="16" stroke-width="2" /> New rule
            </button>
        </div>

        <!-- Filters -->
        <div class="cr-filters">
            <select v-model="productFilter" class="field-input" @change="applyFilters">
                <option value="">All products</option>
                <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
            <select v-model="referrerFilter" class="field-input" @change="applyFilters">
                <option value="">All referrers</option>
                <option value="default">Default (no referrer)</option>
                <option v-for="r in referrers" :key="r.id" :value="r.id">{{ r.name }}</option>
            </select>
            <select v-model="activeFilter" class="field-input" @change="applyFilters">
                <option value="">Any status</option>
                <option value="1">Active</option>
                <option value="0">Inactive</option>
            </select>
        </div>

        <section class="table-card">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Referrer</th>
                        <th>Rate</th>
                        <th>Valid</th>
                        <th style="width: 90px;">Status</th>
                        <th style="width: 120px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="rule in rules.data" :key="rule.id">
                        <td>{{ rule.product_name }}</td>
                        <td>
                            <span v-if="rule.referrer_name">{{ rule.referrer_name }}</span>
                            <span v-else class="muted">All — default</span>
                        </td>
                        <td>{{ rule.rate_label }}</td>
                        <td class="muted small">
                            {{ rule.valid_from }} → {{ rule.valid_until || 'Open' }}
                        </td>
                        <td>
                            <span class="badge badge-sm" :class="rule.is_active ? 'badge-success' : 'badge-neutral'">
                                {{ rule.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td>
                            <div class="row-actions">
                                <button type="button" class="icon-btn xs" title="Edit" @click="openEdit(rule)">
                                    <IconPencil :size="14" stroke-width="2" />
                                </button>
                                <button type="button" class="icon-btn xs" :title="rule.is_active ? 'Deactivate' : 'Activate'" @click="toggle(rule)">
                                    <component :is="rule.is_active ? IconToggleRight : IconToggleLeft" :size="16" stroke-width="2" />
                                </button>
                                <button type="button" class="icon-btn xs danger" title="Delete" @click="askDelete(rule)">
                                    <IconTrash :size="13" stroke-width="2" />
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="rules.data.length === 0">
                        <td colspan="6" class="muted center" style="padding: 28px;">
                            No commission rules yet. <button class="ghost-link inline" @click="openCreate">Create one</button>.
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <!-- Pagination -->
        <div v-if="rules.last_page > 1" class="cr-pager">
            <Link
                class="icon-btn xs"
                :class="{ disabled: !rules.prev_page_url }"
                :href="rules.prev_page_url || ''"
                preserve-scroll
            ><IconChevronLeft :size="15" stroke-width="2" /></Link>
            <span class="muted small">Page {{ rules.current_page }} of {{ rules.last_page }}</span>
            <Link
                class="icon-btn xs"
                :class="{ disabled: !rules.next_page_url }"
                :href="rules.next_page_url || ''"
                preserve-scroll
            ><IconChevronRight :size="15" stroke-width="2" /></Link>
        </div>

        <!-- ═══ Create / edit slide-over ═══ -->
        <TransitionRoot as="template" :show="showForm">
            <Dialog as="div" class="slide-over-dialog" @close="showForm = false">
                <TransitionChild as="template"
                    enter="transition-opacity ease-out duration-200" enter-from="opacity-0" enter-to="opacity-100"
                    leave="transition-opacity ease-in duration-150" leave-from="opacity-100" leave-to="opacity-0">
                    <div class="slide-over-backdrop" />
                </TransitionChild>
                <TransitionChild as="template"
                    enter="transform transition ease-out duration-200" enter-from="translate-x-full" enter-to="translate-x-0"
                    leave="transform transition ease-in duration-150" leave-from="translate-x-0" leave-to="translate-x-full">
                    <DialogPanel class="slide-over-panel">
                        <form class="slide-over-form" @submit.prevent="submit">
                            <header class="slide-over-header">
                                <h2>{{ isEditing ? 'Edit commission rule' : 'New commission rule' }}</h2>
                                <button type="button" class="icon-btn" aria-label="Close" @click="showForm = false">
                                    <IconX :size="18" stroke-width="1.75" />
                                </button>
                            </header>

                            <div class="slide-over-body">
                                <div v-if="form.hasErrors" class="cr-form-error">
                                    <IconAlertCircle :size="16" stroke-width="2" />
                                    Please check the fields below.
                                </div>

                                <div class="cr-note">
                                    <IconInfoCircle :size="15" stroke-width="2" />
                                    <span>Edits apply to <strong>future accruals only</strong> — already-accrued ledger rows keep their stored amounts. To change a rate over time, end-date this rule and add a new one.</span>
                                </div>

                                <div class="sec-label" style="padding-top: 0;">Scope</div>
                                <div class="form-grid-2">
                                    <div class="field">
                                        <label class="field-label">Product <span class="req">*</span></label>
                                        <select v-model="form.product_id" class="field-input" :class="{ 'has-err': form.errors.product_id }">
                                            <option value="">Select a product…</option>
                                            <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
                                        </select>
                                        <div v-if="form.errors.product_id" class="field-err">{{ form.errors.product_id }}</div>
                                    </div>
                                    <div class="field">
                                        <label class="field-label">Referrer</label>
                                        <select v-model="form.referrer_id" class="field-input">
                                            <option value="">All — product-wide default</option>
                                            <option v-for="r in referrers" :key="r.id" :value="r.id">{{ r.name }}</option>
                                        </select>
                                        <div class="field-help">Blank = the default rule for this product. A referrer-specific rule overrides it.</div>
                                    </div>
                                </div>

                                <div class="sec-label">Rate</div>
                                <div class="cr-seg">
                                    <button type="button" :class="{ active: form.mode === 'one_off' }" @click="form.mode = 'one_off'">One-off</button>
                                    <button type="button" :class="{ active: form.mode === 'recurring' }" @click="form.mode = 'recurring'">Recurring</button>
                                </div>

                                <!-- One-off -->
                                <template v-if="form.mode === 'one_off'">
                                    <div class="cr-seg cr-seg-sm">
                                        <button type="button" :class="{ active: form.rate_kind === 'percentage' }" @click="form.rate_kind = 'percentage'">Percentage</button>
                                        <button type="button" :class="{ active: form.rate_kind === 'flat' }" @click="form.rate_kind = 'flat'">Flat amount</button>
                                    </div>
                                    <div class="field" v-if="form.rate_kind === 'percentage'">
                                        <label class="field-label">Percentage of invoice <span class="req">*</span></label>
                                        <div class="cr-input-suffix">
                                            <input v-model="form.percentage" type="number" step="0.01" min="0" max="100" class="field-input" :class="{ 'has-err': form.errors.percentage }">
                                            <span>%</span>
                                        </div>
                                        <div v-if="form.errors.percentage" class="field-err">{{ form.errors.percentage }}</div>
                                    </div>
                                    <div class="field" v-else>
                                        <label class="field-label">Flat amount <span class="req">*</span></label>
                                        <div class="cr-input-prefix">
                                            <span>£</span>
                                            <input v-model="form.flat_amount" type="number" step="0.01" min="0" class="field-input" :class="{ 'has-err': form.errors.flat_amount }">
                                        </div>
                                        <div class="field-help">A fixed payout per qualifying invoice (takes precedence over a percentage).</div>
                                        <div v-if="form.errors.flat_amount" class="field-err">{{ form.errors.flat_amount }}</div>
                                    </div>
                                </template>

                                <!-- Recurring -->
                                <template v-else>
                                    <div class="field">
                                        <label class="field-label">Recurring percentage <span class="req">*</span></label>
                                        <div class="cr-input-suffix">
                                            <input v-model="form.recurring_percentage" type="number" step="0.01" min="0" max="100" class="field-input" :class="{ 'has-err': form.errors.recurring_percentage }">
                                            <span>%</span>
                                        </div>
                                        <div class="field-help">Applied to each paid subscription invoice.</div>
                                        <div v-if="form.errors.recurring_percentage" class="field-err">{{ form.errors.recurring_percentage }}</div>
                                    </div>
                                    <div class="field">
                                        <label class="field-label">Duration</label>
                                        <div class="cr-seg cr-seg-sm">
                                            <button type="button" :class="{ active: form.duration === 'lifetime' }" @click="form.duration = 'lifetime'">Lifetime</button>
                                            <button type="button" :class="{ active: form.duration === 'months' }" @click="form.duration = 'months'">N months</button>
                                        </div>
                                    </div>
                                    <div class="field" v-if="form.duration === 'months'">
                                        <label class="field-label">Number of months <span class="req">*</span></label>
                                        <input v-model="form.recurring_months" type="number" min="1" step="1" class="field-input" :class="{ 'has-err': form.errors.recurring_months }">
                                        <div v-if="form.errors.recurring_months" class="field-err">{{ form.errors.recurring_months }}</div>
                                    </div>
                                </template>

                                <div class="sec-label">Validity</div>
                                <div class="form-grid-2">
                                    <div class="field">
                                        <label class="field-label">Valid from <span class="req">*</span></label>
                                        <input v-model="form.valid_from" type="date" class="field-input" :class="{ 'has-err': form.errors.valid_from }">
                                        <div v-if="form.errors.valid_from" class="field-err">{{ form.errors.valid_from }}</div>
                                    </div>
                                    <div class="field">
                                        <label class="field-label">Valid until</label>
                                        <input v-model="form.valid_until" type="date" class="field-input" :class="{ 'has-err': form.errors.valid_until }">
                                        <div class="field-help">Leave blank for open-ended.</div>
                                        <div v-if="form.errors.valid_until" class="field-err">{{ form.errors.valid_until }}</div>
                                    </div>
                                </div>

                                <label class="cr-check">
                                    <input v-model="form.is_active" type="checkbox">
                                    <span>Active</span>
                                </label>
                            </div>

                            <footer class="slide-over-footer">
                                <button type="button" class="btn btn-ghost" @click="showForm = false">Cancel</button>
                                <button type="submit" class="btn btn-primary" :disabled="form.processing">
                                    {{ form.processing ? 'Saving…' : (isEditing ? 'Save changes' : 'Create rule') }}
                                </button>
                            </footer>
                        </form>
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </TransitionRoot>

        <ConfirmModal
            v-model:show="showDelete"
            variant="danger"
            title="Delete this commission rule?"
            message="This removes the rule entirely. If it has already accrued commissions it can't be deleted — deactivate it instead."
            confirm-label="Delete rule"
            @confirm="confirmDelete"
        />
    </SettingsLayout>
</template>

<style scoped>
.cr-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.cr-title { font: 600 18px/1.2 'Inter', sans-serif; color: var(--text-primary); }
.cr-sub { font: 400 13px/1.5 'Inter', sans-serif; color: var(--text-secondary); margin-top: 2px; max-width: 60ch; }
.cr-filters { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
.cr-filters .field-input { width: auto; min-width: 180px; }
.cr-pager { display: flex; align-items: center; gap: 12px; justify-content: flex-end; margin-top: 14px; }
.cr-pager .disabled { opacity: .4; pointer-events: none; }

.cr-form-error {
    display: flex; align-items: center; gap: 8px;
    margin-bottom: 12px; padding: 10px 14px;
    background: var(--danger-bg); color: var(--danger);
    border: 1px solid #FECACA; border-radius: var(--radius-md);
    font: 500 13px/1.4 'Inter', sans-serif;
}
.cr-note {
    display: flex; align-items: flex-start; gap: 8px;
    margin-bottom: 16px; padding: 10px 14px;
    background: var(--info-bg, #EFF6FF); color: var(--text-secondary);
    border: 1px solid var(--border); border-radius: var(--radius-md);
    font: 400 12.5px/1.5 'Inter', sans-serif;
}
.cr-note .ti, .cr-note svg { flex-shrink: 0; margin-top: 1px; color: var(--info, #3B82F6); }

.cr-seg { display: inline-flex; border: 1px solid var(--border); border-radius: var(--radius-md); overflow: hidden; margin-bottom: 14px; }
.cr-seg.cr-seg-sm { margin-bottom: 12px; }
.cr-seg button {
    padding: 7px 16px; font: 500 13px/1 'Inter', sans-serif;
    background: #fff; color: var(--text-secondary); border: 0; cursor: pointer;
    border-right: 1px solid var(--border);
}
.cr-seg button:last-child { border-right: 0; }
.cr-seg button.active { background: var(--accent); color: var(--bg-navy); }

.cr-input-suffix, .cr-input-prefix { display: flex; align-items: center; gap: 0; }
.cr-input-suffix span, .cr-input-prefix span { padding: 0 10px; color: var(--text-tertiary); font: 500 13px/1 'Inter', sans-serif; }

.cr-check { display: inline-flex; align-items: center; gap: 8px; margin-top: 16px; font: 500 13px/1 'Inter', sans-serif; color: var(--text-primary); cursor: pointer; }
</style>
