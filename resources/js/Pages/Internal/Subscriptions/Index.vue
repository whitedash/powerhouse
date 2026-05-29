<script setup>
import { computed, onMounted, ref, watch } from 'vue';
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
    IconCurrencyPound,
    IconCircleCheck,
    IconClock,
    IconUserPlus,
    IconUserMinus,
    IconSearch,
    IconChevronDown,
    IconChevronLeft,
    IconChevronRight,
    IconAdjustmentsHorizontal,
    IconArrowsSort,
    IconArrowDown,
    IconDownload,
    IconDots,
    IconX,
    IconCheck,
    IconAlertCircle,
    IconAlertTriangle,
    IconReceiptOff,
    IconBrandStripe,
    IconExternalLink,
    IconPlus,
    IconCreditCard,
    IconFlag,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const props = defineProps({
    subscriptions: { type: Object, required: true },
    analytics: { type: Object, required: true },
    products: { type: Array, default: () => [] },
    product_plans: { type: Object, default: () => ({}) },
    billing_entities: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    intervals: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const page = usePage();
const breadcrumbs = [{ label: 'Subscriptions' }];

/* ─── Money + dates ─── */
function gbp(n) {
    return new Intl.NumberFormat('en-GB', {
        style: 'currency',
        currency: 'GBP',
        minimumFractionDigits: 2,
    }).format(Number(n || 0));
}
function gbpRound(n) {
    return new Intl.NumberFormat('en-GB', {
        style: 'currency',
        currency: 'GBP',
        maximumFractionDigits: 0,
    }).format(Number(n || 0));
}
function formatDate(iso) {
    if (! iso) return '—';
    return new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}
function daysUntil(iso) {
    if (! iso) return null;
    return Math.ceil((new Date(iso) - new Date()) / 86400000);
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

/* ─── Interval label/badge ─── */
const INTERVAL_LABELS = { monthly: 'Monthly', annual: 'Annual', one_off: 'One-off' };
function intervalBadgeClass(iv) {
    return iv === 'monthly' ? 'badge-info'
        : iv === 'annual' ? 'badge-gold'
        : 'badge-inactive';
}
const STATUS_LABELS = { active: 'Active', trial: 'Trial', suspended: 'Suspended', cancelled: 'Cancelled' };
function statusBadgeClass(st) {
    return st === 'active' ? 'badge-active'
        : st === 'trial' ? 'badge-pending'
        : 'badge-inactive';
}

function priceSuffix(iv) {
    return iv === 'annual' ? '/yr' : iv === 'monthly' ? '/mo' : '';
}
function discountActive(sub) {
    if (! sub.discount_pct || sub.discount_pct <= 0) return false;
    if (! sub.discount_expires_at) return true;
    return daysUntil(sub.discount_expires_at) >= 0;
}
function nextBillingClass(sub) {
    if (! sub.next_billing_date) return 'muted';
    const d = daysUntil(sub.next_billing_date);
    if (d < 0) return 'overdue';
    if (d <= 7) return 'soon';
    return '';
}

/* ─── Filters ─── */
const searchInput = ref(props.filters.search ?? '');
const productFilter = ref(props.filters.product_slug ?? '');
const statusFilter = ref(props.filters.status ?? '');
const intervalFilter = ref(props.filters.billing_interval ?? '');

let searchTimeout = null;
watch(searchInput, (v) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => applyFilters({ search: v || null }), 400);
});

function applyFilters(patch = {}) {
    const params = {
        search: searchInput.value || null,
        product_slug: productFilter.value || null,
        status: statusFilter.value || null,
        billing_interval: intervalFilter.value || null,
        ...patch,
    };
    router.get('/subscriptions', params, { preserveScroll: true, preserveState: true, replace: true });
}
function setProduct(slug) {
    productFilter.value = productFilter.value === slug ? '' : slug;
    applyFilters();
}
function setStatus(st) {
    statusFilter.value = statusFilter.value === st ? '' : st;
    applyFilters();
}
function setInterval(iv) {
    intervalFilter.value = intervalFilter.value === iv ? '' : iv;
    applyFilters();
}

const hasFilters = computed(() => searchInput.value || productFilter.value || statusFilter.value || intervalFilter.value);

/* ─── Stripe banner dismiss state ─── */
const STRIPE_BANNER_KEY = 'subscriptions.stripeBannerDismissed';
const stripeBannerVisible = ref(true);
onMounted(() => {
    stripeBannerVisible.value = localStorage.getItem(STRIPE_BANNER_KEY) !== '1';
});
function dismissStripeBanner() {
    stripeBannerVisible.value = false;
    localStorage.setItem(STRIPE_BANNER_KEY, '1');
}

/* ─── Edit slide-over ─── */
const showEdit = ref(false);
const editingSub = ref(null);
const editForm = useForm({
    plan_id: null,
    plan: '',
    price_monthly: 0,
    billing_interval: 'monthly',
    billing_entity_id: null,
    next_billing_date: '',
    discount_pct: null,
    discount_expires_at: '',
    stripe_subscription_id: '',
    stripe_price_id: '',
    cancels_at: '',
});

const showDiscountSection = ref(false);
const showStripeSection = ref(false);
const showPlanPicker = ref(false);
const showPriceOverride = ref(false);

function plansForCurrentProduct() {
    const sub = editingSub.value;
    if (! sub) return [];
    // product.id isn't on the subscription row payload; we look up by
    // slug → id via the products prop.
    const productMatch = props.products.find((p) => p.slug === sub.product.slug);
    return productMatch ? (props.product_plans[productMatch.id] ?? []) : [];
}

function openEdit(sub) {
    editingSub.value = sub;
    editForm.plan_id = sub.plan_id ?? null;
    editForm.plan = sub.plan ?? '';
    editForm.price_monthly = sub.price_monthly ?? 0;
    editForm.billing_interval = sub.billing_interval ?? 'monthly';
    editForm.billing_entity_id = sub.billing_entity?.id ?? null;
    editForm.next_billing_date = sub.next_billing_date ?? '';
    editForm.discount_pct = sub.discount_pct ?? null;
    editForm.discount_expires_at = sub.discount_expires_at ?? '';
    editForm.stripe_subscription_id = sub.stripe_subscription_id ?? '';
    editForm.stripe_price_id = sub.stripe_price_id ?? '';
    editForm.cancels_at = sub.cancels_at ?? '';
    editForm.clearErrors();
    showDiscountSection.value = !! sub.discount_pct;
    showStripeSection.value = !! (sub.stripe_subscription_id || sub.stripe_price_id);
    showPlanPicker.value = ! sub.plan_id;
    showPriceOverride.value = false;
    showEdit.value = true;
}

function selectEditPlan(plan) {
    editForm.plan_id = plan.id;
    editForm.plan = plan.name;
    editForm.price_monthly = editForm.billing_interval === 'annual' && plan.price_annual !== null
        ? plan.price_annual
        : plan.price_monthly;
    showPlanPicker.value = false;
}

function submitEdit() {
    editForm.put(`/subscriptions/${editingSub.value.id}`, {
        preserveScroll: true,
        onSuccess: () => { showEdit.value = false; },
    });
}

function removeDiscount() {
    editForm.discount_pct = null;
    editForm.discount_expires_at = '';
    showDiscountSection.value = false;
}

const previewEffective = computed(() => {
    const base = Number(editForm.price_monthly) || 0;
    const pct = Number(editForm.discount_pct) || 0;
    if (pct <= 0) return null;
    // Expiry in the past = no discount. Mirrors the model accessor.
    if (editForm.discount_expires_at && new Date(editForm.discount_expires_at) < new Date()) return null;
    return Math.round(base * (1 - pct / 100) * 100) / 100;
});

/* ─── Cancel modal ─── */
const showCancel = ref(false);
const cancelTarget = ref(null);
const cancelProcessing = ref(false);
const cancelMode = ref('immediately'); // 'immediately' | 'scheduled'
const cancelDate = ref('');

function askCancel(sub) {
    cancelTarget.value = sub;
    cancelMode.value = 'immediately';
    cancelDate.value = '';
    showCancel.value = true;
}
function handleCancel() {
    if (! cancelTarget.value) return;
    cancelProcessing.value = true;
    router.post(`/subscriptions/${cancelTarget.value.id}/cancel`, {
        immediately: cancelMode.value === 'immediately',
        cancels_at: cancelMode.value === 'scheduled' ? cancelDate.value : null,
    }, {
        preserveScroll: true,
        onFinish: () => {
            cancelProcessing.value = false;
            showCancel.value = false;
            cancelTarget.value = null;
        },
    });
}
const cancelDisabled = computed(() =>
    cancelMode.value === 'scheduled' && ! cancelDate.value,
);

/* ─── Pagination ─── */
const prevUrl = computed(() => props.subscriptions.prev_page_url);
const nextUrl = computed(() => props.subscriptions.next_page_url);
</script>

<template>
    <Head title="Subscriptions" />

    <InternalLayout title="Subscriptions" :breadcrumbs="breadcrumbs" active-nav="subscriptions">
        <template #topbar-actions>
            <button type="button" class="btn btn-ghost btn-sm" style="color: var(--text-secondary);" disabled>
                <IconDownload :size="14" stroke-width="1.75" />
                Export
            </button>
        </template>

        <div class="subscriptions">
            <!-- Greeting -->
            <div class="greet">
                <div>
                    <h1>Subscriptions</h1>
                    <div class="sub">
                        {{ analytics.active_count }} active · {{ analytics.trial_count }} trials · {{ gbpRound(analytics.mrr) }} MRR
                    </div>
                </div>
            </div>

            <!-- Stripe info banner (dismissible) -->
            <div v-if="stripeBannerVisible" class="stripe-banner">
                <div class="ic"><IconBrandStripe :size="20" stroke-width="1.75" /></div>
                <div class="text">
                    <strong>Connect Stripe</strong> to sync subscriptions automatically. When a customer subscribes on your product websites, they'll appear here instantly.
                </div>
                <a href="#" class="more" @click.prevent>Learn more
                    <IconExternalLink :size="12" stroke-width="1.75" style="vertical-align: -1px; margin-left: 2px;" />
                </a>
                <button class="close" aria-label="Dismiss" @click="dismissStripeBanner">
                    <IconX :size="16" stroke-width="1.75" />
                </button>
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

            <!-- KPI cards -->
            <div class="kpi-row">
                <div class="kpi">
                    <div class="kpi-top"><div class="kpi-icon gold"><IconCurrencyPound :size="18" stroke-width="1.75" /></div></div>
                    <div class="kpi-mid">
                        <div class="kpi-value">{{ gbp(analytics.mrr) }}</div>
                        <div class="kpi-label">Monthly recurring revenue</div>
                    </div>
                    <div class="kpi-foot">{{ gbpRound(analytics.arr) }} ARR</div>
                </div>
                <div class="kpi">
                    <div class="kpi-top"><div class="kpi-icon teal"><IconCircleCheck :size="18" stroke-width="1.75" /></div></div>
                    <div class="kpi-mid">
                        <div class="kpi-value">{{ analytics.active_count }}</div>
                        <div class="kpi-label">Active subscriptions</div>
                    </div>
                    <div class="kpi-foot" :class="{ up: analytics.new_this_month > 0 }">
                        +{{ analytics.new_this_month }} this month
                    </div>
                </div>
                <div class="kpi">
                    <div class="kpi-top"><div class="kpi-icon amber"><IconClock :size="18" stroke-width="1.75" /></div></div>
                    <div class="kpi-mid">
                        <div class="kpi-value">{{ analytics.trial_count }}</div>
                        <div class="kpi-label">On trial</div>
                    </div>
                    <div class="kpi-foot" :class="analytics.trial_converting_soon > 0 ? 'warn' : 'up'">
                        <template v-if="analytics.trial_converting_soon > 0">{{ analytics.trial_converting_soon }} converting in 7 days</template>
                        <template v-else>None expiring soon</template>
                    </div>
                </div>
                <div class="kpi">
                    <div class="kpi-top"><div class="kpi-icon blue"><IconUserPlus :size="18" stroke-width="1.75" /></div></div>
                    <div class="kpi-mid">
                        <div class="kpi-value">{{ analytics.new_this_month }}</div>
                        <div class="kpi-label">New this month</div>
                    </div>
                </div>
                <div class="kpi">
                    <div class="kpi-top">
                        <div class="kpi-icon" :class="analytics.churned_this_month > 0 ? 'red' : 'neutral'">
                            <IconUserMinus :size="18" stroke-width="1.75" />
                        </div>
                    </div>
                    <div class="kpi-mid">
                        <div class="kpi-value">{{ analytics.churned_this_month }}</div>
                        <div class="kpi-label">Churned this month</div>
                    </div>
                </div>
            </div>

            <!-- Per-product MRR strip -->
            <div v-if="analytics.by_product.length" class="sub-product-strip">
                <template v-for="(p, i) in analytics.by_product" :key="p.slug">
                    <div v-if="i > 0" class="sp-divider" />
                    <div class="sp-item">
                        <div class="sp-icon" :style="{ background: p.icon_colour || '#0D9488' }">{{ p.name?.[0] || '?' }}</div>
                        <span class="sp-name">{{ p.name }}</span>
                        <span class="sp-mrr">{{ gbpRound(p.mrr) }}/mo</span>
                        <span class="sp-count">{{ p.active_count }} active</span>
                    </div>
                </template>
            </div>

            <!-- Filter bar -->
            <div class="filter-bar">
                <div class="field-search">
                    <span class="search-icon"><IconSearch :size="16" stroke-width="1.75" /></span>
                    <input v-model="searchInput" type="text" placeholder="Search by customer…">
                </div>

                <Menu as="div" class="dd-menu">
                    <MenuButton class="dd-btn">
                        <IconCreditCard :size="14" stroke-width="1.75" />
                        {{ productFilter ? products.find((p) => p.slug === productFilter)?.name : 'Product' }}
                        <IconChevronDown :size="14" stroke-width="1.75" class="ch" />
                    </MenuButton>
                    <MenuItems class="dd-popover">
                        <MenuItem v-for="p in products" :key="p.slug" v-slot="{ active }">
                            <button type="button" :class="['dd-option', { active, current: productFilter === p.slug }]" @click="setProduct(p.slug)">
                                {{ p.name }}
                            </button>
                        </MenuItem>
                    </MenuItems>
                </Menu>

                <Menu as="div" class="dd-menu">
                    <MenuButton class="dd-btn">
                        <IconFlag :size="14" stroke-width="1.75" />
                        {{ statusFilter ? STATUS_LABELS[statusFilter] : 'Status' }}
                        <IconChevronDown :size="14" stroke-width="1.75" class="ch" />
                    </MenuButton>
                    <MenuItems class="dd-popover">
                        <MenuItem v-for="st in statuses" :key="st" v-slot="{ active }">
                            <button type="button" :class="['dd-option', { active, current: statusFilter === st }]" @click="setStatus(st)">
                                {{ STATUS_LABELS[st] }}
                            </button>
                        </MenuItem>
                    </MenuItems>
                </Menu>

                <Menu as="div" class="dd-menu">
                    <MenuButton class="dd-btn">
                        <IconArrowsSort :size="14" stroke-width="1.75" />
                        {{ intervalFilter ? INTERVAL_LABELS[intervalFilter] : 'Billing' }}
                        <IconChevronDown :size="14" stroke-width="1.75" class="ch" />
                    </MenuButton>
                    <MenuItems class="dd-popover">
                        <MenuItem v-for="iv in intervals" :key="iv" v-slot="{ active }">
                            <button type="button" :class="['dd-option', { active, current: intervalFilter === iv }]" @click="setInterval(iv)">
                                {{ INTERVAL_LABELS[iv] }}
                            </button>
                        </MenuItem>
                    </MenuItems>
                </Menu>

                <div class="right">
                    <button type="button" class="btn btn-ghost btn-sm" style="color: var(--text-secondary);" :class="{ 'btn-dot': hasFilters }" disabled>
                        <IconAdjustmentsHorizontal :size="14" stroke-width="1.75" />
                        Filters
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm" style="color: var(--text-secondary);" disabled>
                        <IconArrowsSort :size="14" stroke-width="1.75" />
                        Sort: Started
                        <IconArrowDown :size="13" stroke-width="1.75" />
                    </button>
                </div>
            </div>

            <!-- Subscriptions table -->
            <div class="table-card" style="margin-top: 12px;">
                <table class="tbl">
                    <colgroup>
                        <col>
                        <col style="width: 180px;">
                        <col style="width: 140px;">
                        <col style="width: 140px;">
                        <col style="width: 110px;">
                        <col style="width: 160px;">
                        <col style="width: 130px;">
                        <col style="width: 56px;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Product</th>
                            <th>Plan</th>
                            <th>Price</th>
                            <th>Billing</th>
                            <th>Status</th>
                            <th>Next billing</th>
                            <th />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="! subscriptions.data.length">
                            <td colspan="8">
                                <div class="empty-state">
                                    <IconReceiptOff :size="48" stroke-width="1.5" />
                                    <h3>No subscriptions found</h3>
                                    <p v-if="hasFilters">Try clearing your filters.</p>
                                    <p v-else>Subscriptions appear here once customers have active products.</p>
                                </div>
                            </td>
                        </tr>

                        <tr v-for="sub in subscriptions.data" :key="sub.id">
                            <td>
                                <Link :href="`/customers/${sub.customer.id}`" class="cell-cust" style="color: inherit; text-decoration: none;">
                                    <div class="avatar" :style="{ background: avatarColour(sub.customer.id), color: '#fff' }">
                                        {{ initials(sub.customer.name) }}
                                    </div>
                                    <div>
                                        <div class="cust-name">{{ sub.customer.name }}</div>
                                        <div class="cust-loc">{{ sub.customer.city || '—' }}</div>
                                    </div>
                                </Link>
                            </td>
                            <td>
                                <div class="cell-prod">
                                    <div class="pi" :style="{ background: sub.product.icon_colour || '#0D9488' }">{{ sub.product.name?.[0] || '?' }}</div>
                                    <span class="pn">{{ sub.product.name }}</span>
                                </div>
                            </td>
                            <td>
                                <div class="plan-cell">
                                    <span class="pl" :class="{ muted: ! sub.plan }">{{ sub.plan || '—' }}</span>
                                    <span v-if="sub.stripe_subscription_id" class="stripe-chip">
                                        <IconBrandStripe :size="11" stroke-width="2" />
                                        Stripe
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div class="price-cell">
                                    <template v-if="discountActive(sub)">
                                        <span class="price-original">{{ gbp(sub.price_monthly) }}{{ priceSuffix(sub.billing_interval) }}</span>
                                        <span class="price-effective">{{ gbp(sub.effective_price) }}{{ priceSuffix(sub.billing_interval) }}</span>
                                        <span class="discount-badge">-{{ sub.discount_pct }}%</span>
                                    </template>
                                    <template v-else-if="sub.billing_interval === 'one_off'">
                                        <span class="price-main">One-off</span>
                                    </template>
                                    <template v-else>
                                        <span class="price-main">{{ gbp(sub.price_monthly) }}{{ priceSuffix(sub.billing_interval) }}</span>
                                    </template>
                                </div>
                            </td>
                            <td>
                                <span class="badge badge-sm" :class="intervalBadgeClass(sub.billing_interval)">
                                    {{ INTERVAL_LABELS[sub.billing_interval] }}
                                </span>
                            </td>
                            <td>
                                <div class="status-cell">
                                    <span class="badge" :class="statusBadgeClass(sub.status)">{{ STATUS_LABELS[sub.status] }}</span>
                                    <span
                                        v-if="sub.status === 'trial' && sub.trial_ends_at && daysUntil(sub.trial_ends_at) !== null && daysUntil(sub.trial_ends_at) <= 7"
                                        class="warn-pill-inline"
                                    >
                                        <IconClock :size="11" stroke-width="1.75" />
                                        {{ Math.max(daysUntil(sub.trial_ends_at), 0) }} days left
                                    </span>
                                    <span v-if="sub.cancels_at" class="warn-pill-inline">
                                        <IconAlertTriangle :size="11" stroke-width="1.75" />
                                        Cancels {{ formatDate(sub.cancels_at) }}
                                    </span>
                                </div>
                            </td>
                            <td>
                                <span class="next-bill" :class="nextBillingClass(sub)">
                                    {{ sub.next_billing_date ? formatDate(sub.next_billing_date) : '—' }}
                                </span>
                            </td>
                            <td>
                                <Menu as="div" class="dd-menu">
                                    <MenuButton class="icon-btn" aria-label="Actions">
                                        <IconDots :size="16" stroke-width="1.75" />
                                    </MenuButton>
                                    <MenuItems class="dd-popover right-align">
                                        <MenuItem v-slot="{ active }">
                                            <Link :class="['dd-option', { active }]" :href="`/customers/${sub.customer.id}`">
                                                View customer
                                            </Link>
                                        </MenuItem>
                                        <MenuItem v-slot="{ active }">
                                            <button type="button" :class="['dd-option', { active }]" @click="openEdit(sub)">
                                                Edit subscription
                                            </button>
                                        </MenuItem>
                                        <div style="height: 1px; background: var(--border-soft); margin: 4px 0;" />
                                        <MenuItem v-if="['active', 'trial'].includes(sub.status)" v-slot="{ active }">
                                            <button type="button" :class="['dd-option', { active }]" style="color: var(--danger);" @click="askCancel(sub)">
                                                Cancel subscription
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
                        Showing <strong>{{ subscriptions.from || 0 }} – {{ subscriptions.to || 0 }}</strong> of <strong>{{ subscriptions.total }}</strong> subscriptions
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
        </div>

        <!-- Edit slide-over -->
        <TransitionRoot as="template" :show="showEdit">
            <Dialog as="div" class="slide-over-dialog" @close="showEdit = false">
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
                        <form v-if="editingSub" class="slide-over-form" @submit.prevent="submitEdit">
                            <header class="slide-over-header">
                                <h2>Edit subscription</h2>
                                <button type="button" class="icon-btn" aria-label="Close" @click="showEdit = false">
                                    <IconX :size="18" stroke-width="1.75" />
                                </button>
                            </header>

                            <div class="slide-over-body">
                                <!-- Context summary -->
                                <div class="sub-context">
                                    <div class="avatar" :style="{ background: avatarColour(editingSub.customer.id), color: '#fff' }">
                                        {{ initials(editingSub.customer.name) }}
                                    </div>
                                    <div class="sub-context-meta">
                                        <div class="nm">{{ editingSub.customer.name }}</div>
                                        <div class="sb">{{ editingSub.product.name }}<span v-if="editingSub.customer.city"> · {{ editingSub.customer.city }}</span></div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <h3>Plan</h3>
                                    <!-- Current plan summary (read-only) when one is set and the picker isn't open -->
                                    <template v-if="editForm.plan_id && ! showPlanPicker">
                                        <div style="padding: 10px 14px; background: var(--neutral-bg); border-radius: var(--radius-md); display: flex; align-items: center; gap: 12px;">
                                            <div style="font: 600 13px/1.2 'Inter', sans-serif;">{{ editForm.plan || '—' }}</div>
                                            <div style="font: 600 13px/1.2 'Inter', sans-serif; color: var(--accent); margin-left: auto;">{{ gbp(editForm.price_monthly) }}{{ priceSuffix(editForm.billing_interval) }}</div>
                                            <button type="button" class="collapsible-trigger" style="padding: 0;" @click="showPlanPicker = true">Change plan</button>
                                        </div>
                                    </template>
                                    <!-- Plan picker (radio cards) -->
                                    <template v-else>
                                        <div v-if="plansForCurrentProduct().length === 0" style="padding: 10px 14px; background: var(--neutral-bg); border-radius: var(--radius-md); color: var(--text-secondary); font: 400 13px/1.5 'Inter', sans-serif;">
                                            No plans defined for this product yet. Use the custom price override below.
                                        </div>
                                        <div v-else style="display: flex; flex-direction: column; gap: 8px;">
                                            <button
                                                v-for="plan in plansForCurrentProduct()"
                                                :key="plan.id"
                                                type="button"
                                                class="ent-opt"
                                                :class="{ selected: editForm.plan_id === plan.id }"
                                                style="padding: 10px 14px; align-items: flex-start; flex-direction: column; gap: 3px;"
                                                @click="selectEditPlan(plan)"
                                            >
                                                <div style="display: flex; align-items: center; gap: 10px; width: 100%;">
                                                    <span style="font: 600 13px/1.2 'Inter', sans-serif;">{{ plan.name }}</span>
                                                    <span style="margin-left: auto; font: 600 13px/1.2 'Inter', sans-serif; color: var(--accent);">{{ gbp(plan.price_monthly) }}/mo</span>
                                                </div>
                                                <span v-if="plan.price_annual !== null" style="font: 400 11px/1.3 'Inter', sans-serif; color: var(--text-secondary);">
                                                    or {{ gbp(plan.price_annual) }}/yr
                                                </span>
                                            </button>
                                        </div>
                                    </template>
                                </div>

                                <!-- Custom price override (collapsible) -->
                                <div class="form-section">
                                    <button
                                        v-if="! showPriceOverride"
                                        type="button"
                                        class="collapsible-trigger"
                                        @click="showPriceOverride = true"
                                    >
                                        <IconPlus :size="14" stroke-width="2" />
                                        Custom price override
                                    </button>
                                    <template v-else>
                                        <h3>Custom price override</h3>
                                        <div class="form-row two">
                                            <div class="form-field">
                                                <label>Plan label</label>
                                                <input v-model="editForm.plan" type="text" placeholder="e.g. Pro (custom)">
                                                <div v-if="editForm.errors.plan" class="err">{{ editForm.errors.plan }}</div>
                                            </div>
                                            <div class="form-field">
                                                <label>Price (£)</label>
                                                <input v-model.number="editForm.price_monthly" type="number" min="0" step="0.01">
                                                <div class="field-help">Overrides the plan price. Annual subscriptions show the full annual amount.</div>
                                                <div v-if="editForm.errors.price_monthly" class="err">{{ editForm.errors.price_monthly }}</div>
                                            </div>
                                        </div>
                                    </template>
                                </div>

                                <div class="form-section">
                                    <h3>Billing interval</h3>
                                    <div class="type-toggle" style="grid-template-columns: 1fr 1fr 1fr;">
                                        <button
                                            type="button"
                                            class="type-opt"
                                            :class="{ active: editForm.billing_interval === 'monthly' }"
                                            @click="editForm.billing_interval = 'monthly'"
                                        >Monthly</button>
                                        <button
                                            type="button"
                                            class="type-opt"
                                            :class="{ active: editForm.billing_interval === 'annual' }"
                                            @click="editForm.billing_interval = 'annual'"
                                        >Annual</button>
                                        <button
                                            type="button"
                                            class="type-opt"
                                            :class="{ active: editForm.billing_interval === 'one_off' }"
                                            @click="editForm.billing_interval = 'one_off'"
                                        >One-off</button>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <div class="form-row two">
                                        <div class="form-field">
                                            <label>Next billing date</label>
                                            <input v-model="editForm.next_billing_date" type="date">
                                        </div>
                                        <div class="form-field">
                                            <label>Billing entity</label>
                                            <select v-model="editForm.billing_entity_id">
                                                <option :value="null">— None —</option>
                                                <option v-for="be in billing_entities" :key="be.id" :value="be.id">{{ be.name }}</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- Discount (collapsible) -->
                                <div class="form-section">
                                    <button
                                        v-if="! showDiscountSection"
                                        type="button"
                                        class="collapsible-trigger"
                                        @click="showDiscountSection = true"
                                    >
                                        <IconPlus :size="14" stroke-width="2" />
                                        Add discount
                                    </button>
                                    <template v-else>
                                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                                            <h3 style="margin: 0;">Discount</h3>
                                            <button type="button" class="collapsible-trigger danger" style="padding: 0;" @click="removeDiscount">Remove</button>
                                        </div>
                                        <div class="form-row two">
                                            <div class="form-field">
                                                <label>Discount %</label>
                                                <input v-model.number="editForm.discount_pct" type="number" min="0" max="100" step="0.01">
                                                <div v-if="editForm.errors.discount_pct" class="err">{{ editForm.errors.discount_pct }}</div>
                                            </div>
                                            <div class="form-field">
                                                <label>Expires <span style="color: var(--text-tertiary); font-weight: 400;">(optional)</span></label>
                                                <input v-model="editForm.discount_expires_at" type="date">
                                            </div>
                                        </div>
                                        <div v-if="previewEffective !== null" class="sub-preview">
                                            {{ gbp(editForm.price_monthly) }} → {{ gbp(previewEffective) }}{{ priceSuffix(editForm.billing_interval) }}
                                            ({{ editForm.discount_pct }}% off)
                                        </div>
                                    </template>
                                </div>

                                <!-- Stripe sync (collapsible) -->
                                <div class="form-section">
                                    <button
                                        v-if="! showStripeSection"
                                        type="button"
                                        class="collapsible-trigger"
                                        @click="showStripeSection = true"
                                    >
                                        <IconBrandStripe :size="14" stroke-width="1.75" />
                                        Stripe sync
                                    </button>
                                    <template v-else>
                                        <h3 style="margin-bottom: 6px;">Stripe sync</h3>
                                        <div class="form-row single">
                                            <div class="form-field">
                                                <label>Subscription ID</label>
                                                <input v-model="editForm.stripe_subscription_id" type="text" placeholder="sub_1Nxxx..." style="font-family: 'JetBrains Mono', monospace;">
                                            </div>
                                        </div>
                                        <div class="form-row single">
                                            <div class="form-field">
                                                <label>Price ID</label>
                                                <input v-model="editForm.stripe_price_id" type="text" placeholder="price_1Nxxx..." style="font-family: 'JetBrains Mono', monospace;">
                                                <div class="field-help">These fields will be populated automatically when Stripe webhook integration is built.</div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <footer class="slide-over-footer">
                                <button type="button" class="btn btn-secondary" @click="showEdit = false">Cancel</button>
                                <button type="submit" class="btn btn-primary" :disabled="editForm.processing">
                                    <IconCheck :size="15" stroke-width="1.75" />
                                    {{ editForm.processing ? 'Saving…' : 'Save changes' }}
                                </button>
                            </footer>
                        </form>
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </TransitionRoot>

        <!-- Cancel confirmation modal -->
        <ConfirmModal
            v-model:show="showCancel"
            :title="cancelTarget ? `Cancel ${cancelTarget.product.name} for ${cancelTarget.customer.name}?` : 'Cancel subscription?'"
            confirm-label="Cancel subscription"
            cancel-label="Keep subscription"
            variant="danger"
            :loading="cancelProcessing || cancelDisabled"
            @confirm="handleCancel"
        >
            <div class="cancel-options">
                <label :class="{ selected: cancelMode === 'immediately' }">
                    <input v-model="cancelMode" type="radio" value="immediately">
                    <div class="opt-meta">
                        <span class="nm">Cancel immediately</span>
                        <span class="sb">Access is removed now. No further billing.</span>
                    </div>
                </label>
                <label :class="{ selected: cancelMode === 'scheduled' }">
                    <input v-model="cancelMode" type="radio" value="scheduled">
                    <div class="opt-meta">
                        <span class="nm">Schedule cancellation</span>
                        <span class="sb">Customer keeps access until the date you pick.</span>
                        <input
                            v-if="cancelMode === 'scheduled'"
                            v-model="cancelDate"
                            type="date"
                            :min="new Date().toISOString().slice(0, 10)"
                            required
                        >
                    </div>
                </label>
            </div>
        </ConfirmModal>
    </InternalLayout>
</template>
