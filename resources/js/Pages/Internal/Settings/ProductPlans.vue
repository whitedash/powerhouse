<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
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
    IconArrowLeft,
    IconArrowRight,
    IconPlus,
    IconX,
    IconDeviceFloppy,
    IconCheck,
    IconDots,
    IconPencil,
    IconTrash,
    IconFolder,
    IconTag,
    IconGripVertical,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';
import IntervalPicker from '@/Components/UI/IntervalPicker.vue';

const props = defineProps({
    product: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    uncategorised: { type: Array, default: () => [] },
});

const breadcrumbs = computed(() => [
    { label: 'Settings', href: '/settings' },
    { label: 'Products', href: '/settings/products' },
    { label: props.product.name, href: `/settings/products/${props.product.id}/plans` },
    { label: 'Plans' },
]);

/* ─── Sidebar filter ─── */
const activeFilter = ref('all'); // 'all' | category.id | 'uncategorised'

function setFilter(value) {
    activeFilter.value = value;
}

const totalPlanCount = computed(
    () => props.categories.reduce((acc, c) => acc + c.plans.length, 0) + props.uncategorised.length,
);

/* ─── Visible groups based on the filter ─── */
const visibleCategories = computed(() => {
    if (activeFilter.value === 'all') return props.categories;
    if (activeFilter.value === 'uncategorised') return [];
    return props.categories.filter((c) => c.id === activeFilter.value);
});
const visibleUncategorised = computed(() => {
    if (activeFilter.value === 'all' || activeFilter.value === 'uncategorised') return props.uncategorised;
    return [];
});

/* ─── Helpers ─── */
function gbp(n) {
    return '£' + Number(n || 0).toFixed(2);
}
function intervalBadgeClass(price) {
    const u = price.interval_unit;
    const c = price.interval_count;
    if (u === 'one_time') return 'badge-inactive';
    if (c === 1 && u === 'month') return 'badge-active';
    if (u === 'month' && [3, 6, 12].includes(c)) return 'badge-gold';
    if (u === 'year') return 'badge-gold';
    return 'badge-info';
}

/* ─── Category slide-over ─── */
const showCategoryModal = ref(false);
const categoryMode = ref('create');
const editingCategoryId = ref(null);
const categoryForm = useForm({
    product_id: props.product.id,
    name: '',
    description: '',
    sort_order: 0,
    is_public: true,
});

function openCreateCategory() {
    categoryMode.value = 'create';
    editingCategoryId.value = null;
    categoryForm.defaults({
        product_id: props.product.id, name: '', description: '', sort_order: 0, is_public: true,
    });
    categoryForm.reset();
    categoryForm.clearErrors();
    showCategoryModal.value = true;
}
function openEditCategory(cat) {
    categoryMode.value = 'edit';
    editingCategoryId.value = cat.id;
    categoryForm.defaults({
        product_id: props.product.id,
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
    const onSuccess = () => {
        showCategoryModal.value = false;
        router.reload({ only: ['categories', 'uncategorised'] });
    };
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

/* ─── Plan slide-over ─── */
const PLAN_DEFAULTS = {
    product_id: props.product.id,
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
const planMode = ref('create');
const editingPlanId = ref(null);
const planForm = useForm({ ...PLAN_DEFAULTS });
const newFeature = ref('');
const showInitialPriceSection = ref(false);

const initialInterval = computed({
    get: () => ({ count: planForm.initial_interval_count, unit: planForm.initial_interval_unit }),
    set: (v) => {
        planForm.initial_interval_count = v.count;
        planForm.initial_interval_unit = v.unit;
    },
});

function openCreatePlan(categoryId = null) {
    planMode.value = 'create';
    editingPlanId.value = null;
    planForm.defaults({ ...PLAN_DEFAULTS, category_id: categoryId });
    planForm.reset();
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
function submitPlan() {
    const onSuccess = () => {
        showPlanModal.value = false;
        router.reload({ only: ['categories', 'uncategorised'] });
    };
    if (planMode.value === 'create') {
        planForm.post('/settings/plans', { preserveScroll: true, onSuccess });
    } else {
        planForm.put(`/settings/plans/${editingPlanId.value}`, { preserveScroll: true, onSuccess });
    }
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

/* ─── Drag-to-reorder features ─── */
const featureDragIndex = ref(null);
const featureDragOverIndex = ref(null);
function onFeatureDragStart(event, index) {
    featureDragIndex.value = index;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', String(index));
    event.target.classList.add('feat-dragging');
}
function onFeatureDragOver(event, index) {
    if (featureDragIndex.value === null || featureDragIndex.value === index) return;
    featureDragOverIndex.value = index;
}
function onFeatureDragLeave() { featureDragOverIndex.value = null; }
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
    event.target.classList.remove('feat-dragging');
    featureDragIndex.value = null;
    featureDragOverIndex.value = null;
}

function togglePlanActive(plan) {
    router.post(`/settings/plans/${plan.id}/toggle`, {}, {
        preserveScroll: true,
        onSuccess: () => router.reload({ only: ['categories', 'uncategorised'] }),
    });
}

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
const deletePlanMessage = computed(() => deletePlanTarget.value
    ? `Delete plan '${deletePlanTarget.value.name}'? This can't be undone.`
    : '');

/* ─── Price slide-over ─── */
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
const priceInterval = computed({
    get: () => ({ count: priceForm.interval_count, unit: priceForm.interval_unit }),
    set: (v) => {
        priceForm.interval_count = v.count;
        priceForm.interval_unit = v.unit;
    },
});

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
    const onSuccess = () => {
        showPriceModal.value = false;
        router.reload({ only: ['categories', 'uncategorised'] });
    };
    if (priceMode.value === 'create') {
        priceForm.post('/settings/plan-prices', { preserveScroll: true, onSuccess });
    } else {
        priceForm.put(`/settings/plan-prices/${editingPriceId.value}`, { preserveScroll: true, onSuccess });
    }
}

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

/* ─── Feature expansion (per-card) ─── */
const expandedFeatures = ref({});
function toggleFeatures(planId) {
    expandedFeatures.value = { ...expandedFeatures.value, [planId]: ! expandedFeatures.value[planId] };
}
function visibleFeatures(plan) {
    return expandedFeatures.value[plan.id] ? plan.features : plan.features.slice(0, 4);
}

/* ─── Navigation ─── */
function back() {
    router.visit('/settings/products');
}
</script>

<template>
    <Head :title="`${product.name} — Plans`" />

    <InternalLayout :title="`${product.name} — Plans`" :breadcrumbs="breadcrumbs" active-nav="settings">
        <template #topbar-actions>
            <button type="button" class="btn btn-ghost btn-sm" @click="back">
                <IconArrowLeft :size="14" stroke-width="1.75" />
                Back to products
            </button>
            <button type="button" class="btn btn-secondary" @click="openCreateCategory">
                <IconPlus :size="14" stroke-width="1.75" />
                Add category
            </button>
            <button type="button" class="btn btn-primary" @click="openCreatePlan(null)">
                <IconPlus :size="14" stroke-width="1.75" />
                Add plan
            </button>
        </template>

        <div class="product-plans-layout">
            <!-- LEFT — category filter sidebar -->
            <aside class="plans-sidebar">
                <div class="plans-sidebar-header">Categories</div>
                <div class="plans-filter-item" :class="{ active: activeFilter === 'all' }" @click="setFilter('all')">
                    <span>All plans</span>
                    <span class="plans-filter-count">{{ totalPlanCount }}</span>
                </div>
                <div
                    v-for="cat in categories"
                    :key="`fc-${cat.id}`"
                    class="plans-filter-item"
                    :class="{ active: activeFilter === cat.id }"
                    @click="setFilter(cat.id)"
                >
                    <span>{{ cat.name }}</span>
                    <span class="plans-filter-count">{{ cat.plans.length }}</span>
                </div>
                <div
                    v-if="uncategorised.length > 0"
                    class="plans-filter-item"
                    :class="{ active: activeFilter === 'uncategorised' }"
                    @click="setFilter('uncategorised')"
                >
                    <span>Uncategorised</span>
                    <span class="plans-filter-count">{{ uncategorised.length }}</span>
                </div>
                <div style="height: 1px; background: var(--border-soft); margin: 4px 0;" />
                <button type="button" class="plans-filter-item" style="color: var(--accent); width: 100%; background: transparent; border: 0; cursor: pointer; text-align: left; font: 500 13px/1.3 'Inter', sans-serif;" @click="openCreateCategory">
                    <span style="display: flex; align-items: center; gap: 6px;">
                        <IconPlus :size="13" stroke-width="1.75" />
                        Add category
                    </span>
                </button>
            </aside>

            <!-- RIGHT — main grid -->
            <section class="plans-main">
                <!-- Empty state -->
                <div v-if="totalPlanCount === 0" class="plans-empty">
                    <IconTag :size="48" stroke-width="1.5" />
                    <div class="plans-empty-title">{{ product.name }} has no plans yet</div>
                    <div class="plans-empty-sub">Create your first pricing plan to start enrolling customers.</div>
                    <button type="button" class="btn btn-primary" @click="openCreatePlan(null)">
                        <IconPlus :size="14" stroke-width="1.75" />
                        Add plan
                    </button>
                </div>

                <!-- Category groups -->
                <div v-for="cat in visibleCategories" :key="`cg-${cat.id}`" class="category-group">
                    <div class="category-header">
                        <div class="category-bar" />
                        <span class="category-name">{{ cat.name }}</span>
                        <span class="category-count">· {{ cat.plans.length }} plan{{ cat.plans.length === 1 ? '' : 's' }}</span>
                        <span v-if="cat.description" class="category-desc">· {{ cat.description }}</span>
                        <div style="margin-left: auto; display: flex; gap: 4px;">
                            <button type="button" class="btn btn-ghost btn-sm" @click="openEditCategory(cat)">
                                <IconPencil :size="13" stroke-width="1.75" />
                                Edit
                            </button>
                            <button type="button" class="btn btn-ghost btn-sm" style="color: var(--danger);" @click="askDeleteCategory(cat)">
                                <IconTrash :size="13" stroke-width="1.75" />
                                Delete
                            </button>
                        </div>
                    </div>
                    <div class="plans-grid">
                        <div v-for="plan in cat.plans" :key="`p-${plan.id}`" class="plan-card" :style="! plan.is_active ? 'opacity: .6;' : ''">
                            <!-- HEADER -->
                            <header class="plan-card-header">
                                <div class="plan-name">{{ plan.name }}</div>
                                <div style="display: flex; align-items: center; gap: 6px;">
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
                                            <div style="height: 1px; background: var(--border-soft); margin: 4px 0;" />
                                            <MenuItem v-slot="{ active }">
                                                <button type="button" :class="['dd-option', { active }]" @click="togglePlanActive(plan)">{{ plan.is_active ? 'Deactivate' : 'Activate' }}</button>
                                            </MenuItem>
                                            <MenuItem v-slot="{ active }">
                                                <button type="button" :class="['dd-option', { active }]" style="color: var(--danger);" @click="askDeletePlan(plan)">Delete plan</button>
                                            </MenuItem>
                                        </MenuItems>
                                    </Menu>
                                </div>
                            </header>

                            <!-- PRICES -->
                            <div class="plan-section">
                                <div class="plan-section-label">Pricing</div>
                                <div v-if="plan.prices.length === 0" style="font: 400 12px/1.4 'Inter', sans-serif; color: var(--text-tertiary); font-style: italic;">No pricing set</div>
                                <div v-else>
                                    <div v-for="price in plan.prices" :key="`pp-${price.id}`" class="price-row">
                                        <span class="badge badge-sm" :class="intervalBadgeClass(price)">{{ price.interval_label }}</span>
                                        <span class="price-mid">
                                            <span class="price-amount">{{ gbp(price.price) }}</span>
                                            <span v-if="price.label" class="price-label-pill">{{ price.label }}</span>
                                            <span v-if="price.is_default" style="font: 400 11px/1.2 'Inter', sans-serif; color: var(--text-tertiary);">Default</span>
                                            <span v-if="price.active_customers > 0" class="price-subs">{{ price.active_customers }} {{ price.active_customers === 1 ? 'subscriber' : 'subscribers' }}</span>
                                        </span>
                                        <span class="price-actions">
                                            <button type="button" class="icon-btn" aria-label="Edit price" @click="openEditPrice(plan, price)">
                                                <IconPencil :size="13" stroke-width="1.75" />
                                            </button>
                                            <button type="button" class="icon-btn" style="color: var(--danger);" aria-label="Delete price" @click="askDeletePrice(price)">
                                                <IconTrash :size="13" stroke-width="1.75" />
                                            </button>
                                        </span>
                                    </div>
                                </div>
                                <button type="button" style="margin-top: 6px; background: none; border: 0; padding: 0; color: var(--accent); font: 500 12px/1.2 'Inter', sans-serif; cursor: pointer; display: flex; align-items: center; gap: 4px;" @click="openCreatePrice(plan)">
                                    <IconPlus :size="12" stroke-width="2" />
                                    Add price
                                </button>
                            </div>

                            <!-- FEATURES -->
                            <div class="plan-section plan-section-features">
                                <div class="plan-section-label">
                                    Features <span style="color: var(--text-tertiary);">· {{ plan.features.length }}</span>
                                </div>
                                <div v-if="plan.features.length === 0" style="font: 400 12px/1.4 'Inter', sans-serif; color: var(--text-tertiary); font-style: italic;">None set</div>
                                <div v-else class="feat-list">
                                    <div v-for="(f, i) in visibleFeatures(plan)" :key="`f-${plan.id}-${i}`" class="feat-item">
                                        <IconCheck :size="13" stroke-width="2" />
                                        <span>{{ f }}</span>
                                    </div>
                                    <button v-if="plan.features.length > 4" type="button" class="feat-more" @click="toggleFeatures(plan.id)">
                                        {{ expandedFeatures[plan.id] ? 'Show less' : `+ ${plan.features.length - 4} more` }}
                                    </button>
                                </div>
                            </div>

                            <!-- FOOTER -->
                            <footer class="plan-card-footer">
                                <span style="font: 400 12px/1.2 'Inter', sans-serif; color: var(--text-secondary);">
                                    {{ plan.active_customers }} active
                                    <template v-if="plan.mrr > 0">
                                        · <span style="color: var(--success);">{{ gbp(plan.mrr) }}/mo MRR</span>
                                    </template>
                                </span>
                                <button type="button" style="background: none; border: 0; padding: 0; color: var(--accent); font: 500 12px/1.2 'Inter', sans-serif; cursor: pointer; display: flex; align-items: center; gap: 4px;" @click="openEditPlan(plan)">
                                    Edit plan
                                    <IconArrowRight :size="12" stroke-width="1.75" />
                                </button>
                            </footer>
                        </div>

                        <!-- ADD PLAN dashed card -->
                        <button type="button" class="plan-add-card" @click="openCreatePlan(cat.id)">
                            <IconPlus :size="20" stroke-width="1.75" />
                            <span class="plan-add-label">Add plan</span>
                        </button>
                    </div>
                </div>

                <!-- Uncategorised group -->
                <div v-if="visibleUncategorised.length > 0" class="category-group">
                    <div class="category-header">
                        <div class="category-bar" style="background: var(--text-tertiary);" />
                        <span class="category-name">Uncategorised</span>
                        <span class="category-count">· {{ visibleUncategorised.length }} plan{{ visibleUncategorised.length === 1 ? '' : 's' }}</span>
                    </div>
                    <div class="plans-grid">
                        <div v-for="plan in visibleUncategorised" :key="`pu-${plan.id}`" class="plan-card" :style="! plan.is_active ? 'opacity: .6;' : ''">
                            <header class="plan-card-header">
                                <div class="plan-name">{{ plan.name }}</div>
                                <div style="display: flex; align-items: center; gap: 6px;">
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
                                            <div style="height: 1px; background: var(--border-soft); margin: 4px 0;" />
                                            <MenuItem v-slot="{ active }">
                                                <button type="button" :class="['dd-option', { active }]" @click="togglePlanActive(plan)">{{ plan.is_active ? 'Deactivate' : 'Activate' }}</button>
                                            </MenuItem>
                                            <MenuItem v-slot="{ active }">
                                                <button type="button" :class="['dd-option', { active }]" style="color: var(--danger);" @click="askDeletePlan(plan)">Delete plan</button>
                                            </MenuItem>
                                        </MenuItems>
                                    </Menu>
                                </div>
                            </header>
                            <div class="plan-section">
                                <div class="plan-section-label">Pricing</div>
                                <div v-if="plan.prices.length === 0" style="font: 400 12px/1.4 'Inter', sans-serif; color: var(--text-tertiary); font-style: italic;">No pricing set</div>
                                <div v-else>
                                    <div v-for="price in plan.prices" :key="`pp-u-${price.id}`" class="price-row">
                                        <span class="badge badge-sm" :class="intervalBadgeClass(price)">{{ price.interval_label }}</span>
                                        <span class="price-mid">
                                            <span class="price-amount">{{ gbp(price.price) }}</span>
                                            <span v-if="price.label" class="price-label-pill">{{ price.label }}</span>
                                            <span v-if="price.is_default" style="font: 400 11px/1.2 'Inter', sans-serif; color: var(--text-tertiary);">Default</span>
                                            <span v-if="price.active_customers > 0" class="price-subs">{{ price.active_customers }} {{ price.active_customers === 1 ? 'subscriber' : 'subscribers' }}</span>
                                        </span>
                                        <span class="price-actions">
                                            <button type="button" class="icon-btn" aria-label="Edit price" @click="openEditPrice(plan, price)">
                                                <IconPencil :size="13" stroke-width="1.75" />
                                            </button>
                                            <button type="button" class="icon-btn" style="color: var(--danger);" aria-label="Delete price" @click="askDeletePrice(price)">
                                                <IconTrash :size="13" stroke-width="1.75" />
                                            </button>
                                        </span>
                                    </div>
                                </div>
                                <button type="button" style="margin-top: 6px; background: none; border: 0; padding: 0; color: var(--accent); font: 500 12px/1.2 'Inter', sans-serif; cursor: pointer; display: flex; align-items: center; gap: 4px;" @click="openCreatePrice(plan)">
                                    <IconPlus :size="12" stroke-width="2" />
                                    Add price
                                </button>
                            </div>
                            <div class="plan-section plan-section-features">
                                <div class="plan-section-label">
                                    Features <span style="color: var(--text-tertiary);">· {{ plan.features.length }}</span>
                                </div>
                                <div v-if="plan.features.length === 0" style="font: 400 12px/1.4 'Inter', sans-serif; color: var(--text-tertiary); font-style: italic;">None set</div>
                                <div v-else class="feat-list">
                                    <div v-for="(f, i) in visibleFeatures(plan)" :key="`f-u-${plan.id}-${i}`" class="feat-item">
                                        <IconCheck :size="13" stroke-width="2" />
                                        <span>{{ f }}</span>
                                    </div>
                                    <button v-if="plan.features.length > 4" type="button" class="feat-more" @click="toggleFeatures(plan.id)">
                                        {{ expandedFeatures[plan.id] ? 'Show less' : `+ ${plan.features.length - 4} more` }}
                                    </button>
                                </div>
                            </div>
                            <footer class="plan-card-footer">
                                <span style="font: 400 12px/1.2 'Inter', sans-serif; color: var(--text-secondary);">
                                    {{ plan.active_customers }} active
                                    <template v-if="plan.mrr > 0">
                                        · <span style="color: var(--success);">{{ gbp(plan.mrr) }}/mo MRR</span>
                                    </template>
                                </span>
                                <button type="button" style="background: none; border: 0; padding: 0; color: var(--accent); font: 500 12px/1.2 'Inter', sans-serif; cursor: pointer; display: flex; align-items: center; gap: 4px;" @click="openEditPlan(plan)">
                                    Edit plan
                                    <IconArrowRight :size="12" stroke-width="1.75" />
                                </button>
                            </footer>
                        </div>
                        <button type="button" class="plan-add-card" @click="openCreatePlan(null)">
                            <IconPlus :size="20" stroke-width="1.75" />
                            <span class="plan-add-label">Add plan</span>
                        </button>
                    </div>
                </div>
            </section>
        </div>

        <!-- Add / Edit category slide-over -->
        <TransitionRoot as="template" :show="showCategoryModal">
            <Dialog as="div" class="slide-over-dialog" @close="showCategoryModal = false">
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

        <!-- Add / Edit plan slide-over -->
        <TransitionRoot as="template" :show="showPlanModal">
            <Dialog as="div" class="slide-over-dialog" @close="showPlanModal = false">
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
                                                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                                            </select>
                                            <div v-if="planForm.errors.category_id" class="err">{{ planForm.errors.category_id }}</div>
                                        </div>
                                    </div>
                                </div>

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

        <!-- Add / Edit price slide-over -->
        <TransitionRoot as="template" :show="showPriceModal">
            <Dialog as="div" class="slide-over-dialog" @close="showPriceModal = false">
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
                                    <span style="font: 400 12px/1.3 'Inter', sans-serif; color: var(--text-secondary); margin-left: auto;">on {{ product.name }}</span>
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
    </InternalLayout>
</template>
