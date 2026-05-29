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

/* ─── Plans ─── */
const PLAN_DEFAULTS = {
    name: '',
    description: '',
    price: 0,
    interval_count: 1,
    interval_unit: 'month',
    stripe_price_id: '',
    features: [],
    is_active: true,
    is_public: true,
    sort_order: 0,
};

const showPlanModal = ref(false);
const planMode = ref('create'); // 'create' | 'edit'
const editingPlanId = ref(null);
const planForm = useForm({ ...PLAN_DEFAULTS, product_id: null });
const newFeature = ref('');

function openCreatePlan() {
    if (! selectedProduct.value) return;
    planMode.value = 'create';
    editingPlanId.value = null;
    planForm.defaults({ ...PLAN_DEFAULTS, product_id: selectedProduct.value.id });
    planForm.reset();
    planForm.product_id = selectedProduct.value.id;
    planForm.clearErrors();
    newFeature.value = '';
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
        price: Number(plan.price ?? 0),
        interval_count: Number(plan.interval_count ?? 1),
        interval_unit: plan.interval_unit ?? 'month',
        stripe_price_id: plan.stripe_price_id ?? '',
        features: Array.isArray(plan.features) ? [...plan.features] : [],
        is_active: !! plan.is_active,
        is_public: !! plan.is_public,
        sort_order: Number(plan.sort_order ?? 0),
    });
    planForm.reset();
    planForm.clearErrors();
    newFeature.value = '';
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

function submitPlan() {
    const onSuccess = () => { showPlanModal.value = false; };
    if (planMode.value === 'create') {
        planForm.post('/settings/plans', { preserveScroll: true, onSuccess });
    } else {
        planForm.put(`/settings/plans/${editingPlanId.value}`, { preserveScroll: true, onSuccess });
    }
}

/* IntervalPicker uses { count, unit }; the form stores them as
 * interval_count + interval_unit so it can post directly. Map both
 * directions here so the picker stays a clean v-model. */
const planInterval = computed({
    get: () => ({ count: planForm.interval_count, unit: planForm.interval_unit }),
    set: (v) => {
        planForm.interval_count = v.count;
        planForm.interval_unit = v.unit;
    },
});

function togglePlanActive(plan) {
    router.post(`/settings/plans/${plan.id}/toggle`, {}, { preserveScroll: true });
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

                    <!-- ═══ Plans section ═══ -->
                    <div style="margin-top: 28px; padding-top: 20px; border-top: 1px solid var(--border);">
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 14px;">
                            <h3 style="margin: 0; font: 600 16px/1.2 'Inter', sans-serif;">Pricing plans</h3>
                            <span class="badge badge-inactive badge-sm">{{ selectedProduct.plans?.length || 0 }} plans</span>
                            <button
                                type="button"
                                class="btn btn-ghost btn-sm"
                                style="margin-left: auto; color: var(--accent);"
                                @click="openCreatePlan"
                            >
                                <IconPlus :size="14" stroke-width="1.75" />
                                Add plan
                            </button>
                        </div>

                        <div v-if="! selectedProduct.plans?.length" style="border: 1.5px dashed var(--border); border-radius: var(--radius-md); padding: 28px 16px; text-align: center;">
                            <IconTag :size="24" stroke-width="1.5" style="color: var(--text-tertiary); margin-bottom: 8px;" />
                            <div style="font: 600 14px/1.3 'Inter', sans-serif;">No plans yet</div>
                            <div style="font: 400 13px/1.4 'Inter', sans-serif; color: var(--text-secondary); margin-top: 4px;">
                                Add your first pricing plan to start enrolling customers.
                            </div>
                            <button type="button" class="btn btn-ghost btn-sm" style="margin-top: 12px; color: var(--accent);" @click="openCreatePlan">
                                <IconPlus :size="14" stroke-width="1.75" />
                                Add plan
                            </button>
                        </div>

                        <div v-else style="display: flex; flex-direction: column; gap: 10px;">
                            <div
                                v-for="plan in selectedProduct.plans"
                                :key="plan.id"
                                style="background: #fff; border: 1px solid var(--border); border-radius: var(--radius-md); padding: 16px 18px; position: relative;"
                                :style="! plan.is_active ? 'opacity: .6;' : ''"
                            >
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div style="font: 600 15px/1.2 'Inter', sans-serif;">{{ plan.name }}</div>
                                    <div style="font: 600 15px/1.2 'Inter', sans-serif; color: var(--accent);">
                                        {{ gbp(plan.price) }}
                                        <span style="font-weight: 400; color: var(--text-secondary); font-size: 13px;">· {{ plan.interval_label }}</span>
                                    </div>
                                    <div style="margin-left: auto; display: flex; align-items: center; gap: 6px;">
                                        <span class="badge badge-sm" :class="plan.is_active ? 'badge-active' : 'badge-inactive'">
                                            {{ plan.is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                        <span class="badge badge-sm" :class="plan.is_public ? 'badge-info' : 'badge-inactive'">
                                            {{ plan.is_public ? 'Public' : 'Private' }}
                                        </span>
                                        <Menu as="div" class="dd-menu">
                                            <MenuButton class="icon-btn" aria-label="Plan actions">
                                                <IconDots :size="16" stroke-width="1.75" />
                                            </MenuButton>
                                            <MenuItems class="dd-popover right-align">
                                                <MenuItem v-slot="{ active }">
                                                    <button type="button" :class="['dd-option', { active }]" @click="openEditPlan(plan)">Edit plan</button>
                                                </MenuItem>
                                                <MenuItem v-slot="{ active }">
                                                    <button type="button" :class="['dd-option', { active }]" @click="togglePlanActive(plan)">
                                                        {{ plan.is_active ? 'Deactivate' : 'Activate' }}
                                                    </button>
                                                </MenuItem>
                                                <div style="height: 1px; background: var(--border-soft); margin: 4px 0;" />
                                                <MenuItem v-slot="{ active }">
                                                    <button type="button" :class="['dd-option', { active }]" style="color: var(--danger);" @click="askDeletePlan(plan)">
                                                        Delete plan
                                                    </button>
                                                </MenuItem>
                                            </MenuItems>
                                        </Menu>
                                    </div>
                                </div>

                                <div style="font: 400 12px/1.4 'Inter', sans-serif; color: var(--text-tertiary); margin-top: 4px;">
                                    £{{ Number(plan.mrr_contribution).toFixed(2) }}/mo MRR equivalent
                                </div>
                                <div v-if="plan.description" style="font: 400 13px/1.5 'Inter', sans-serif; color: var(--text-secondary); margin-top: 6px;">
                                    {{ plan.description }}
                                </div>
                                <div v-if="plan.features.length" style="font: 400 12px/1.5 'Inter', sans-serif; color: var(--text-secondary); margin-top: 8px;">
                                    <template v-for="(f, i) in plan.features" :key="i">
                                        <span v-if="i > 0"> · </span>
                                        ✓ {{ f }}
                                    </template>
                                </div>
                                <div v-if="plan.stripe_price_id" style="display: flex; align-items: center; gap: 6px; margin-top: 8px; font-family: 'JetBrains Mono', monospace; font-size: 11px; color: var(--text-tertiary);">
                                    <IconBrandStripe :size="14" stroke-width="1.75" style="color: var(--info);" />
                                    <span>{{ plan.stripe_price_id }}</span>
                                </div>
                                <div style="font: 400 11px/1.3 'Inter', sans-serif; color: var(--text-tertiary); margin-top: 8px;">
                                    {{ plan.active_customers }} active subscription{{ plan.active_customers === 1 ? '' : 's' }}
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
                                    <h3>Pricing</h3>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Price (£)<span class="req">*</span></label>
                                            <input v-model.number="planForm.price" type="number" min="0" step="0.01" required>
                                            <div v-if="planForm.errors.price" class="err">{{ planForm.errors.price }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single" style="margin-top: 12px;">
                                        <div class="form-field">
                                            <label>Billing interval<span class="req">*</span></label>
                                            <IntervalPicker v-model="planInterval" />
                                            <div v-if="planForm.errors.interval_count" class="err">{{ planForm.errors.interval_count }}</div>
                                            <div v-if="planForm.errors.interval_unit" class="err">{{ planForm.errors.interval_unit }}</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <h3>Features <span style="color: var(--text-tertiary); font-weight: 400; font-size: 12px;">— shown on pricing page</span></h3>
                                    <div v-for="(f, i) in planForm.features" :key="i" style="display: flex; gap: 8px; margin-bottom: 6px;">
                                        <input :value="f" type="text" maxlength="200" style="flex: 1;" @input="planForm.features[i] = $event.target.value">
                                        <button type="button" class="icon-btn" aria-label="Remove feature" @click="removeFeature(i)">
                                            <IconX :size="16" stroke-width="1.75" />
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
                                    <h3>Stripe Price ID <span style="color: var(--text-tertiary); font-weight: 400; font-size: 12px;">— optional</span></h3>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Price ID</label>
                                            <input v-model="planForm.stripe_price_id" type="text" placeholder="price_1Nxxx..." style="font-family: 'JetBrains Mono', monospace;">
                                            <div class="field-help">Copy from Stripe Dashboard → Products → Prices. Used for checkout and webhook matching.</div>
                                            <div v-if="planForm.errors.stripe_price_id" class="err">{{ planForm.errors.stripe_price_id }}</div>
                                        </div>
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
    </SettingsLayout>
</template>
