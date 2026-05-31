<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Dialog,
    DialogPanel,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import {
    IconPlus,
    IconDeviceFloppy,
    IconX,
    IconAlertCircle,
    IconLock,
    IconUsers,
    IconArrowRight,
    IconTag,
    IconBuildingFactory2,
    IconPencil,
    IconTrash,
} from '@tabler/icons-vue';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const props = defineProps({
    products: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
});

/* ─── Colour palette presets ─── */
const PRESETS = [
    { hex: '#0D9488', name: 'Teal' },
    { hex: '#3B82F6', name: 'Blue' },
    { hex: '#7C3AED', name: 'Purple' },
    { hex: '#8B5CF6', name: 'Violet' },
    { hex: '#F59E0B', name: 'Amber' },
    { hex: '#EF4444', name: 'Red' },
    { hex: '#0F172A', name: 'Navy' },
    { hex: '#10B981', name: 'Green' },
];

/* ─── Selection ─── */
const selectedId = ref(props.products[0]?.id ?? null);
const selectedProduct = computed(() => props.products.find((p) => p.id === selectedId.value) ?? null);

const activeCount = computed(() => props.products.filter((p) => p.is_active).length);

function initial(name) { return (name?.[0] ?? '?').toUpperCase(); }

/* ─── Detail form ─── */
function buildDefaults(p) {
    return {
        name: p?.name ?? '',
        slug: p?.slug ?? '',
        description: p?.description ?? '',
        icon_colour: p?.icon_colour ?? '#0D9488',
        is_active: p?.is_active ?? true,
        is_coming_soon: p?.is_coming_soon ?? false,
        is_hosting: p?.is_hosting ?? false,
        sort_order: p?.sort_order ?? 0,
    };
}

const form = useForm(buildDefaults(selectedProduct.value));

watch(selectedProduct, (next) => {
    form.defaults(buildDefaults(next));
    form.reset();
    form.clearErrors();
});

/* ─── Switch product (ConfirmModal when dirty) ─── */
const showSwitchModal = ref(false);
const pendingSwitchId = ref(null);

function selectProduct(id) {
    if (id === selectedId.value) return;
    if (form.isDirty) {
        pendingSwitchId.value = id;
        showSwitchModal.value = true;

        return;
    }
    selectedId.value = id;
}

function handleSwitchConfirm() {
    const id = pendingSwitchId.value;
    showSwitchModal.value = false;
    pendingSwitchId.value = null;
    if (id !== null) selectedId.value = id;
}

function saveDetail() {
    if (!selectedProduct.value) return;
    form.put(`/settings/products/${selectedProduct.value.id}`, {
        preserveScroll: true,
    });
}

function discardChanges() {
    form.reset();
    form.clearErrors();
}

function toggleActive() {
    if (!selectedProduct.value) return;
    router.post(`/settings/products/${selectedProduct.value.id}/toggle`, {}, {
        preserveScroll: true,
    });
}

/* ─── Create slide-over ─── */
const showCreate = ref(false);
const createForm = useForm(buildDefaults(null));

function openCreate() {
    createForm.reset();
    createForm.clearErrors();
    showCreate.value = true;
}

function submitCreate() {
    createForm.post('/settings/products', {
        onSuccess: () => { showCreate.value = false; },
        preserveScroll: true,
    });
}

/* ─── Slug auto-derive (only while empty) ─── */
function slugFromName(name) {
    return String(name || '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 50);
}

watch(() => createForm.name, (n) => {
    if (!createForm.slug || createForm.slug === slugFromName(createForm.__lastName ?? '')) {
        createForm.slug = slugFromName(n);
    }
    createForm.__lastName = n;
});

const slugLocked = computed(() => (selectedProduct.value?.active_customers ?? 0) > 0);

/* Jump from the product detail page into the dedicated plan builder.
 * All plan/category/price management lives on that page now. */
function goToPlans() {
    if (! selectedProduct.value) return;
    router.visit(`/settings/products/${selectedProduct.value.id}/plans`);
}

/* ───────────────────────────────────────────────────────────────
 * Cost suppliers — the product_suppliers pivot + margin summary.
 * ─────────────────────────────────────────────────────────────── */
const INTERVAL_LABEL = { monthly: '/mo', quarterly: '/qtr', annually: '/yr', one_time: 'one-off' };

function moneyGBP(value) {
    return `£${Number(value || 0).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

/* Amortise a cost line to a per-month figure. One-off costs are not a
 * recurring monthly burden, so they're excluded from the margin maths. */
function monthlyCostOf(s) {
    const c = Number(s.cost_per_unit || 0);
    if (s.billing_interval === 'monthly') return c;
    if (s.billing_interval === 'quarterly') return c / 3;
    if (s.billing_interval === 'annually') return c / 12;

    return 0;
}

const productSuppliers = computed(() => selectedProduct.value?.suppliers ?? []);

const totalMonthlyCost = computed(() =>
    productSuppliers.value.reduce((sum, s) => sum + monthlyCostOf(s), 0));

/* Normalise any plan price to a monthly-equivalent revenue figure, then
 * take the cheapest non-zero — the entry-level monthly revenue we
 * compare the supplier cost against. */
function priceToMonthly(p) {
    const price = Number(p.price || 0);
    const count = Number(p.interval_count || 1) || 1;
    const unitMonths = { day: 1 / 30, week: 7 / 30, month: 1, year: 12 }[p.interval_unit] ?? 1;
    const months = count * unitMonths;

    return months > 0 ? price / months : 0;
}

const cheapestMonthlyRevenue = computed(() => {
    const monthlies = (selectedProduct.value?.plans ?? [])
        .flatMap((pl) => pl.prices ?? [])
        .map(priceToMonthly)
        .filter((v) => v > 0);

    return monthlies.length ? Math.min(...monthlies) : 0;
});

const grossMargin = computed(() => cheapestMonthlyRevenue.value - totalMonthlyCost.value);
const marginPct = computed(() => {
    if (cheapestMonthlyRevenue.value <= 0) return null;

    return Math.round((grossMargin.value / cheapestMonthlyRevenue.value) * 100);
});

/* ─── Add / edit supplier cost ─── */
const showSupplierForm = ref(false);
const editingSupplierId = ref(null);
const supplierForm = useForm({
    supplier_id: null,
    cost_per_unit: '',
    billing_interval: 'monthly',
    notes: '',
});

/* Suppliers not yet linked to this product (for the add picker). */
const availableSuppliers = computed(() => {
    const linked = new Set(productSuppliers.value.map((s) => s.id));

    return props.suppliers.filter((s) => !linked.has(s.id));
});

function openAddSupplier() {
    editingSupplierId.value = null;
    supplierForm.reset();
    supplierForm.clearErrors();
    showSupplierForm.value = true;
}
function openEditSupplier(s) {
    editingSupplierId.value = s.id;
    supplierForm.supplier_id = s.id;
    supplierForm.cost_per_unit = s.cost_per_unit;
    supplierForm.billing_interval = s.billing_interval;
    supplierForm.notes = s.notes ?? '';
    supplierForm.clearErrors();
    showSupplierForm.value = true;
}
function submitSupplier() {
    if (! selectedProduct.value) return;
    const base = `/settings/products/${selectedProduct.value.id}/suppliers`;
    const opts = { preserveScroll: true, onSuccess: () => { showSupplierForm.value = false; } };

    if (editingSupplierId.value) {
        supplierForm.put(`${base}/${editingSupplierId.value}`, opts);
    } else {
        supplierForm.post(base, opts);
    }
}

const showRemoveSupplier = ref(false);
const supplierToRemove = ref(null);
function askRemoveSupplier(s) { supplierToRemove.value = s; showRemoveSupplier.value = true; }
function confirmRemoveSupplier() {
    if (! selectedProduct.value || ! supplierToRemove.value) return;
    router.delete(`/settings/products/${selectedProduct.value.id}/suppliers/${supplierToRemove.value.id}`, {
        preserveScroll: true,
        onFinish: () => { showRemoveSupplier.value = false; supplierToRemove.value = null; },
    });
}
</script>


<template>
    <Head title="Products" />

    <SettingsLayout title="Products" active-section="products">
        <template #topbar-actions>
            <button type="button" class="btn btn-primary" @click="openCreate">
                <IconPlus :size="15" stroke-width="1.75" />
                Add product
            </button>
        </template>

        <div class="billing-entities">
            <!-- LEFT — product list -->
            <aside class="ent-list-card">
                <div class="ent-list-head">
                    <span class="title">Products</span>
                    <span class="badge badge-active badge-sm right">{{ activeCount }} active</span>
                </div>

                <div
                    v-for="p in products"
                    :key="p.id"
                    class="ent-row"
                    :class="{ selected: p.id === selectedId }"
                    @click="selectProduct(p.id)"
                >
                    <div class="top">
                        <div
                            class="ent-mark"
                            :style="{ background: p.icon_colour, color: '#fff' }"
                        >{{ initial(p.name) }}</div>
                        <div>
                            <div class="ent-name">{{ p.name }}</div>
                            <div class="ent-co">{{ p.slug }}</div>
                        </div>
                        <div class="right">
                            <span
                                v-if="p.is_coming_soon"
                                class="badge badge-pending badge-sm"
                            >Coming soon</span>
                            <span
                                v-else
                                class="badge badge-sm"
                                :class="p.is_active ? 'badge-active' : 'badge-inactive'"
                            >{{ p.is_active ? 'Active' : 'Inactive' }}</span>
                        </div>
                    </div>
                    <div class="ent-desc">
                        {{ p.active_customers }} active customer{{ p.active_customers === 1 ? '' : 's' }}
                    </div>
                </div>

                <div class="ent-add-divider" />
                <button type="button" class="ent-add" @click="openCreate">
                    <IconPlus :size="16" stroke-width="1.75" />
                    Add new product
                </button>
            </aside>

            <!-- RIGHT — detail form -->
            <section v-if="selectedProduct" class="form-card">
                <div class="form-head">
                    <div
                        class="ent-mark lg"
                        :style="{ background: form.icon_colour, color: '#fff' }"
                    >{{ initial(form.name) }}</div>
                    <div class="form-title">{{ selectedProduct.name }}</div>
                    <span
                        v-if="selectedProduct.is_coming_soon"
                        class="badge badge-pending"
                    >Coming soon</span>
                    <span
                        v-else
                        class="badge"
                        :class="selectedProduct.is_active ? 'badge-active' : 'badge-inactive'"
                    >{{ selectedProduct.is_active ? 'Active' : 'Inactive' }}</span>
                    <div class="right">
                        <button
                            type="button"
                            class="btn btn-primary"
                            :class="{ disabled: !form.isDirty }"
                            :disabled="!form.isDirty || form.processing"
                            @click="saveDetail"
                        >
                            <IconDeviceFloppy :size="15" stroke-width="1.75" />
                            {{ form.processing ? 'Saving…' : 'Save changes' }}
                        </button>
                    </div>
                </div>

                <form class="form-body" @submit.prevent="saveDetail">
                    <!-- PRODUCT DETAILS -->
                    <div class="sec-label">Product details</div>
                    <div class="form-grid-2">
                        <div class="field">
                            <label class="field-label">Name</label>
                            <input
                                v-model="form.name"
                                class="field-input"
                                :class="{ 'has-err': form.errors.name }"
                                type="text"
                                maxlength="100"
                            >
                            <div v-if="form.errors.name" class="field-err">{{ form.errors.name }}</div>
                        </div>
                        <div class="field">
                            <label class="field-label">
                                Slug
                                <IconLock v-if="slugLocked" :size="11" stroke-width="2" style="margin-left: 4px; display: inline; vertical-align: middle;" />
                            </label>
                            <input
                                v-model="form.slug"
                                class="field-input mono"
                                :class="{ 'has-err': form.errors.slug }"
                                type="text"
                                maxlength="50"
                                :readonly="slugLocked"
                                :style="slugLocked ? 'background: var(--neutral-bg); cursor: not-allowed;' : ''"
                            >
                            <div class="field-help">
                                {{ slugLocked
                                    ? 'Locked — product has active customers.'
                                    : 'Used in URLs and API. Avoid changing after launch.' }}
                            </div>
                            <div v-if="form.errors.slug" class="field-err">{{ form.errors.slug }}</div>
                        </div>
                        <div class="field full">
                            <label class="field-label">Description</label>
                            <textarea
                                v-model="form.description"
                                class="field-input"
                                rows="3"
                                maxlength="500"
                                placeholder="Brief description visible to staff and on dashboards"
                            />
                            <div v-if="form.errors.description" class="field-err">{{ form.errors.description }}</div>
                        </div>
                    </div>

                    <!-- DISPLAY -->
                    <div class="sec-label">Display</div>
                    <div class="form-grid-2">
                        <div class="field full">
                            <label class="field-label">Icon colour</label>
                            <div class="colour-row">
                                <button
                                    v-for="c in PRESETS"
                                    :key="c.hex"
                                    type="button"
                                    class="colour-swatch"
                                    :class="{ active: form.icon_colour.toLowerCase() === c.hex.toLowerCase() }"
                                    :style="{ background: c.hex }"
                                    :title="c.name"
                                    @click="form.icon_colour = c.hex"
                                />
                                <input
                                    v-model="form.icon_colour"
                                    class="field-input mono colour-hex"
                                    :class="{ 'has-err': form.errors.icon_colour }"
                                    type="text"
                                    maxlength="7"
                                    pattern="^#[0-9A-Fa-f]{6}$"
                                >
                                <div
                                    class="ent-mark lg"
                                    :style="{ background: form.icon_colour, color: '#fff' }"
                                >{{ initial(form.name) }}</div>
                            </div>
                            <div v-if="form.errors.icon_colour" class="field-err">{{ form.errors.icon_colour }}</div>
                        </div>
                    </div>

                    <!-- STATUS -->
                    <div class="sec-label">Status</div>
                    <div class="status-rows">
                        <div class="set-row">
                            <div>
                                <div class="nm">Active</div>
                                <div class="sb">Inactive products are hidden from provisioning.</div>
                            </div>
                            <button
                                type="button"
                                class="toggle"
                                :class="{ on: form.is_active }"
                                aria-label="Toggle active"
                                @click="form.is_active = !form.is_active"
                            />
                        </div>
                        <div class="set-row">
                            <div>
                                <div class="nm">Coming soon</div>
                                <div class="sb">Shows a "Coming soon" badge instead of the product card.</div>
                            </div>
                            <button
                                type="button"
                                class="toggle"
                                :class="{ on: form.is_coming_soon }"
                                aria-label="Toggle coming soon"
                                @click="form.is_coming_soon = !form.is_coming_soon"
                            />
                        </div>
                        <div
                            v-if="!form.is_active && selectedProduct.active_customers > 0"
                            class="warn-pill"
                            style="margin-top: 10px;"
                        >
                            Has {{ selectedProduct.active_customers }} active customer{{ selectedProduct.active_customers === 1 ? '' : 's' }} — suspend subscriptions before deactivating.
                        </div>
                    </div>

                    <!-- PRODUCT TYPE -->
                    <div class="sec-label">Product type</div>
                    <div class="status-rows">
                        <div class="set-row">
                            <div>
                                <div class="nm">Hosting product</div>
                                <div class="sb">When enabled, this product appears in the website hosting plan selector.</div>
                            </div>
                            <button
                                type="button"
                                class="toggle"
                                :class="{ on: form.is_hosting }"
                                aria-label="Toggle hosting product"
                                @click="form.is_hosting = !form.is_hosting"
                            />
                        </div>
                    </div>

                    <!-- STATS -->
                    <div class="sec-label">Stats</div>
                    <div class="stats-card">
                        <div class="stats-row">
                            <span class="k">Active customers</span>
                            <span class="v">{{ selectedProduct.active_customers }}</span>
                        </div>
                        <div class="stats-row">
                            <span class="k">Total customers ever</span>
                            <span class="v">{{ selectedProduct.total_customers }}</span>
                        </div>
                        <a
                            :href="`/customers?product=${selectedProduct.slug}`"
                            class="stats-link"
                        >
                            <IconUsers :size="14" stroke-width="1.75" />
                            View customers
                            <IconArrowRight :size="14" stroke-width="1.75" />
                        </a>
                    </div>

                    <!-- Save row -->
                    <div v-if="form.isDirty" class="save-row">
                        <button type="button" class="btn btn-ghost" :disabled="form.processing" @click="discardChanges">Discard changes</button>
                        <button type="submit" class="btn btn-primary" :disabled="form.processing">
                            <IconDeviceFloppy :size="15" stroke-width="1.75" />
                            {{ form.processing ? 'Saving…' : 'Save changes' }}
                        </button>
                    </div>

                    <!-- Toggle row (operator action, separate from form save) -->
                    <div
                        style="margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border-soft); display: flex; justify-content: flex-end;"
                    >
                        <button
                            type="button"
                            class="btn btn-ghost"
                            :class="{ danger: selectedProduct.is_active }"
                            @click="toggleActive"
                        >
                            {{ selectedProduct.is_active ? 'Deactivate' : 'Activate' }}
                        </button>
                    </div>

                    <!-- Link card to the dedicated plan builder. All
                         plan/category/price management lives there now. -->
                    <button type="button" class="plans-link-card" @click="goToPlans">
                        <div class="plc-left">
                            <div class="plc-icon"><IconTag :size="18" stroke-width="1.75" /></div>
                            <div style="text-align: left;">
                                <div class="plc-title">Plans &amp; Pricing</div>
                                <div class="plc-sub">
                                    {{ selectedProduct.plans?.length ?? 0 }} plans · manage tiers, pricing and categories
                                </div>
                            </div>
                        </div>
                        <IconArrowRight :size="16" stroke-width="1.75" class="plc-arrow" />
                    </button>

                    <!-- COST SUPPLIERS -->
                    <div class="sec-label">Cost suppliers</div>
                    <div class="cost-suppliers">
                        <div class="cs-head">
                            <p class="cs-sub">Track the underlying costs for margin calculation.</p>
                            <button type="button" class="btn btn-ghost btn-sm" @click="openAddSupplier">
                                <IconPlus :size="14" stroke-width="1.75" />
                                Add supplier
                            </button>
                        </div>

                        <div v-if="productSuppliers.length > 0" class="cs-rows">
                            <div v-for="s in productSuppliers" :key="s.id" class="cs-row">
                                <div class="cs-left">
                                    <div class="cs-icon"><IconBuildingFactory2 :size="16" stroke-width="1.75" /></div>
                                    <div class="cs-meta">
                                        <Link :href="`/suppliers?search=${encodeURIComponent(s.name)}`" class="cs-name">{{ s.name }}</Link>
                                        <div v-if="s.notes" class="cs-notes">{{ s.notes }}</div>
                                    </div>
                                </div>
                                <div class="cs-right">
                                    <span class="cs-cost">{{ moneyGBP(s.cost_per_unit) }}<span class="cs-int">{{ INTERVAL_LABEL[s.billing_interval] }}</span></span>
                                    <button type="button" class="icon-btn xs" title="Edit" @click="openEditSupplier(s)">
                                        <IconPencil :size="14" stroke-width="1.75" />
                                    </button>
                                    <button type="button" class="icon-btn xs danger" title="Remove" @click="askRemoveSupplier(s)">
                                        <IconTrash :size="14" stroke-width="1.75" />
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- MARGIN SUMMARY -->
                        <div v-if="productSuppliers.length > 0" class="cs-margin">
                            <div class="csm-row">
                                <span class="k">Monthly revenue</span>
                                <span class="v">{{ cheapestMonthlyRevenue > 0 ? moneyGBP(cheapestMonthlyRevenue) + '/mo' : '— no monthly plan' }}</span>
                            </div>
                            <div class="csm-row">
                                <span class="k">Monthly cost</span>
                                <span class="v neg">−{{ moneyGBP(totalMonthlyCost) }}/mo</span>
                            </div>
                            <div class="csm-row total">
                                <span class="k">Gross margin</span>
                                <span class="v" :class="grossMargin >= 0 ? 'pos' : 'neg'">
                                    {{ moneyGBP(grossMargin) }}/mo
                                    <span v-if="marginPct !== null" class="csm-pct">({{ marginPct }}%)</span>
                                </span>
                            </div>
                        </div>

                        <!-- EMPTY STATE -->
                        <div v-else class="cs-empty">
                            No supplier costs linked. Add suppliers to track your margin.
                        </div>
                    </div>
                </form>
            </section>

            <section v-else class="form-card">
                <div class="form-body" style="padding: 48px 24px; text-align: center; color: var(--text-secondary);">
                    Select a product from the list, or add a new one.
                </div>
            </section>
        </div>

        <!-- ═══ Add product slide-over ═══ -->
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
                                <h2>New product</h2>
                                <button type="button" class="icon-btn" aria-label="Close" @click="showCreate = false">
                                    <IconX :size="18" stroke-width="1.75" />
                                </button>
                            </header>

                            <div class="slide-over-body billing-entities">
                                <div
                                    v-if="createForm.hasErrors"
                                    style="margin-bottom: 12px; padding: 10px 14px; background: var(--danger-bg); color: var(--danger); border: 1px solid #FECACA; border-radius: var(--radius-md); font: 500 13px/1.4 'Inter', sans-serif; display: flex; gap: 8px; align-items: center;"
                                >
                                    <IconAlertCircle :size="16" stroke-width="2" />
                                    Please check the fields below.
                                </div>

                                <div class="sec-label" style="padding-top: 0;">Product details</div>
                                <div class="form-grid-2">
                                    <div class="field">
                                        <label class="field-label">Name</label>
                                        <input v-model="createForm.name" class="field-input" :class="{ 'has-err': createForm.errors.name }" type="text" maxlength="100">
                                        <div v-if="createForm.errors.name" class="field-err">{{ createForm.errors.name }}</div>
                                    </div>
                                    <div class="field">
                                        <label class="field-label">Slug</label>
                                        <input v-model="createForm.slug" class="field-input mono" :class="{ 'has-err': createForm.errors.slug }" type="text" maxlength="50">
                                        <div class="field-help">Used in URLs and API — auto-filled from name.</div>
                                        <div v-if="createForm.errors.slug" class="field-err">{{ createForm.errors.slug }}</div>
                                    </div>
                                    <div class="field full">
                                        <label class="field-label">Description</label>
                                        <textarea v-model="createForm.description" class="field-input" rows="3" maxlength="500" />
                                        <div v-if="createForm.errors.description" class="field-err">{{ createForm.errors.description }}</div>
                                    </div>
                                </div>

                                <div class="sec-label">Display</div>
                                <div class="form-grid-2">
                                    <div class="field full">
                                        <label class="field-label">Icon colour</label>
                                        <div class="colour-row">
                                            <button
                                                v-for="c in PRESETS"
                                                :key="c.hex"
                                                type="button"
                                                class="colour-swatch"
                                                :class="{ active: createForm.icon_colour.toLowerCase() === c.hex.toLowerCase() }"
                                                :style="{ background: c.hex }"
                                                :title="c.name"
                                                @click="createForm.icon_colour = c.hex"
                                            />
                                            <input
                                                v-model="createForm.icon_colour"
                                                class="field-input mono colour-hex"
                                                :class="{ 'has-err': createForm.errors.icon_colour }"
                                                type="text"
                                                maxlength="7"
                                            >
                                            <div
                                                class="ent-mark lg"
                                                :style="{ background: createForm.icon_colour, color: '#fff' }"
                                            >{{ initial(createForm.name) }}</div>
                                        </div>
                                        <div v-if="createForm.errors.icon_colour" class="field-err">{{ createForm.errors.icon_colour }}</div>
                                    </div>
                                </div>

                                <div class="sec-label">Status</div>
                                <div class="status-rows">
                                    <div class="set-row">
                                        <div>
                                            <div class="nm">Active</div>
                                            <div class="sb">Available for new subscriptions.</div>
                                        </div>
                                        <button
                                            type="button"
                                            class="toggle"
                                            :class="{ on: createForm.is_active }"
                                            aria-label="Toggle active"
                                            @click="createForm.is_active = !createForm.is_active"
                                        />
                                    </div>
                                    <div class="set-row">
                                        <div>
                                            <div class="nm">Coming soon</div>
                                            <div class="sb">Pre-launch marker for the dashboard.</div>
                                        </div>
                                        <button
                                            type="button"
                                            class="toggle"
                                            :class="{ on: createForm.is_coming_soon }"
                                            aria-label="Toggle coming soon"
                                            @click="createForm.is_coming_soon = !createForm.is_coming_soon"
                                        />
                                    </div>
                                </div>

                                <div class="sec-label">Product type</div>
                                <div class="status-rows">
                                    <div class="set-row">
                                        <div>
                                            <div class="nm">Hosting product</div>
                                            <div class="sb">When enabled, this product appears in the website hosting plan selector.</div>
                                        </div>
                                        <button
                                            type="button"
                                            class="toggle"
                                            :class="{ on: createForm.is_hosting }"
                                            aria-label="Toggle hosting product"
                                            @click="createForm.is_hosting = !createForm.is_hosting"
                                        />
                                    </div>
                                </div>
                            </div>

                            <footer class="slide-over-footer">
                                <button type="button" class="btn btn-secondary" @click="showCreate = false">Cancel</button>
                                <button type="submit" class="btn btn-primary" :disabled="createForm.processing">
                                    <IconPlus :size="15" stroke-width="1.75" />
                                    {{ createForm.processing ? 'Creating…' : 'Create product' }}
                                </button>
                            </footer>
                        </form>
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </TransitionRoot>

        <!-- ═══ Add/Edit supplier cost slide-over ═══ -->
        <Teleport to="body">
            <div v-if="showSupplierForm" class="slide-over-overlay" @click.self="showSupplierForm = false">
                <div class="slide-over product-supplier-form" style="width: 460px;">
                    <div class="slide-over-head">
                        <h2>{{ editingSupplierId ? 'Edit supplier cost' : 'Add supplier cost' }}</h2>
                        <button type="button" class="icon-btn" @click="showSupplierForm = false">
                            <IconX :size="18" stroke-width="2" />
                        </button>
                    </div>
                    <form class="slide-over-body" @submit.prevent="submitSupplier">
                        <div class="form-section">
                            <label class="form-label">Supplier <span class="req">*</span></label>
                            <select v-if="!editingSupplierId" v-model="supplierForm.supplier_id" class="form-input" required>
                                <option :value="null" disabled>Select a supplier…</option>
                                <option v-for="s in availableSuppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </select>
                            <input v-else type="text" class="form-input" :value="productSuppliers.find((x) => x.id === editingSupplierId)?.name" readonly disabled />
                            <p v-if="!editingSupplierId && availableSuppliers.length === 0" class="field-help">
                                All active suppliers are already linked. <Link href="/suppliers" target="_blank">Create one →</Link>
                            </p>
                            <p v-if="supplierForm.errors.supplier_id" class="form-error">{{ supplierForm.errors.supplier_id }}</p>
                        </div>

                        <div class="form-row-2">
                            <div class="form-section">
                                <label class="form-label">Cost per unit (£) <span class="req">*</span></label>
                                <input v-model="supplierForm.cost_per_unit" type="number" min="0" step="0.01" class="form-input" required />
                                <p v-if="supplierForm.errors.cost_per_unit" class="form-error">{{ supplierForm.errors.cost_per_unit }}</p>
                            </div>
                            <div class="form-section">
                                <label class="form-label">Billing interval</label>
                                <select v-model="supplierForm.billing_interval" class="form-input">
                                    <option value="monthly">Monthly</option>
                                    <option value="quarterly">Quarterly</option>
                                    <option value="annually">Annually</option>
                                    <option value="one_time">One-time</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-section">
                            <label class="form-label">Notes</label>
                            <textarea v-model="supplierForm.notes" class="form-input" rows="2" maxlength="500"></textarea>
                            <p v-if="supplierForm.errors.notes" class="form-error">{{ supplierForm.errors.notes }}</p>
                        </div>
                    </form>
                    <div class="slide-over-foot">
                        <button type="button" class="btn btn-ghost" @click="showSupplierForm = false">Cancel</button>
                        <button type="button" class="btn btn-primary" :disabled="supplierForm.processing" @click="submitSupplier">
                            {{ supplierForm.processing ? 'Saving…' : (editingSupplierId ? 'Save' : 'Add cost') }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <ConfirmModal
            v-model:show="showRemoveSupplier"
            variant="danger"
            title="Remove supplier cost?"
            :message="`${supplierToRemove?.name ?? 'This supplier'} will be unlinked from this product. The supplier record itself is not deleted.`"
            confirm-label="Remove"
            @confirm="confirmRemoveSupplier"
        />

        <ConfirmModal
            v-model:show="showSwitchModal"
            title="Discard unsaved changes?"
            message="You have unsaved changes on the current product. Switching will discard them."
            confirm-label="Discard and switch"
            variant="warning"
            @confirm="handleSwitchConfirm"
        />

    </SettingsLayout>
</template>
