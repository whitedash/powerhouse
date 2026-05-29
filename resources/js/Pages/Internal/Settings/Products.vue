<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Dialog,
    DialogPanel,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import {
    Menu,
    MenuButton,
    MenuItem,
    MenuItems,
} from '@headlessui/vue';
import {
    IconPlus,
    IconDeviceFloppy,
    IconX,
    IconAlertCircle,
    IconCheck,
    IconLock,
    IconUsers,
    IconArrowRight,
    IconTag,
    IconDots,
    IconTrash,
    IconBrandStripe,
    IconGripVertical,
} from '@tabler/icons-vue';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';
import IntervalPicker from '@/Components/UI/IntervalPicker.vue';

const props = defineProps({
    products: { type: Array, default: () => [] },
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

/* ─── Plans ─── *
 * The plan form drops the old price/interval/stripe fields and gains
 * category_id + an optional initial_* block. The backend uses
 * initial_* to seed the plan's first ProductPlanPrice; on edit the
 * controller drops them and pricing is managed through the dedicated
 * price slide-over instead. */
const PLAN_DEFAULTS = {
    name: '',
    description: '',
    category_id: null,
    features: [],
    is_active: true,
    is_public: true,
    sort_order: 0,
    initial_price: null,
    initial_interval_count: 1,
    initial_interval_unit: 'month',
};

const showPlanModal = ref(false);
const planMode = ref('create'); // 'create' | 'edit'
const editingPlanId = ref(null);
const planForm = useForm({ ...PLAN_DEFAULTS, product_id: null });
const newFeature = ref('');

function openCreatePlan(categoryId = null) {
    if (! selectedProduct.value) return;
    planMode.value = 'create';
    editingPlanId.value = null;
    planForm.defaults({ ...PLAN_DEFAULTS, product_id: selectedProduct.value.id, category_id: categoryId });
    planForm.reset();
    planForm.product_id = selectedProduct.value.id;
    planForm.category_id = categoryId;
    planForm.clearErrors();
    newFeature.value = '';
    showInitialPriceSection.value = false;
    showPlanModal.value = true;
}

function openEditPlan(plan) {
    planMode.value = 'edit';
    editingPlanId.value = plan.id;
    planForm.defaults({
        ...PLAN_DEFAULTS,
        product_id: selectedProduct.value.id,
        name: plan.name ?? '',
        description: plan.description ?? '',
        category_id: plan.category_id ?? null,
        features: Array.isArray(plan.features) ? [...plan.features] : [],
        is_active: !! plan.is_active,
        is_public: !! plan.is_public,
        sort_order: Number(plan.sort_order ?? 0),
    });
    planForm.reset();
    planForm.clearErrors();
    newFeature.value = '';
    showInitialPriceSection.value = false;
    showPlanModal.value = true;
}

function addFeature() {
    const f = newFeature.value.trim();
    if (! f || planForm.features.length >= 10) return;
    planForm.features = [...planForm.features, f];
    newFeature.value = '';
}
function removeFeature(idx) {
    planForm.features = planForm.features.filter((_, i) => i !== idx);
}

/* ─── Drag-to-reorder features (HTML5 DnD) ─── */
const featureDragIndex = ref(null);
const featureDragOverIndex = ref(null);

function onFeatureDragStart(event, index) {
    featureDragIndex.value = index;
    event.dataTransfer.effectAllowed = 'move';
    // dataTransfer must be set for Firefox to fire the rest of the
    // drag lifecycle. The payload isn't read — index lives in state.
    event.dataTransfer.setData('text/plain', String(index));
    event.target.classList.add('feat-dragging');
}
function onFeatureDragOver(event, index) {
    if (featureDragIndex.value === null || featureDragIndex.value === index) return;
    featureDragOverIndex.value = index;
}
function onFeatureDragLeave() {
    featureDragOverIndex.value = null;
}
function onFeatureDrop(event, dropIndex) {
    const dragIndex = featureDragIndex.value;
    if (dragIndex === null || dragIndex === dropIndex) {
        featureDragIndex.value = null;
        featureDragOverIndex.value = null;

        return;
    }
    const features = [...planForm.features];
    const [moved] = features.splice(dragIndex, 1);
    features.splice(dropIndex, 0, moved);
    planForm.features = features;
    featureDragIndex.value = null;
    featureDragOverIndex.value = null;
}
function onFeatureDragEnd(event) {
    // Fires even if the drop was cancelled (e.g. dropped outside any
    // valid target), so it's the canonical place to reset state.
    event.target.classList.remove('feat-dragging');
    featureDragIndex.value = null;
    featureDragOverIndex.value = null;
}

function submitPlan() {
    const onSuccess = () => { showPlanModal.value = false; };
    if (planMode.value === 'create') {
        planForm.post('/settings/plans', { preserveScroll: true, onSuccess });
    } else {
        planForm.put(`/settings/plans/${editingPlanId.value}`, { preserveScroll: true, onSuccess });
    }
}

const showInitialPriceSection = ref(false);

/* The plan slide-over's optional "first price" block uses IntervalPicker
 * against initial_interval_* — the form posts them directly. */
const initialInterval = computed({
    get: () => ({ count: planForm.initial_interval_count, unit: planForm.initial_interval_unit }),
    set: (v) => {
        planForm.initial_interval_count = v.count;
        planForm.initial_interval_unit = v.unit;
    },
});

function togglePlanActive(plan) {
    router.post(`/settings/plans/${plan.id}/toggle`, {}, { preserveScroll: true });
}

/* ─── Categories ─── */
const showCategoryModal = ref(false);
const categoryMode = ref('create');
const editingCategoryId = ref(null);
const categoryForm = useForm({
    product_id: null,
    name: '',
    description: '',
    sort_order: 0,
    is_public: true,
});

function openCreateCategory() {
    if (! selectedProduct.value) return;
    categoryMode.value = 'create';
    editingCategoryId.value = null;
    categoryForm.defaults({
        product_id: selectedProduct.value.id, name: '', description: '', sort_order: 0, is_public: true,
    });
    categoryForm.reset();
    categoryForm.clearErrors();
    showCategoryModal.value = true;
}
function openEditCategory(cat) {
    categoryMode.value = 'edit';
    editingCategoryId.value = cat.id;
    categoryForm.defaults({
        product_id: selectedProduct.value.id,
        name: cat.name ?? '',
        description: cat.description ?? '',
        sort_order: Number(cat.sort_order ?? 0),
        is_public: !! cat.is_public,
    });
    categoryForm.reset();
    categoryForm.clearErrors();
    showCategoryModal.value = true;
}
function submitCategory() {
    const onSuccess = () => { showCategoryModal.value = false; };
    if (categoryMode.value === 'create') {
        categoryForm.post('/settings/plan-categories', { preserveScroll: true, onSuccess });
    } else {
        categoryForm.put(`/settings/plan-categories/${editingCategoryId.value}`, { preserveScroll: true, onSuccess });
    }
}

const showDeleteCategoryModal = ref(false);
const deleteCategoryTarget = ref(null);
const deleteCategoryProcessing = ref(false);
function askDeleteCategory(cat) {
    deleteCategoryTarget.value = cat;
    showDeleteCategoryModal.value = true;
}
function handleDeleteCategory() {
    if (! deleteCategoryTarget.value) return;
    deleteCategoryProcessing.value = true;
    router.delete(`/settings/plan-categories/${deleteCategoryTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deleteCategoryProcessing.value = false;
            showDeleteCategoryModal.value = false;
            deleteCategoryTarget.value = null;
        },
    });
}
const deleteCategoryMessage = computed(() => deleteCategoryTarget.value
    ? `Delete '${deleteCategoryTarget.value.name}'? Plans currently in this category will become uncategorised.`
    : '');

/* ─── Prices ─── */
const PRICE_DEFAULTS = {
    plan_id: null,
    price: 0,
    interval_count: 1,
    interval_unit: 'month',
    stripe_price_id: '',
    label: '',
    is_default: false,
    is_active: true,
    sort_order: 0,
};

const showPriceModal = ref(false);
const priceMode = ref('create');
const editingPriceId = ref(null);
const priceContextPlan = ref(null);
const priceForm = useForm({ ...PRICE_DEFAULTS });

function openCreatePrice(plan) {
    priceMode.value = 'create';
    editingPriceId.value = null;
    priceContextPlan.value = plan;
    priceForm.defaults({ ...PRICE_DEFAULTS, plan_id: plan.id });
    priceForm.reset();
    priceForm.plan_id = plan.id;
    priceForm.clearErrors();
    showPriceModal.value = true;
}
function openEditPrice(plan, price) {
    priceMode.value = 'edit';
    editingPriceId.value = price.id;
    priceContextPlan.value = plan;
    priceForm.defaults({
        ...PRICE_DEFAULTS,
        plan_id: plan.id,
        price: Number(price.price ?? 0),
        interval_count: Number(price.interval_count ?? 1),
        interval_unit: price.interval_unit ?? 'month',
        stripe_price_id: price.stripe_price_id ?? '',
        label: price.label ?? '',
        is_default: !! price.is_default,
        is_active: !! price.is_active,
        sort_order: Number(price.sort_order ?? 0),
    });
    priceForm.reset();
    priceForm.clearErrors();
    showPriceModal.value = true;
}
function submitPrice() {
    const onSuccess = () => { showPriceModal.value = false; };
    if (priceMode.value === 'create') {
        priceForm.post('/settings/plan-prices', { preserveScroll: true, onSuccess });
    } else {
        priceForm.put(`/settings/plan-prices/${editingPriceId.value}`, { preserveScroll: true, onSuccess });
    }
}

const priceInterval = computed({
    get: () => ({ count: priceForm.interval_count, unit: priceForm.interval_unit }),
    set: (v) => {
        priceForm.interval_count = v.count;
        priceForm.interval_unit = v.unit;
    },
});

const showDeletePriceModal = ref(false);
const deletePriceTarget = ref(null);
const deletePriceProcessing = ref(false);
function askDeletePrice(price) {
    deletePriceTarget.value = price;
    showDeletePriceModal.value = true;
}
function handleDeletePrice() {
    if (! deletePriceTarget.value) return;
    deletePriceProcessing.value = true;
    router.delete(`/settings/plan-prices/${deletePriceTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deletePriceProcessing.value = false;
            showDeletePriceModal.value = false;
            deletePriceTarget.value = null;
        },
    });
}

/* ─── Grouped plans for the right panel ─── */
const categorisedPlans = computed(() => {
    const out = (selectedProduct.value?.plan_categories ?? []).map((c) => ({
        ...c,
        plans: (selectedProduct.value?.plans ?? []).filter((p) => p.category_id === c.id),
    }));
    const uncategorised = (selectedProduct.value?.plans ?? []).filter((p) => ! p.category_id);

    return { categories: out, uncategorised };
});

const showDeletePlanModal = ref(false);
const deletePlanTarget = ref(null);
const deletePlanProcessing = ref(false);
function askDeletePlan(plan) {
    deletePlanTarget.value = plan;
    showDeletePlanModal.value = true;
}
function handleDeletePlan() {
    if (! deletePlanTarget.value) return;
    deletePlanProcessing.value = true;
    router.delete(`/settings/plans/${deletePlanTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deletePlanProcessing.value = false;
            showDeletePlanModal.value = false;
            deletePlanTarget.value = null;
        },
    });
}
const deletePlanMessage = computed(() => {
    if (! deletePlanTarget.value) return '';
    return `Delete plan '${deletePlanTarget.value.name}'? This can't be undone.`;
});

function gbp(n) {
    return '£' + Number(n || 0).toFixed(2);
}
function gbpRound(n) {
    return '£' + Math.round(Number(n || 0));
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

        <!-- Flash banners -->
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

                    <!-- ═══ Plan categories ═══ -->
                    <div style="margin-top: 28px; padding-top: 20px; border-top: 1px solid var(--border);">
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 14px;">
                            <h3 style="margin: 0; font: 600 16px/1.2 'Inter', sans-serif;">Plan categories</h3>
                            <span class="badge badge-inactive badge-sm">{{ selectedProduct.plan_categories?.length || 0 }} categories</span>
                            <button
                                type="button"
                                class="btn btn-ghost btn-sm"
                                style="margin-left: auto; color: var(--accent);"
                                @click="openCreateCategory"
                            >
                                <IconPlus :size="14" stroke-width="1.75" />
                                Add category
                            </button>
                        </div>
                        <div v-if="(selectedProduct.plan_categories?.length ?? 0) === 0" style="border: 1.5px dashed var(--border); border-radius: var(--radius-md); padding: 16px; text-align: center; font: 400 13px/1.4 'Inter', sans-serif; color: var(--text-secondary);">
                            No categories yet. Plans will appear ungrouped.
                        </div>
                        <div v-else style="display: flex; flex-direction: column; gap: 8px;">
                            <div
                                v-for="cat in selectedProduct.plan_categories"
                                :key="`cat-${cat.id}`"
                                style="display: flex; align-items: center; gap: 10px; padding: 12px 14px; background: #fff; border: 1px solid var(--border); border-radius: var(--radius-md);"
                            >
                                <div>
                                    <div style="font: 600 14px/1.2 'Inter', sans-serif;">{{ cat.name }}</div>
                                    <div v-if="cat.description" style="font: 400 12px/1.3 'Inter', sans-serif; color: var(--text-secondary); margin-top: 2px;">{{ cat.description }}</div>
                                </div>
                                <span class="badge badge-inactive badge-sm" style="margin-left: auto;">
                                    {{ (selectedProduct.plans ?? []).filter((p) => p.category_id === cat.id).length }} plans
                                </span>
                                <span class="badge badge-sm" :class="cat.is_public ? 'badge-info' : 'badge-inactive'">{{ cat.is_public ? 'Public' : 'Private' }}</span>
                                <Menu as="div" class="dd-menu">
                                    <MenuButton class="icon-btn" aria-label="Category actions">
                                        <IconDots :size="16" stroke-width="1.75" />
                                    </MenuButton>
                                    <MenuItems class="dd-popover right-align">
                                        <MenuItem v-slot="{ active }">
                                            <button type="button" :class="['dd-option', { active }]" @click="openEditCategory(cat)">Edit category</button>
                                        </MenuItem>
                                        <div style="height: 1px; background: var(--border-soft); margin: 4px 0;" />
                                        <MenuItem v-slot="{ active }">
                                            <button type="button" :class="['dd-option', { active }]" style="color: var(--danger);" @click="askDeleteCategory(cat)">Delete category</button>
                                        </MenuItem>
                                    </MenuItems>
                                </Menu>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ Plans section, grouped by category ═══ -->
                    <div style="margin-top: 28px; padding-top: 20px; border-top: 1px solid var(--border);">
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 14px;">
                            <h3 style="margin: 0; font: 600 16px/1.2 'Inter', sans-serif;">Pricing plans</h3>
                            <span class="badge badge-inactive badge-sm">{{ selectedProduct.plans?.length || 0 }} plans</span>
                            <button
                                type="button"
                                class="btn btn-ghost btn-sm"
                                style="margin-left: auto; color: var(--accent);"
                                @click="openCreatePlan(null)"
                            >
                                <IconPlus :size="14" stroke-width="1.75" />
                                Add plan
                            </button>
                        </div>

                        <div v-if="(selectedProduct.plans?.length ?? 0) === 0" style="border: 1.5px dashed var(--border); border-radius: var(--radius-md); padding: 28px 16px; text-align: center;">
                            <IconTag :size="24" stroke-width="1.5" style="color: var(--text-tertiary); margin-bottom: 8px;" />
                            <div style="font: 600 14px/1.3 'Inter', sans-serif;">No plans yet</div>
                            <div style="font: 400 13px/1.4 'Inter', sans-serif; color: var(--text-secondary); margin-top: 4px;">
                                Add your first plan to start enrolling customers.
                            </div>
                            <button type="button" class="btn btn-ghost btn-sm" style="margin-top: 12px; color: var(--accent);" @click="openCreatePlan(null)">
                                <IconPlus :size="14" stroke-width="1.75" />
                                Add plan
                            </button>
                        </div>

                        <!-- Categorised groups -->
                        <div v-for="group in categorisedPlans.categories" :key="`grp-${group.id}`" style="margin-bottom: 18px;">
                            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px; padding-left: 10px; border-left: 2px solid var(--accent);">
                                <span style="font: 600 11px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary);">
                                    {{ group.name }}
                                </span>
                                <button type="button" class="btn btn-ghost btn-sm" style="margin-left: auto; color: var(--accent);" @click="openCreatePlan(group.id)">
                                    <IconPlus :size="13" stroke-width="1.75" />
                                    Add plan to {{ group.name }}
                                </button>
                            </div>
                            <div v-if="group.plans.length === 0" style="padding: 12px 14px; background: var(--neutral-bg); border-radius: var(--radius-md); color: var(--text-secondary); font: 400 12px/1.4 'Inter', sans-serif;">
                                No plans in this category yet.
                            </div>
                            <div v-else style="display: flex; flex-direction: column; gap: 10px;">
                                <component :is="'div'" v-for="plan in group.plans" :key="plan.id">
                                    <!-- Plan card body -->
                                    <div style="background: #fff; border: 1px solid var(--border); border-radius: var(--radius-md); padding: 16px 18px;" :style="! plan.is_active ? 'opacity: .6;' : ''">
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div style="font: 600 15px/1.2 'Inter', sans-serif;">{{ plan.name }}</div>
                                            <span class="badge badge-inactive badge-sm">{{ plan.features.length }} features</span>
                                            <div style="margin-left: auto; display: flex; align-items: center; gap: 6px;">
                                                <span class="badge badge-sm" :class="plan.is_active ? 'badge-active' : 'badge-inactive'">{{ plan.is_active ? 'Active' : 'Inactive' }}</span>
                                                <span class="badge badge-sm" :class="plan.is_public ? 'badge-info' : 'badge-inactive'">{{ plan.is_public ? 'Public' : 'Private' }}</span>
                                                <Menu as="div" class="dd-menu">
                                                    <MenuButton class="icon-btn" aria-label="Plan actions">
                                                        <IconDots :size="16" stroke-width="1.75" />
                                                    </MenuButton>
                                                    <MenuItems class="dd-popover right-align">
                                                        <MenuItem v-slot="{ active }">
                                                            <button type="button" :class="['dd-option', { active }]" @click="openEditPlan(plan)">Edit plan</button>
                                                        </MenuItem>
                                                        <MenuItem v-slot="{ active }">
                                                            <button type="button" :class="['dd-option', { active }]" @click="openCreatePrice(plan)">Add price</button>
                                                        </MenuItem>
                                                        <MenuItem v-slot="{ active }">
                                                            <button type="button" :class="['dd-option', { active }]" @click="togglePlanActive(plan)">{{ plan.is_active ? 'Deactivate' : 'Activate' }}</button>
                                                        </MenuItem>
                                                        <div style="height: 1px; background: var(--border-soft); margin: 4px 0;" />
                                                        <MenuItem v-slot="{ active }">
                                                            <button type="button" :class="['dd-option', { active }]" style="color: var(--danger);" @click="askDeletePlan(plan)">Delete plan</button>
                                                        </MenuItem>
                                                    </MenuItems>
                                                </Menu>
                                            </div>
                                        </div>
                                        <div v-if="plan.description" style="font: 400 13px/1.5 'Inter', sans-serif; color: var(--text-secondary); margin-top: 6px;">{{ plan.description }}</div>
                                        <div v-if="plan.features.length" style="font: 400 12px/1.5 'Inter', sans-serif; color: var(--text-secondary); margin-top: 8px;">
                                            <template v-for="(f, i) in plan.features" :key="i"><span v-if="i > 0"> · </span>✓ {{ f }}</template>
                                        </div>

                                        <!-- Pricing options subsection -->
                                        <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border-soft);">
                                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                                <span style="font: 500 10px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary);">Pricing options</span>
                                                <button type="button" class="btn btn-ghost btn-sm" style="margin-left: auto; color: var(--accent);" @click="openCreatePrice(plan)">
                                                    <IconPlus :size="13" stroke-width="1.75" />
                                                    Add price
                                                </button>
                                            </div>
                                            <div v-if="(plan.prices ?? []).length === 0" style="font: 400 12px/1.4 'Inter', sans-serif; color: var(--text-tertiary); font-style: italic;">
                                                No pricing set · add a price to enable subscriptions
                                            </div>
                                            <div v-else style="display: flex; flex-direction: column; gap: 6px;">
                                                <div v-for="price in plan.prices" :key="`pp-${price.id}`" style="display: flex; align-items: center; gap: 10px; padding: 8px 10px; border: 1px solid var(--border-soft); border-radius: var(--radius-sm);">
                                                    <span class="badge badge-sm badge-inactive">{{ price.interval_label }}</span>
                                                    <span style="font: 600 14px/1.2 'Inter', sans-serif; color: var(--accent);">{{ gbp(price.price) }}</span>
                                                    <span v-if="price.stripe_price_id" style="display: inline-flex; align-items: center; gap: 4px; padding: 1px 6px; background: var(--info-bg); color: var(--info); border-radius: 4px; font: 600 10px/1.3 'JetBrains Mono', monospace;">
                                                        <IconBrandStripe :size="10" stroke-width="2" />
                                                        Stripe
                                                    </span>
                                                    <span v-if="price.is_default" class="badge badge-sm badge-active">Default</span>
                                                    <span v-if="price.label" class="badge badge-sm badge-pending">{{ price.label }}</span>
                                                    <span style="margin-left: auto; font: 400 11px/1.3 'Inter', sans-serif; color: var(--text-tertiary);">
                                                        {{ price.active_customers }} sub{{ price.active_customers === 1 ? '' : 's' }}
                                                    </span>
                                                    <Menu as="div" class="dd-menu">
                                                        <MenuButton class="icon-btn" aria-label="Price actions">
                                                            <IconDots :size="14" stroke-width="1.75" />
                                                        </MenuButton>
                                                        <MenuItems class="dd-popover right-align">
                                                            <MenuItem v-slot="{ active }">
                                                                <button type="button" :class="['dd-option', { active }]" @click="openEditPrice(plan, price)">Edit price</button>
                                                            </MenuItem>
                                                            <div style="height: 1px; background: var(--border-soft); margin: 4px 0;" />
                                                            <MenuItem v-slot="{ active }">
                                                                <button type="button" :class="['dd-option', { active }]" style="color: var(--danger);" @click="askDeletePrice(price)">Delete price</button>
                                                            </MenuItem>
                                                        </MenuItems>
                                                    </Menu>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </component>
                            </div>
                        </div>

                        <!-- Uncategorised group -->
                        <div v-if="categorisedPlans.uncategorised.length > 0" style="margin-top: 18px;">
                            <div style="font: 600 11px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary); margin-bottom: 10px; padding-left: 10px; border-left: 2px solid var(--text-tertiary);">
                                Uncategorised
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 10px;">
                                <div v-for="plan in categorisedPlans.uncategorised" :key="plan.id" style="background: #fff; border: 1px solid var(--border); border-radius: var(--radius-md); padding: 16px 18px;" :style="! plan.is_active ? 'opacity: .6;' : ''">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <div style="font: 600 15px/1.2 'Inter', sans-serif;">{{ plan.name }}</div>
                                        <span class="badge badge-inactive badge-sm">{{ plan.features.length }} features</span>
                                        <div style="margin-left: auto; display: flex; align-items: center; gap: 6px;">
                                            <span class="badge badge-sm" :class="plan.is_active ? 'badge-active' : 'badge-inactive'">{{ plan.is_active ? 'Active' : 'Inactive' }}</span>
                                            <span class="badge badge-sm" :class="plan.is_public ? 'badge-info' : 'badge-inactive'">{{ plan.is_public ? 'Public' : 'Private' }}</span>
                                            <Menu as="div" class="dd-menu">
                                                <MenuButton class="icon-btn" aria-label="Plan actions">
                                                    <IconDots :size="16" stroke-width="1.75" />
                                                </MenuButton>
                                                <MenuItems class="dd-popover right-align">
                                                    <MenuItem v-slot="{ active }">
                                                        <button type="button" :class="['dd-option', { active }]" @click="openEditPlan(plan)">Edit plan</button>
                                                    </MenuItem>
                                                    <MenuItem v-slot="{ active }">
                                                        <button type="button" :class="['dd-option', { active }]" @click="openCreatePrice(plan)">Add price</button>
                                                    </MenuItem>
                                                    <MenuItem v-slot="{ active }">
                                                        <button type="button" :class="['dd-option', { active }]" @click="togglePlanActive(plan)">{{ plan.is_active ? 'Deactivate' : 'Activate' }}</button>
                                                    </MenuItem>
                                                    <div style="height: 1px; background: var(--border-soft); margin: 4px 0;" />
                                                    <MenuItem v-slot="{ active }">
                                                        <button type="button" :class="['dd-option', { active }]" style="color: var(--danger);" @click="askDeletePlan(plan)">Delete plan</button>
                                                    </MenuItem>
                                                </MenuItems>
                                            </Menu>
                                        </div>
                                    </div>
                                    <div v-if="plan.description" style="font: 400 13px/1.5 'Inter', sans-serif; color: var(--text-secondary); margin-top: 6px;">{{ plan.description }}</div>
                                    <div v-if="plan.features.length" style="font: 400 12px/1.5 'Inter', sans-serif; color: var(--text-secondary); margin-top: 8px;">
                                        <template v-for="(f, i) in plan.features" :key="i"><span v-if="i > 0"> · </span>✓ {{ f }}</template>
                                    </div>
                                    <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border-soft);">
                                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                            <span style="font: 500 10px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary);">Pricing options</span>
                                            <button type="button" class="btn btn-ghost btn-sm" style="margin-left: auto; color: var(--accent);" @click="openCreatePrice(plan)">
                                                <IconPlus :size="13" stroke-width="1.75" />
                                                Add price
                                            </button>
                                        </div>
                                        <div v-if="(plan.prices ?? []).length === 0" style="font: 400 12px/1.4 'Inter', sans-serif; color: var(--text-tertiary); font-style: italic;">
                                            No pricing set · add a price to enable subscriptions
                                        </div>
                                        <div v-else style="display: flex; flex-direction: column; gap: 6px;">
                                            <div v-for="price in plan.prices" :key="`pp-${price.id}`" style="display: flex; align-items: center; gap: 10px; padding: 8px 10px; border: 1px solid var(--border-soft); border-radius: var(--radius-sm);">
                                                <span class="badge badge-sm badge-inactive">{{ price.interval_label }}</span>
                                                <span style="font: 600 14px/1.2 'Inter', sans-serif; color: var(--accent);">{{ gbp(price.price) }}</span>
                                                <span v-if="price.stripe_price_id" style="display: inline-flex; align-items: center; gap: 4px; padding: 1px 6px; background: var(--info-bg); color: var(--info); border-radius: 4px; font: 600 10px/1.3 'JetBrains Mono', monospace;">
                                                    <IconBrandStripe :size="10" stroke-width="2" />
                                                    Stripe
                                                </span>
                                                <span v-if="price.is_default" class="badge badge-sm badge-active">Default</span>
                                                <span v-if="price.label" class="badge badge-sm badge-pending">{{ price.label }}</span>
                                                <span style="margin-left: auto; font: 400 11px/1.3 'Inter', sans-serif; color: var(--text-tertiary);">
                                                    {{ price.active_customers }} sub{{ price.active_customers === 1 ? '' : 's' }}
                                                </span>
                                                <Menu as="div" class="dd-menu">
                                                    <MenuButton class="icon-btn" aria-label="Price actions">
                                                        <IconDots :size="14" stroke-width="1.75" />
                                                    </MenuButton>
                                                    <MenuItems class="dd-popover right-align">
                                                        <MenuItem v-slot="{ active }">
                                                            <button type="button" :class="['dd-option', { active }]" @click="openEditPrice(plan, price)">Edit price</button>
                                                        </MenuItem>
                                                        <div style="height: 1px; background: var(--border-soft); margin: 4px 0;" />
                                                        <MenuItem v-slot="{ active }">
                                                            <button type="button" :class="['dd-option', { active }]" style="color: var(--danger);" @click="askDeletePrice(price)">Delete price</button>
                                                        </MenuItem>
                                                    </MenuItems>
                                                </Menu>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
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

        <ConfirmModal
            v-model:show="showSwitchModal"
            title="Discard unsaved changes?"
            message="You have unsaved changes on the current product. Switching will discard them."
            confirm-label="Discard and switch"
            variant="warning"
            @confirm="handleSwitchConfirm"
        />

        <!-- Add / Edit plan slide-over -->
        <TransitionRoot as="template" :show="showPlanModal">
            <Dialog as="div" class="slide-over-dialog" @close="showPlanModal = false">
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
                    <DialogPanel class="slide-over-panel" style="width: 520px;">
                        <form class="slide-over-form" @submit.prevent="submitPlan">
                            <header class="slide-over-header">
                                <h2>{{ planMode === 'create' ? 'Add plan' : 'Edit plan' }}</h2>
                                <button type="button" class="icon-btn" aria-label="Close" @click="showPlanModal = false">
                                    <IconX :size="18" stroke-width="1.75" />
                                </button>
                            </header>

                            <div class="slide-over-body">
                                <div class="form-section">
                                    <h3>Basic info</h3>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Plan name<span class="req">*</span></label>
                                            <input v-model="planForm.name" type="text" :class="{ 'has-err': planForm.errors.name }" placeholder="e.g. Pro, Basic, Enterprise" required>
                                            <div v-if="planForm.errors.name" class="err">{{ planForm.errors.name }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Description</label>
                                            <textarea v-model="planForm.description" rows="2" placeholder="What's included in this plan" />
                                            <div v-if="planForm.errors.description" class="err">{{ planForm.errors.description }}</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <h3>Category</h3>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Group this plan under</label>
                                            <select v-model="planForm.category_id">
                                                <option :value="null">— Uncategorised —</option>
                                                <option v-for="c in (selectedProduct?.plan_categories ?? [])" :key="c.id" :value="c.id">{{ c.name }}</option>
                                            </select>
                                            <div v-if="planForm.errors.category_id" class="err">{{ planForm.errors.category_id }}</div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Initial price (create-only convenience block) -->
                                <div v-if="planMode === 'create'" class="form-section">
                                    <button v-if="! showInitialPriceSection" type="button" class="collapsible-trigger" @click="showInitialPriceSection = true">
                                        <IconPlus :size="14" stroke-width="2" />
                                        Add first pricing option
                                    </button>
                                    <template v-else>
                                        <h3>First pricing option <span style="color: var(--text-tertiary); font-weight: 400; font-size: 12px;">— optional</span></h3>
                                        <div class="form-row single">
                                            <div class="form-field">
                                                <label>Price (£)</label>
                                                <input v-model.number="planForm.initial_price" type="number" min="0" step="0.01">
                                                <div v-if="planForm.errors.initial_price" class="err">{{ planForm.errors.initial_price }}</div>
                                            </div>
                                        </div>
                                        <div class="form-row single" style="margin-top: 12px;">
                                            <div class="form-field">
                                                <label>Billing interval</label>
                                                <IntervalPicker v-model="initialInterval" />
                                                <div class="field-help">You can add more pricing options after creating the plan.</div>
                                            </div>
                                        </div>
                                    </template>
                                </div>

                                <div class="form-section">
                                    <h3>Features <span style="color: var(--text-tertiary); font-weight: 400; font-size: 12px;">— drag to reorder · shown on pricing page</span></h3>
                                    <div
                                        v-for="(f, i) in planForm.features"
                                        :key="'feat-' + i"
                                        class="feat-row"
                                        :class="{ 'feat-drag-over': featureDragOverIndex === i }"
                                        draggable="true"
                                        @dragstart="onFeatureDragStart($event, i)"
                                        @dragover.prevent="onFeatureDragOver($event, i)"
                                        @dragleave="onFeatureDragLeave"
                                        @drop.prevent="onFeatureDrop($event, i)"
                                        @dragend="onFeatureDragEnd"
                                    >
                                        <div class="feat-handle" title="Drag to reorder">
                                            <IconGripVertical :size="16" stroke-width="1.75" />
                                        </div>
                                        <input
                                            :value="f"
                                            type="text"
                                            class="feat-input"
                                            maxlength="200"
                                            placeholder="e.g. Unlimited orders"
                                            @input="planForm.features[i] = $event.target.value"
                                        >
                                        <button
                                            type="button"
                                            class="feat-remove"
                                            :disabled="planForm.features.length === 1"
                                            title="Remove feature"
                                            @click="removeFeature(i)"
                                        >
                                            <IconX :size="14" stroke-width="1.75" />
                                        </button>
                                    </div>
                                    <div v-if="planForm.features.length < 10" style="display: flex; gap: 8px;">
                                        <input
                                            v-model="newFeature"
                                            type="text"
                                            maxlength="200"
                                            placeholder="e.g. Unlimited orders"
                                            style="flex: 1;"
                                            @keydown.enter.prevent="addFeature"
                                        >
                                        <button type="button" class="btn btn-ghost btn-sm" style="color: var(--accent);" @click="addFeature">
                                            <IconPlus :size="14" stroke-width="1.75" />
                                            Add
                                        </button>
                                    </div>
                                    <div v-if="planForm.features.length >= 10" class="field-help">Maximum of 10 features.</div>
                                </div>

                                <div class="form-section">
                                    <h3>Visibility</h3>
                                    <div class="status-rows">
                                        <div class="set-row">
                                            <div>
                                                <div class="nm">Active</div>
                                                <div class="sb">Show in the new-subscription plan picker.</div>
                                            </div>
                                            <button type="button" class="toggle" :class="{ on: planForm.is_active }" aria-label="Toggle active" @click="planForm.is_active = ! planForm.is_active" />
                                        </div>
                                        <div class="set-row">
                                            <div>
                                                <div class="nm">Public</div>
                                                <div class="sb">Expose on the public pricing API.</div>
                                            </div>
                                            <button type="button" class="toggle" :class="{ on: planForm.is_public }" aria-label="Toggle public" @click="planForm.is_public = ! planForm.is_public" />
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <footer class="slide-over-footer">
                                <button type="button" class="btn btn-secondary" @click="showPlanModal = false">Cancel</button>
                                <button type="submit" class="btn btn-primary" :disabled="planForm.processing">
                                    <IconDeviceFloppy :size="15" stroke-width="1.75" />
                                    {{ planForm.processing ? 'Saving…' : 'Save plan' }}
                                </button>
                            </footer>
                        </form>
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </TransitionRoot>

        <ConfirmModal
            v-model:show="showDeletePlanModal"
            :title="deletePlanTarget ? `Delete plan '${deletePlanTarget.name}'?` : 'Delete plan?'"
            :message="deletePlanMessage"
            confirm-label="Delete plan"
            variant="danger"
            :loading="deletePlanProcessing"
            @confirm="handleDeletePlan"
        />

        <!-- Add / Edit category slide-over -->
        <TransitionRoot as="template" :show="showCategoryModal">
            <Dialog as="div" class="slide-over-dialog" @close="showCategoryModal = false">
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
                    <DialogPanel class="slide-over-panel" style="width: 400px;">
                        <form class="slide-over-form" @submit.prevent="submitCategory">
                            <header class="slide-over-header">
                                <h2>{{ categoryMode === 'create' ? 'Add category' : 'Edit category' }}</h2>
                                <button type="button" class="icon-btn" aria-label="Close" @click="showCategoryModal = false">
                                    <IconX :size="18" stroke-width="1.75" />
                                </button>
                            </header>
                            <div class="slide-over-body">
                                <div class="form-section">
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Name<span class="req">*</span></label>
                                            <input v-model="categoryForm.name" type="text" :class="{ 'has-err': categoryForm.errors.name }" placeholder="e.g. Hosting, Branding" required>
                                            <div v-if="categoryForm.errors.name" class="err">{{ categoryForm.errors.name }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Description <span style="color: var(--text-tertiary); font-weight: 400;">(optional)</span></label>
                                            <textarea v-model="categoryForm.description" rows="2" placeholder="Shown above this group on the pricing page" />
                                            <div v-if="categoryForm.errors.description" class="err">{{ categoryForm.errors.description }}</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-section">
                                    <h3>Visibility</h3>
                                    <div class="status-rows">
                                        <div class="set-row">
                                            <div>
                                                <div class="nm">Public</div>
                                                <div class="sb">Show on the public pricing page.</div>
                                            </div>
                                            <button type="button" class="toggle" :class="{ on: categoryForm.is_public }" aria-label="Toggle public" @click="categoryForm.is_public = ! categoryForm.is_public" />
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <footer class="slide-over-footer">
                                <button type="button" class="btn btn-secondary" @click="showCategoryModal = false">Cancel</button>
                                <button type="submit" class="btn btn-primary" :disabled="categoryForm.processing">
                                    <IconDeviceFloppy :size="15" stroke-width="1.75" />
                                    {{ categoryForm.processing ? 'Saving…' : 'Save category' }}
                                </button>
                            </footer>
                        </form>
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </TransitionRoot>

        <ConfirmModal
            v-model:show="showDeleteCategoryModal"
            :title="deleteCategoryTarget ? `Delete '${deleteCategoryTarget.name}'?` : 'Delete category?'"
            :message="deleteCategoryMessage"
            confirm-label="Delete category"
            variant="danger"
            :loading="deleteCategoryProcessing"
            @confirm="handleDeleteCategory"
        />

        <!-- Add / Edit price slide-over -->
        <TransitionRoot as="template" :show="showPriceModal">
            <Dialog as="div" class="slide-over-dialog" @close="showPriceModal = false">
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
                    <DialogPanel class="slide-over-panel" style="width: 440px;">
                        <form class="slide-over-form" @submit.prevent="submitPrice">
                            <header class="slide-over-header">
                                <h2>{{ priceMode === 'create' ? 'Add pricing option' : 'Edit price' }}</h2>
                                <button type="button" class="icon-btn" aria-label="Close" @click="showPriceModal = false">
                                    <IconX :size="18" stroke-width="1.75" />
                                </button>
                            </header>
                            <div class="slide-over-body">
                                <div v-if="priceContextPlan" style="padding: 10px 14px; background: var(--neutral-bg); border-radius: var(--radius-md); display: flex; align-items: center; gap: 10px;">
                                    <span class="badge badge-inactive badge-sm">{{ priceContextPlan.name }}</span>
                                    <span style="font: 400 12px/1.3 'Inter', sans-serif; color: var(--text-secondary); margin-left: auto;">on {{ selectedProduct?.name }}</span>
                                </div>
                                <div class="form-section">
                                    <h3>Price &amp; interval</h3>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Price (£)<span class="req">*</span></label>
                                            <input v-model.number="priceForm.price" type="number" min="0" step="0.01" required>
                                            <div v-if="priceForm.errors.price" class="err">{{ priceForm.errors.price }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single" style="margin-top: 12px;">
                                        <div class="form-field">
                                            <label>Billing interval<span class="req">*</span></label>
                                            <IntervalPicker v-model="priceInterval" />
                                            <div v-if="priceForm.errors.interval_count" class="err">{{ priceForm.errors.interval_count }}</div>
                                            <div v-if="priceForm.errors.interval_unit" class="err">{{ priceForm.errors.interval_unit }}</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-section">
                                    <h3>Marketing</h3>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Stripe Price ID <span style="color: var(--text-tertiary); font-weight: 400;">(optional)</span></label>
                                            <input v-model="priceForm.stripe_price_id" type="text" placeholder="price_1Nxxx..." style="font-family: 'JetBrains Mono', monospace;">
                                            <div v-if="priceForm.errors.stripe_price_id" class="err">{{ priceForm.errors.stripe_price_id }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Label <span style="color: var(--text-tertiary); font-weight: 400;">(optional)</span></label>
                                            <input v-model="priceForm.label" type="text" placeholder="e.g. Best value, Most popular" maxlength="100">
                                            <div class="field-help">Shown as a badge on this price option.</div>
                                            <div v-if="priceForm.errors.label" class="err">{{ priceForm.errors.label }}</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-section">
                                    <h3>Visibility</h3>
                                    <div class="status-rows">
                                        <div class="set-row">
                                            <div>
                                                <div class="nm">Default</div>
                                                <div class="sb">Used when enabling this plan without picking a price.</div>
                                            </div>
                                            <button type="button" class="toggle" :class="{ on: priceForm.is_default }" aria-label="Toggle default" @click="priceForm.is_default = ! priceForm.is_default" />
                                        </div>
                                        <div class="set-row">
                                            <div>
                                                <div class="nm">Active</div>
                                                <div class="sb">Show in the new-subscription price picker.</div>
                                            </div>
                                            <button type="button" class="toggle" :class="{ on: priceForm.is_active }" aria-label="Toggle active" @click="priceForm.is_active = ! priceForm.is_active" />
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <footer class="slide-over-footer">
                                <button type="button" class="btn btn-secondary" @click="showPriceModal = false">Cancel</button>
                                <button type="submit" class="btn btn-primary" :disabled="priceForm.processing">
                                    <IconDeviceFloppy :size="15" stroke-width="1.75" />
                                    {{ priceForm.processing ? 'Saving…' : 'Save price' }}
                                </button>
                            </footer>
                        </form>
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </TransitionRoot>

        <ConfirmModal
            v-model:show="showDeletePriceModal"
            title="Delete pricing option?"
            message="This can't be undone. Subscriptions on this price won't be reassigned automatically."
            confirm-label="Delete price"
            variant="danger"
            :loading="deletePriceProcessing"
            @confirm="handleDeletePrice"
        />
    </SettingsLayout>
</template>
