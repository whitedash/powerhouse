<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
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
    IconStack2,
    IconFileExport,
    IconSearch,
    IconToolsKitchen2,
    IconFlag,
    IconAdjustmentsHorizontal,
    IconChevronDown,
    IconChevronLeft,
    IconChevronRight,
    IconCheck,
    IconAlertCircle,
    IconAlertTriangle,
    IconClock,
    IconUsers,
    IconUserPlus,
    IconLayoutGrid,
    IconDots,
    IconPlus,
    IconX,
    IconUser,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const props = defineProps({
    customers: { type: Object, required: true },
    products: { type: Array, default: () => [] },
    summary: { type: Object, required: true },
    billing_entities: { type: Array, default: () => [] },
    all_customers: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const page = usePage();

const breadcrumbs = [{ label: 'Provisioning' }];

/* ─── Money + dates ─── */
function gbpRound(n) {
    return new Intl.NumberFormat('en-GB', {
        style: 'currency',
        currency: 'GBP',
        maximumFractionDigits: 0,
    }).format(Number(n || 0));
}

function lastChangedLabel(iso) {
    if (! iso) return { label: '—', cls: 'muted' };
    const d = new Date(iso);
    const now = new Date();
    const diffMs = now - d;
    const diffDays = Math.floor(diffMs / 86400000);
    if (diffDays === 0) return { label: 'Today', cls: 'today' };
    if (diffDays === 1) return { label: 'Yesterday', cls: '' };
    if (diffDays < 7) return { label: `${diffDays} days ago`, cls: '' };
    if (diffDays < 14) return { label: '1 week ago', cls: '' };
    if (diffDays < 30) return { label: `${Math.floor(diffDays / 7)} weeks ago`, cls: '' };
    if (diffDays < 60) return { label: '1 month ago', cls: '' };
    return { label: `${Math.floor(diffDays / 30)} months ago`, cls: '' };
}

function trialDaysLeft(iso) {
    if (! iso) return null;
    const d = new Date(iso);
    const now = new Date();
    const diff = Math.ceil((d - now) / 86400000);
    return diff > 0 ? diff : 0;
}

/* ─── Avatar ─── */
function initials(name) {
    const parts = String(name || '').trim().split(/\s+/);
    return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || '?';
}
function avatarColour(id) {
    const palette = ['#0D9488', '#F59E0B', '#3B82F6', '#10B981', '#7C3AED', '#EF4444', '#06B6D4', '#6366F1'];
    return palette[Number(id) % palette.length];
}

/* ─── Toggle state for a (customer, product) cell ─── */
function activeSubscription(customer, product) {
    return customer.products.find(
        (p) => p.product_id === product.id && ['active', 'trial'].includes(p.status),
    );
}

/* ─── Filters ─── */
const searchInput = ref(props.filters.search ?? '');
const productFilter = ref(props.filters.product_slug ?? '');
const statusFilter = ref(props.filters.status ?? '');

let searchTimeout = null;
watch(searchInput, (v) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => applyFilters({ search: v || null }), 250);
});

function applyFilters(patch = {}) {
    const params = {
        search: searchInput.value || null,
        product_slug: productFilter.value || null,
        status: statusFilter.value || null,
        ...patch,
    };
    router.get('/provisioning', params, { preserveScroll: true, preserveState: true, replace: true });
}

function setProductFilter(slug) {
    productFilter.value = productFilter.value === slug ? '' : slug;
    applyFilters();
}
function setStatusFilter(status) {
    statusFilter.value = statusFilter.value === status ? '' : status;
    applyFilters();
}

const STATUS_OPTIONS = [
    { key: 'has_active', label: 'Has active product' },
    { key: 'trial', label: 'Has trial' },
    { key: 'no_products', label: 'No products yet' },
];

/* ─── Enable slide-over ─── */
const showEnable = ref(false);
const enableForm = useForm({
    customer_id: null,
    product_id: null,
    plan_id: null,
    plan_price_id: null,
    plan: '',
    price_monthly: null,
    interval_count: 1,
    interval_unit: 'month',
    billing_entity_id: null,
    status: 'active',
    trial_ends_at: '',
    action: 'enable',
});

const enableContextCustomer = ref(null);
const enableContextProduct = ref(null);

function openEnable(customer, product) {
    enableForm.reset();
    enableForm.clearErrors();
    enableForm.customer_id = customer.id;
    enableForm.product_id = product.id;
    enableForm.billing_entity_id = props.billing_entities[0]?.id ?? null;
    enableForm.status = 'active';
    enableForm.interval_count = 1;
    enableForm.interval_unit = 'month';
    enableForm.action = 'enable';
    enableContextCustomer.value = customer;
    enableContextProduct.value = product;
    showEnable.value = true;
}

function selectEnablePlan(plan) {
    // Step 1: pick the plan tier. Auto-select default price so the
    // operator can ship the most common path in two clicks.
    enableForm.plan_id = plan.id;
    enableForm.plan = plan.name;
    const def = (plan.prices ?? []).find((p) => p.is_default) ?? plan.prices?.[0];
    if (def) {
        selectEnablePrice(def);
    } else {
        enableForm.plan_price_id = null;
        enableForm.price_monthly = null;
        enableForm.interval_count = 1;
        enableForm.interval_unit = 'month';
    }
}

function selectEnablePrice(price) {
    enableForm.plan_price_id = price.id;
    enableForm.price_monthly = price.price;
    enableForm.interval_count = price.interval_count;
    enableForm.interval_unit = price.interval_unit;
}

function selectedEnablePlan() {
    return (enableContextProduct.value?.plans ?? []).find((p) => p.id === enableForm.plan_id) ?? null;
}

function submitEnable() {
    enableForm.post('/provisioning/toggle', {
        preserveScroll: true,
        onSuccess: () => {
            showEnable.value = false;
            enableForm.reset();
        },
    });
}

/* ─── Suspend confirm modal ─── */
const showSuspend = ref(false);
const suspendCustomer = ref(null);
const suspendProduct = ref(null);
const suspendProcessing = ref(false);

function askSuspend(customer, product) {
    suspendCustomer.value = customer;
    suspendProduct.value = product;
    showSuspend.value = true;
}

function handleSuspend() {
    if (! suspendCustomer.value || ! suspendProduct.value) return;
    suspendProcessing.value = true;
    router.post('/provisioning/toggle', {
        customer_id: suspendCustomer.value.id,
        product_id: suspendProduct.value.id,
        action: 'suspend',
    }, {
        preserveScroll: true,
        onFinish: () => {
            suspendProcessing.value = false;
            showSuspend.value = false;
            suspendCustomer.value = null;
            suspendProduct.value = null;
        },
    });
}

const suspendMessage = computed(() => {
    if (! suspendCustomer.value || ! suspendProduct.value) return '';
    return `This will suspend ${suspendProduct.value.name} for ${suspendCustomer.value.name}. Their access will be removed immediately.`;
});

/* ─── Toggle click router ─── */
function onToggleClick(customer, product) {
    if (! product.is_active) return;
    const sub = activeSubscription(customer, product);
    if (sub) {
        askSuspend(customer, product);
    } else {
        openEnable(customer, product);
    }
}

/* ─── Quick enable panel ─── */
const quickCustomerId = ref(null);
const quickProductId = ref(null);

const quickCustomer = computed(() => props.all_customers.find((c) => c.id === quickCustomerId.value));
const quickProduct = computed(() => props.products.find((p) => p.id === quickProductId.value));

function openQuickEnable() {
    if (! quickCustomer.value || ! quickProduct.value) return;
    openEnable(quickCustomer.value, quickProduct.value);
}

/* ─── Subhead label ─── */
function productCountLabel(p) {
    if (p.is_coming_soon) return { text: 'Coming soon', cls: 'muted' };
    return { text: `${p.active_count} active`, cls: 'ok' };
}

const greetSub = computed(() => {
    const total = props.summary.total_customers;
    const trialCount = props.customers.data
        .reduce((acc, c) => acc + c.products.filter((p) => p.status === 'trial').length, 0);
    return `Manage product access per customer · ${total} customers · ${trialCount} active trials on this page`;
});

/* ─── Pagination URLs ─── */
const prevUrl = computed(() => props.customers.prev_page_url);
const nextUrl = computed(() => props.customers.next_page_url);
</script>

<template>
    <Head title="Provisioning" />

    <InternalLayout title="Provisioning" :breadcrumbs="breadcrumbs" active-nav="provisioning">
        <template #topbar-actions>
            <button type="button" class="btn btn-ghost btn-sm" style="color: var(--text-secondary);" disabled>
                <IconStack2 :size="14" stroke-width="1.75" />
                Bulk enable
            </button>
            <button type="button" class="btn btn-ghost btn-sm" style="color: var(--text-secondary);" disabled>
                <IconFileExport :size="14" stroke-width="1.75" />
                Export
            </button>
        </template>

        <div class="provisioning">
            <!-- Greeting -->
            <div class="greet">
                <div>
                    <h1>Provisioning</h1>
                    <div class="sub">{{ greetSub }}</div>
                </div>
            </div>

            <!-- Flash banners -->
            <div
                v-if="page.props.flash?.success"
                style="margin-top: 14px; padding: 10px 14px; background: var(--success-bg); color: #047857; border: 1px solid #A7F3D0; border-radius: var(--radius-md); font: 500 13px/1.4 'Inter', sans-serif; display: flex; align-items: center; gap: 8px;"
            >
                <IconCheck :size="16" stroke-width="2" />{{ page.props.flash.success }}
            </div>
            <div
                v-if="page.props.flash?.error"
                style="margin-top: 14px; padding: 10px 14px; background: var(--danger-bg); color: var(--danger); border: 1px solid #FECACA; border-radius: var(--radius-md); font: 500 13px/1.4 'Inter', sans-serif; display: flex; align-items: center; gap: 8px;"
            >
                <IconAlertCircle :size="16" stroke-width="2" />{{ page.props.flash.error }}
            </div>

            <!-- Summary strip -->
            <div class="summary-strip">
                <div class="stat-pill">
                    <span class="d gold" />
                    <strong>{{ summary.total_customers }}</strong>
                    <span class="lbl">customers</span>
                </div>
                <div v-for="p in summary.products" :key="p.slug" class="stat-pill">
                    <span class="d" :class="p.is_coming_soon ? 'grey' : 'green'" />
                    <strong>{{ p.count }}</strong>
                    <span class="lbl">on {{ p.name }}</span>
                    <span v-if="p.is_coming_soon" class="sub">coming soon</span>
                </div>
            </div>

            <!-- Filter bar -->
            <div class="filter-bar">
                <div class="field-search">
                    <span class="search-icon"><IconSearch :size="16" stroke-width="1.75" /></span>
                    <input v-model="searchInput" type="text" placeholder="Search customers…">
                </div>

                <Menu as="div" class="dd-menu">
                    <MenuButton class="dd-btn">
                        <IconToolsKitchen2 :size="14" stroke-width="1.75" />
                        {{ productFilter ? products.find((p) => p.slug === productFilter)?.name : 'Product' }}
                        <IconChevronDown :size="14" stroke-width="1.75" class="ch" />
                    </MenuButton>
                    <MenuItems class="dd-popover">
                        <MenuItem v-for="p in products" :key="p.slug" v-slot="{ active }">
                            <button type="button" :class="['dd-option', { active, current: productFilter === p.slug }]" @click="setProductFilter(p.slug)">
                                {{ p.name }}
                            </button>
                        </MenuItem>
                    </MenuItems>
                </Menu>

                <Menu as="div" class="dd-menu">
                    <MenuButton class="dd-btn">
                        <IconFlag :size="14" stroke-width="1.75" />
                        {{ statusFilter ? STATUS_OPTIONS.find((s) => s.key === statusFilter)?.label : 'Status' }}
                        <IconChevronDown :size="14" stroke-width="1.75" class="ch" />
                    </MenuButton>
                    <MenuItems class="dd-popover">
                        <MenuItem v-for="s in STATUS_OPTIONS" :key="s.key" v-slot="{ active }">
                            <button type="button" :class="['dd-option', { active, current: statusFilter === s.key }]" @click="setStatusFilter(s.key)">
                                {{ s.label }}
                            </button>
                        </MenuItem>
                    </MenuItems>
                </Menu>

                <div class="right">
                    <button type="button" class="btn btn-ghost btn-sm" style="color: var(--text-secondary);" disabled>
                        <IconAdjustmentsHorizontal :size="14" stroke-width="1.75" />
                        Filters
                    </button>
                </div>
            </div>

            <!-- ═══════════ TABLE ═══════════ -->
            <div class="table-card">
                <table class="tbl">
                    <colgroup>
                        <col>
                        <col v-for="p in products" :key="p.id" style="width: 160px;">
                        <col style="width: 130px;">
                        <col style="width: 56px;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th v-for="p in products" :key="p.id">{{ p.name }}</th>
                            <th>Last changed</th>
                            <th />
                        </tr>
                        <tr class="subhead">
                            <th />
                            <th v-for="p in products" :key="p.id">
                                <span :class="productCountLabel(p).cls">{{ productCountLabel(p).text }}</span>
                            </th>
                            <th />
                            <th />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="! customers.data.length">
                            <td :colspan="products.length + 3" style="text-align: center; padding: 32px; color: var(--text-secondary); font-size: 13px;">
                                No customers match the current filters.
                            </td>
                        </tr>

                        <tr v-for="c in customers.data" :key="c.id">
                            <td>
                                <div class="cell-cust">
                                    <div class="avatar" :style="{ background: avatarColour(c.id), color: '#fff' }">
                                        {{ initials(c.name) }}
                                    </div>
                                    <div>
                                        <div class="cust-name-row">
                                            <span class="cust-name">{{ c.name }}</span>
                                            <span
                                                v-for="p in c.products.filter((x) => x.status === 'trial' && trialDaysLeft(x.trial_ends_at) !== null && trialDaysLeft(x.trial_ends_at) <= 7)"
                                                :key="`tr-${p.id}`"
                                                class="warn-pill"
                                                style="margin-left: 4px;"
                                            >
                                                <IconClock :size="12" stroke-width="1.75" />
                                                Trial ends {{ trialDaysLeft(p.trial_ends_at) }}d
                                            </span>
                                        </div>
                                        <div class="cust-loc">{{ [c.city, c.country].filter(Boolean).join(', ') || '—' }}</div>
                                    </div>
                                </div>
                            </td>

                            <!-- Lead row collapsed strip when customer has no active/trial subs at all -->
                            <td
                                v-if="c.products.filter((p) => ['active', 'trial'].includes(p.status)).length === 0 && c.pipeline_stage === 'lead'"
                                :colspan="products.length"
                            >
                                <div class="prov-lead-strip">
                                    <IconUserPlus :size="14" stroke-width="1.75" />
                                    Lead · no products yet
                                </div>
                            </td>

                            <template v-else>
                                <td v-for="p in products" :key="p.id">
                                    <div class="prov-cell">
                                        <div v-if="p.is_coming_soon" class="toggle-row">
                                            <button type="button" class="toggle disabled" aria-disabled="true" />
                                        </div>
                                        <template v-else>
                                            <div class="toggle-row">
                                                <button
                                                    type="button"
                                                    class="toggle"
                                                    :class="{ on: !! activeSubscription(c, p) }"
                                                    @click="onToggleClick(c, p)"
                                                />
                                            </div>
                                        </template>

                                        <template v-if="p.is_coming_soon">
                                            <span class="label muted">Soon</span>
                                        </template>
                                        <template v-else-if="activeSubscription(c, p)">
                                            <span
                                                v-if="activeSubscription(c, p).status === 'trial'"
                                                class="label trial"
                                            >
                                                Trial · {{ trialDaysLeft(activeSubscription(c, p).trial_ends_at) ?? 0 }} days left
                                            </span>
                                            <span v-else class="label">
                                                {{ activeSubscription(c, p).plan || 'Pro' }}
                                                <template v-if="activeSubscription(c, p).price_monthly">
                                                    · {{ gbpRound(activeSubscription(c, p).price_monthly) }}/mo
                                                </template>
                                            </span>
                                        </template>
                                        <template v-else>
                                            <span class="label muted">Not active</span>
                                        </template>
                                    </div>
                                </td>
                            </template>

                            <td>
                                <span :class="['changed', lastChangedLabel(c.last_changed_at).cls]">
                                    {{ lastChangedLabel(c.last_changed_at).label }}
                                </span>
                            </td>
                            <td>
                                <Menu as="div" class="dd-menu">
                                    <MenuButton class="icon-btn" aria-label="Actions">
                                        <IconDots :size="16" stroke-width="1.75" />
                                    </MenuButton>
                                    <MenuItems class="dd-popover right-align">
                                        <MenuItem v-slot="{ active }">
                                            <Link :class="['dd-option', { active }]" :href="`/customers/${c.id}`">
                                                View customer
                                            </Link>
                                        </MenuItem>
                                        <MenuItem v-slot="{ active }">
                                            <button type="button" :class="['dd-option', { active }]" disabled style="opacity: .55; cursor: not-allowed;">
                                                Enable all products
                                            </button>
                                        </MenuItem>
                                    </MenuItems>
                                </Menu>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="tbl-foot">
                    <div class="info">
                        Showing <strong>{{ customers.from || 0 }} – {{ customers.to || 0 }}</strong> of <strong>{{ customers.total }}</strong> customers
                    </div>
                    <div class="right">
                        <Link v-if="prevUrl" :href="prevUrl" class="pg-btn" preserve-scroll>
                            <IconChevronLeft :size="14" stroke-width="1.75" />
                            Previous
                        </Link>
                        <button v-else type="button" class="pg-btn" disabled>
                            <IconChevronLeft :size="14" stroke-width="1.75" />
                            Previous
                        </button>
                        <Link v-if="nextUrl" :href="nextUrl" class="pg-btn" preserve-scroll>
                            Next
                            <IconChevronRight :size="14" stroke-width="1.75" />
                        </Link>
                        <button v-else type="button" class="pg-btn" disabled>
                            Next
                            <IconChevronRight :size="14" stroke-width="1.75" />
                        </button>
                    </div>
                </div>
            </div>

            <!-- ═══════════ QUICK ENABLE PANEL ═══════════ -->
            <div class="qe-panel">
                <div class="qe-left">
                    <div class="qe-head">
                        <div class="ic">
                            <IconLayoutGrid :size="16" stroke-width="1.75" />
                        </div>
                        <div class="title">Enable a product for a customer</div>
                    </div>
                    <div class="qe-sub">Select a customer and product to provision access instantly.</div>
                </div>
                <div class="qe-right">
                    <select v-model="quickCustomerId" class="qe-select" :class="{ placeholder: ! quickCustomerId }">
                        <option :value="null">Select customer…</option>
                        <option v-for="c in all_customers" :key="c.id" :value="c.id">
                            {{ c.name }}<template v-if="c.city"> · {{ c.city }}</template>
                        </option>
                    </select>
                    <select v-model="quickProductId" class="qe-select" :class="{ placeholder: ! quickProductId }">
                        <option :value="null">Select product…</option>
                        <option
                            v-for="p in products.filter((x) => ! x.is_coming_soon)"
                            :key="p.id"
                            :value="p.id"
                        >
                            {{ p.name }}
                        </option>
                    </select>
                    <button
                        type="button"
                        class="btn btn-primary qe-btn"
                        :disabled="! quickCustomerId || ! quickProductId"
                        @click="openQuickEnable"
                    >
                        <IconCheck :size="14" stroke-width="1.75" />
                        Enable
                    </button>
                </div>
            </div>
        </div>

        <!-- Enable slide-over -->
        <TransitionRoot as="template" :show="showEnable">
            <Dialog as="div" class="slide-over-dialog" @close="showEnable = false">
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
                        <form class="slide-over-form" @submit.prevent="submitEnable">
                            <header class="slide-over-header">
                                <h2>Enable product</h2>
                                <button type="button" class="icon-btn" aria-label="Close" @click="showEnable = false">
                                    <IconX :size="18" stroke-width="1.75" />
                                </button>
                            </header>

                            <div class="slide-over-body">
                                <div class="form-section">
                                    <h3>Customer &amp; product</h3>
                                    <div style="display: flex; flex-direction: column; gap: 10px;">
                                        <div style="padding: 10px 14px; background: var(--neutral-bg); border-radius: var(--radius-md); display: flex; align-items: center; gap: 10px;">
                                            <IconUser :size="16" stroke-width="1.75" style="color: var(--text-secondary);" />
                                            <div>
                                                <div style="font: 600 13px/1.3 'Inter', sans-serif;">{{ enableContextCustomer?.name }}</div>
                                                <div style="font: 400 11.5px/1.3 'Inter', sans-serif; color: var(--text-secondary);">{{ enableContextCustomer?.city || '—' }}</div>
                                            </div>
                                        </div>
                                        <div style="padding: 10px 14px; background: var(--neutral-bg); border-radius: var(--radius-md); display: flex; align-items: center; gap: 10px;">
                                            <div
                                                style="width: 26px; height: 26px; border-radius: 6px; display: grid; place-items: center; color: #fff; font: 600 12px/1 'Inter', sans-serif;"
                                                :style="{ background: enableContextProduct?.icon_colour || '#0D9488' }"
                                            >
                                                {{ enableContextProduct?.name?.[0] || '?' }}
                                            </div>
                                            <div>
                                                <div style="font: 600 13px/1.3 'Inter', sans-serif;">{{ enableContextProduct?.name }}</div>
                                                <div style="font: 400 11.5px/1.3 'Inter', sans-serif; color: var(--text-secondary);">{{ enableContextProduct?.slug }}</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <h3>Plan</h3>
                                    <!-- STEP 1 — plan radio cards (name + features + pricing hint),
                                         grouped by category. Flat .plans stays as the source of truth
                                         for selectedEnablePlan() lookups. -->
                                    <template v-if="(enableContextProduct?.plans ?? []).length > 0">
                                        <div style="display: flex; flex-direction: column; gap: 8px;">
                                            <!-- Categorised groups -->
                                            <template
                                                v-for="category in (enableContextProduct?.plan_categories ?? [])"
                                                :key="`cat-${category.id}`"
                                            >
                                                <div v-if="category.plans.length" class="enable-category-header">
                                                    {{ category.name }}
                                                </div>
                                                <button
                                                    v-for="plan in category.plans"
                                                    :key="`cp-${plan.id}`"
                                                    type="button"
                                                    class="enable-plan-card"
                                                    :class="{ selected: enableForm.plan_id === plan.id }"
                                                    @click="selectEnablePlan(plan)"
                                                >
                                                    <div class="epc-radio">
                                                        <div v-if="enableForm.plan_id === plan.id" class="epc-dot" />
                                                    </div>
                                                    <div class="epc-body">
                                                        <div class="epc-name">{{ plan.name }}</div>
                                                        <div v-if="plan.category_name" class="epc-category">{{ plan.category_name }}</div>
                                                        <div v-if="(plan.features ?? []).length" class="epc-features">
                                                            <span v-for="(feat, i) in plan.features.slice(0, 3)" :key="i" class="epc-feat">
                                                                ✓ {{ feat }}
                                                            </span>
                                                            <span v-if="plan.features.length > 3" class="epc-feat-more">
                                                                +{{ plan.features.length - 3 }} more
                                                            </span>
                                                        </div>
                                                        <div v-else class="epc-features epc-no-features">No features listed</div>
                                                        <div class="epc-pricing-hint">
                                                            {{ (plan.prices ?? []).length }} pricing option{{ (plan.prices ?? []).length === 1 ? '' : 's' }}
                                                        </div>
                                                    </div>
                                                </button>
                                            </template>

                                            <!-- Uncategorised group ("Other" header only when categories preceded it) -->
                                            <template v-if="(enableContextProduct?.uncategorised_plans ?? []).length">
                                                <div
                                                    v-if="(enableContextProduct?.plan_categories ?? []).length"
                                                    class="enable-category-header enable-category-uncategorised"
                                                >
                                                    Other
                                                </div>
                                                <button
                                                    v-for="plan in enableContextProduct.uncategorised_plans"
                                                    :key="`up-${plan.id}`"
                                                    type="button"
                                                    class="enable-plan-card"
                                                    :class="{ selected: enableForm.plan_id === plan.id }"
                                                    @click="selectEnablePlan(plan)"
                                                >
                                                    <div class="epc-radio">
                                                        <div v-if="enableForm.plan_id === plan.id" class="epc-dot" />
                                                    </div>
                                                    <div class="epc-body">
                                                        <div class="epc-name">{{ plan.name }}</div>
                                                        <div v-if="(plan.features ?? []).length" class="epc-features">
                                                            <span v-for="(feat, i) in plan.features.slice(0, 3)" :key="i" class="epc-feat">
                                                                ✓ {{ feat }}
                                                            </span>
                                                            <span v-if="plan.features.length > 3" class="epc-feat-more">
                                                                +{{ plan.features.length - 3 }} more
                                                            </span>
                                                        </div>
                                                        <div v-else class="epc-features epc-no-features">No features listed</div>
                                                        <div class="epc-pricing-hint">
                                                            {{ (plan.prices ?? []).length }} pricing option{{ (plan.prices ?? []).length === 1 ? '' : 's' }}
                                                        </div>
                                                    </div>
                                                </button>
                                            </template>

                                            <!-- Defensive fallback for older payload shapes -->
                                            <template
                                                v-if="!(enableContextProduct?.plan_categories ?? []).length
                                                    && !(enableContextProduct?.uncategorised_plans ?? []).length"
                                            >
                                                <button
                                                    v-for="plan in enableContextProduct.plans"
                                                    :key="`fp-${plan.id}`"
                                                    type="button"
                                                    class="enable-plan-card"
                                                    :class="{ selected: enableForm.plan_id === plan.id }"
                                                    @click="selectEnablePlan(plan)"
                                                >
                                                    <div class="epc-radio">
                                                        <div v-if="enableForm.plan_id === plan.id" class="epc-dot" />
                                                    </div>
                                                    <div class="epc-body">
                                                        <div class="epc-name">{{ plan.name }}</div>
                                                        <div v-if="plan.category_name" class="epc-category">{{ plan.category_name }}</div>
                                                        <div v-if="(plan.features ?? []).length" class="epc-features">
                                                            <span v-for="(feat, i) in plan.features.slice(0, 3)" :key="i" class="epc-feat">
                                                                ✓ {{ feat }}
                                                            </span>
                                                            <span v-if="plan.features.length > 3" class="epc-feat-more">
                                                                +{{ plan.features.length - 3 }} more
                                                            </span>
                                                        </div>
                                                        <div v-else class="epc-features epc-no-features">No features listed</div>
                                                        <div class="epc-pricing-hint">
                                                            {{ (plan.prices ?? []).length }} pricing option{{ (plan.prices ?? []).length === 1 ? '' : 's' }}
                                                        </div>
                                                    </div>
                                                </button>
                                            </template>
                                        </div>
                                    </template>
                                    <!-- Fallback: free-text plan + price for products without defined plans -->
                                    <template v-else>
                                        <div class="form-row two">
                                            <div class="form-field">
                                                <label>Plan</label>
                                                <input v-model="enableForm.plan" type="text" placeholder="e.g. Pro, Basic">
                                                <div v-if="enableForm.errors.plan" class="err">{{ enableForm.errors.plan }}</div>
                                            </div>
                                            <div class="form-field">
                                                <label>Price (£)</label>
                                                <input v-model.number="enableForm.price_monthly" type="number" min="0" step="0.01" placeholder="29.00">
                                                <div v-if="enableForm.errors.price_monthly" class="err">{{ enableForm.errors.price_monthly }}</div>
                                            </div>
                                        </div>
                                        <div class="field-help" style="margin-top: 8px;">
                                            No plans defined for this product yet. Add plans in Settings → Products for a better experience.
                                        </div>
                                    </template>
                                </div>

                                <!-- STEP 2 — pick a price, auto-selects default -->
                                <div v-if="enableForm.plan_id && (selectedEnablePlan()?.prices ?? []).length > 0" class="form-section">
                                    <div class="enable-step-label">Select billing interval</div>
                                    <button
                                        v-for="price in selectedEnablePlan().prices"
                                        :key="`pp-${price.id}`"
                                        type="button"
                                        class="enable-price-row"
                                        :class="{ selected: enableForm.plan_price_id === price.id }"
                                        @click="selectEnablePrice(price)"
                                    >
                                        <div class="epr-left">
                                            <span class="epr-interval">{{ price.interval_label }}</span>
                                            <span class="epr-price">£{{ Number(price.price).toFixed(2) }}</span>
                                            <span v-if="price.label" class="epr-label-pill">{{ price.label }}</span>
                                        </div>
                                        <div class="epr-right">
                                            <span v-if="price.is_default" class="epr-default">Default</span>
                                            <div v-if="enableForm.plan_price_id === price.id" class="epr-radio-dot" />
                                        </div>
                                    </button>
                                </div>

                                <div v-if="billing_entities.length" class="form-section">
                                    <h3>Billing entity</h3>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Bills under</label>
                                            <select v-model="enableForm.billing_entity_id">
                                                <option :value="null">— None —</option>
                                                <option v-for="be in billing_entities" :key="be.id" :value="be.id">{{ be.name }}</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <h3>Status</h3>
                                    <div class="status-opts">
                                        <button
                                            type="button"
                                            class="status-opt"
                                            :class="{ selected: enableForm.status === 'active' }"
                                            @click="enableForm.status = 'active'"
                                        >
                                            <div class="so-radio">
                                                <div v-if="enableForm.status === 'active'" class="so-dot" />
                                            </div>
                                            <div class="so-body">
                                                <div class="so-title">Active</div>
                                                <div class="so-desc">Billing starts immediately</div>
                                            </div>
                                        </button>
                                        <button
                                            type="button"
                                            class="status-opt"
                                            :class="{ selected: enableForm.status === 'trial' }"
                                            @click="enableForm.status = 'trial'"
                                        >
                                            <div class="so-radio">
                                                <div v-if="enableForm.status === 'trial'" class="so-dot" />
                                            </div>
                                            <div class="so-body">
                                                <div class="so-title">Trial</div>
                                                <div class="so-desc">Free access until trial end date</div>
                                            </div>
                                        </button>
                                    </div>
                                    <div v-if="enableForm.status === 'trial'" class="trial-date-field">
                                        <label class="field-label">Trial ends on<span class="req">*</span></label>
                                        <input
                                            v-model="enableForm.trial_ends_at"
                                            type="date"
                                            class="field-input"
                                            :min="new Date().toISOString().split('T')[0]"
                                            required
                                        >
                                        <div class="field-help">Customer will be prompted to subscribe when the trial expires.</div>
                                        <div v-if="enableForm.errors.trial_ends_at" class="err">{{ enableForm.errors.trial_ends_at }}</div>
                                    </div>
                                </div>
                            </div>

                            <footer class="slide-over-footer">
                                <button type="button" class="btn btn-secondary" @click="showEnable = false">Cancel</button>
                                <button type="submit" class="btn btn-primary" :disabled="enableForm.processing">
                                    <IconPlus :size="15" stroke-width="1.75" />
                                    {{ enableForm.processing ? 'Enabling…' : 'Enable product' }}
                                </button>
                            </footer>
                        </form>
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </TransitionRoot>

        <ConfirmModal
            v-model:show="showSuspend"
            :title="suspendProduct && suspendCustomer ? `Suspend ${suspendProduct.name} for ${suspendCustomer.name}?` : 'Suspend product?'"
            :message="suspendMessage"
            confirm-label="Suspend"
            variant="warning"
            :loading="suspendProcessing"
            @confirm="handleSuspend"
        />
    </InternalLayout>
</template>
