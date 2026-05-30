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
    IconPlus,
    IconFileImport,
    IconSearch,
    IconLayoutGrid,
    IconGitBranch,
    IconUsersGroup,
    IconArrowsSort,
    IconChevronDown,
    IconChevronLeft,
    IconChevronRight,
    IconDots,
    IconAlertCircle,
    IconX,
    IconUsers,
    IconExternalLink,
} from '@tabler/icons-vue';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
import InternalLayout from '@/Layouts/InternalLayout.vue';

dayjs.extend(relativeTime);

const props = defineProps({
    customers: { type: Object, required: true },
    filters: { type: Object, required: true },
    summary: { type: Object, required: true },
    products: { type: Array, default: () => [] },
    referrers: { type: Array, default: () => [] },
    pipeline_stages: { type: Array, default: () => [] },
    types: { type: Array, default: () => [] },
    contact_roles: { type: Array, default: () => [] },
    assignable_users: { type: Array, default: () => [] },
});

const page = usePage();
const me = computed(() => page.props.auth?.user);

const breadcrumbs = [
    { label: 'Powerhouse', href: '/' },
    { label: 'Workspace' },
    { label: 'Customers' },
];

const PIPELINE_LABELS = {
    lead: 'Lead',
    prospect: 'Prospect',
    active: 'Active',
    churned: 'Churned',
};

const TYPE_LABELS = {
    restaurant: 'Restaurant',
    bar: 'Bar',
    bakery: 'Bakery',
    cafe: 'Café',
    venue: 'Venue',
    other: 'Other',
};

const CONTACT_ROLE_LABELS = {
    owner: 'Owner',
    manager: 'Manager',
    accounts: 'Accounts',
    other: 'Other',
};

const SORT_LABELS = {
    last_active: 'Last active',
    name: 'Name',
    created_at: 'Created',
};

/* ─── Live search (400ms debounce) ─── */
const searchInput = ref(props.filters.search ?? '');
let searchTimer = null;

watch(searchInput, (value) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        if ((value ?? '') === (props.filters.search ?? '')) return;
        navigate({ search: value, page: 1 });
    }, 400);
});

function navigate(patch) {
    const query = { ...props.filters, ...patch };
    Object.keys(query).forEach((k) => {
        if (query[k] === '' || query[k] === null || query[k] === undefined) {
            delete query[k];
        }
    });
    router.get('/customers', query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function setFilter(key, value) {
    navigate({ [key]: value, page: 1 });
}

function clearSearch() {
    searchInput.value = '';
    navigate({ search: null, page: 1 });
}

const hasActiveFilters = computed(() =>
    !!(props.filters.search || props.filters.pipeline_stage || props.filters.product_slug || props.filters.referrer_id)
);

/* ─── Display helpers ─── */
function customerInitials(name) {
    const parts = String(name || '').trim().split(/\s+/);
    return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || '?';
}

function customerAvatarClass(id) {
    const palette = ['av-1', 'av-2', 'av-3', 'av-5', 'av-teal', 'av-amber', 'av-navy', 'av-grey'];
    return palette[(id ?? 0) % palette.length];
}

function referrerAvatarClass(name) {
    if (!name) return 'av-grey';
    const code = name.charCodeAt(0) || 0;
    const palette = ['av-amber', 'av-teal', 'av-grey', 'av-2', 'av-3'];
    return palette[code % palette.length];
}

function referrerInitials(name) {
    const parts = String(name || '').trim().split(/\s+/);
    return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || '?';
}

function firstName(name) {
    return String(name || '').trim().split(/\s+/)[0] || '';
}

function locationLine(c) {
    return [c.city, c.country].filter(Boolean).join(', ');
}

function formatMrr(amount) {
    const value = Number(amount || 0);
    return '£' + Math.round(value).toLocaleString('en-GB');
}

function lastActive(iso) {
    if (!iso) return '—';
    const m = dayjs(iso);
    const now = dayjs();
    if (m.isSame(now, 'day')) return m.fromNow();
    if (m.isSame(now.subtract(1, 'day'), 'day')) return 'Yesterday';
    const daysAgo = now.diff(m, 'day');
    if (daysAgo < 7) return `${daysAgo} days ago`;
    if (daysAgo < 30) return `${Math.floor(daysAgo / 7)} week${Math.floor(daysAgo / 7) === 1 ? '' : 's'} ago`;
    if (daysAgo < 365) return `${Math.floor(daysAgo / 30)} month${Math.floor(daysAgo / 30) === 1 ? '' : 's'} ago`;
    return m.format('D MMM YYYY');
}

function pipClass(stage) {
    return ({
        active: 'active',
        prospect: 'prospect',
        lead: 'lead',
        churned: 'churned',
    })[stage] || 'lead';
}

function statusBadge(customer) {
    if (customer.archived_at) return { class: 'badge-inactive', label: 'Archived' };
    const hasActive = customer.products?.some((p) => p.status === 'active');
    if (hasActive) return { class: 'badge-active', label: 'Active' };
    const hasTrial = customer.products?.some((p) => p.status === 'trial');
    if (hasTrial) return { class: 'badge-trial', label: 'Trial' };
    if (customer.pipeline_stage === 'churned') return { class: 'badge-overdue', label: 'Churned' };
    return { class: 'badge-inactive', label: 'Inactive' };
}

/* ─── Pagination + table footer ─── */
const pageMeta = computed(() => ({
    from: props.customers.from ?? 0,
    to: props.customers.to ?? 0,
    total: props.customers.total ?? 0,
    links: props.customers.links ?? [],
}));

function navigateToLink(url) {
    if (!url) return;
    router.visit(url, { preserveScroll: true, preserveState: true });
}

/* ─── Row click ─── */
function openCustomer(id) {
    router.visit(`/customers/${id}`);
}

/* ─── Create slide-over ─── */
const showCreate = ref(false);

const form = useForm({
    name: '',
    trading_name: '',
    company_number: '',
    vat_number: '',
    type: 'restaurant',
    address_line1: '',
    address_line2: '',
    city: '',
    postcode: '',
    country: 'GB',
    pipeline_stage: 'lead',
    // Acquisition channel + the detail follow-up; referrer_id only
    // surfaces when channel === 'referral'. All three are nullable
    // server-side so a quick lead-capture skips them entirely.
    acquisition_channel: null,
    channel_detail: '',
    referrer_id: null,
    assigned_to: '',
    contact_name: '',
    contact_email: '',
    contact_phone: '',
    contact_role: 'owner',
});

/* ─── Acquisition channel picker ─── */
const CHANNELS = [
    { key: 'direct',        label: 'Direct',         icon: 'navigation' },
    { key: 'google',        label: 'Google',         icon: 'search' },
    { key: 'social_media',  label: 'Social media',   icon: 'share' },
    { key: 'landing_page',  label: 'Landing page',   icon: 'layout' },
    { key: 'referral',      label: 'Referral',       icon: 'users' },
    { key: 'email',         label: 'Email',          icon: 'mail' },
    { key: 'event',         label: 'Event',          icon: 'calendar' },
    { key: 'word_of_mouth', label: 'Word of mouth',  icon: 'message' },
    { key: 'other',         label: 'Other',          icon: 'dots' },
];
const CHANNEL_DETAIL = {
    social_media:  { label: 'Which platform?',           placeholder: 'e.g. Instagram, LinkedIn' },
    landing_page:  { label: 'Which page / campaign?',    placeholder: 'e.g. Summer 2026 offer' },
    email:         { label: 'Campaign name',             placeholder: 'e.g. May newsletter' },
    event:         { label: 'Event name',                placeholder: 'e.g. Bath Restaurant Week' },
    google:        { label: 'Search term or campaign',   placeholder: 'e.g. "Bristol POS"' },
};
const channelNeedsDetail = computed(() => CHANNEL_DETAIL[form.acquisition_channel] !== undefined);
const channelDetailLabel = computed(() => CHANNEL_DETAIL[form.acquisition_channel]?.label ?? 'Details');
const channelDetailPlaceholder = computed(() => CHANNEL_DETAIL[form.acquisition_channel]?.placeholder ?? '');

function openCreate() {
    form.reset();
    form.clearErrors();
    if (me.value?.id) form.assigned_to = me.value.id;
    showCreate.value = true;
}

function submit() {
    form.post('/customers', {
        preserveScroll: true,
        onSuccess: () => {
            showCreate.value = false;
        },
    });
}

// Dashboard's "+ New customer" button navigates here with ?create=1
// so the slide-over pops open on mount. Strip the param from the URL
// once consumed so a reload doesn't re-open it.
onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get('create') === '1') {
        openCreate();
        params.delete('create');
        const qs = params.toString();
        window.history.replaceState({}, '', '/customers' + (qs ? '?' + qs : ''));
    }
});
</script>

<template>
    <Head title="Customers" />

    <InternalLayout title="Customers" :breadcrumbs="breadcrumbs" active-nav="customers">
        <template #topbar-actions>
            <button class="btn btn-secondary" type="button">
                <IconFileImport :size="15" stroke-width="1.75" />
                Import CSV
            </button>
            <button class="btn btn-primary" type="button" @click="openCreate">
                <IconPlus :size="15" stroke-width="1.75" />
                New customer
            </button>
        </template>

        <!-- Greeting -->
        <div class="greet">
            <div>
                <h1>Customers</h1>
                <div class="sub">
                    {{ summary.total.toLocaleString('en-GB') }} customers across Maavelus, MyOrderPad, and Whitedash B2B
                </div>
            </div>
        </div>

        <!-- Summary strip -->
        <div class="summary-strip">
            <div class="stat-pill"><span class="d gold" /><strong>{{ summary.total }}</strong><span class="lbl">total</span></div>
            <div class="stat-pill"><span class="d green" /><strong>{{ summary.active }}</strong><span class="lbl">active</span></div>
            <div class="stat-pill"><span class="d blue" /><strong>{{ summary.trial }}</strong><span class="lbl">trial</span></div>
            <div class="stat-pill"><span class="d grey" /><strong>{{ summary.inactive }}</strong><span class="lbl">inactive / churned</span></div>
        </div>

        <!-- Filter bar -->
        <div class="filter-bar">
            <div class="field-search">
                <span class="search-icon"><IconSearch :size="16" stroke-width="1.75" /></span>
                <input
                    v-model="searchInput"
                    placeholder="Search by name, email, company…"
                    type="search"
                >
            </div>

            <!-- Product filter -->
            <Menu as="div" class="dd-menu">
                <MenuButton class="dd-btn">
                    <IconLayoutGrid :size="16" stroke-width="1.75" />
                    <span class="lead">Product:</span>
                    <span>{{ filters.product_slug ? products.find(p => p.slug === filters.product_slug)?.name : 'All products' }}</span>
                    <IconChevronDown :size="14" class="ch" stroke-width="1.75" />
                </MenuButton>
                <MenuItems class="dd-popover">
                    <MenuItem v-slot="{ active }">
                        <button type="button" :class="['dd-option', { active }]" @click="setFilter('product_slug', null)">All products</button>
                    </MenuItem>
                    <MenuItem v-for="p in products" :key="p.id" v-slot="{ active }">
                        <button type="button" :class="['dd-option', { active }]" @click="setFilter('product_slug', p.slug)">{{ p.name }}</button>
                    </MenuItem>
                </MenuItems>
            </Menu>

            <!-- Pipeline filter -->
            <Menu as="div" class="dd-menu">
                <MenuButton class="dd-btn">
                    <IconGitBranch :size="16" stroke-width="1.75" />
                    <span class="lead">Pipeline:</span>
                    <span>{{ filters.pipeline_stage ? PIPELINE_LABELS[filters.pipeline_stage] : 'All stages' }}</span>
                    <IconChevronDown :size="14" class="ch" stroke-width="1.75" />
                </MenuButton>
                <MenuItems class="dd-popover">
                    <MenuItem v-slot="{ active }">
                        <button type="button" :class="['dd-option', { active }]" @click="setFilter('pipeline_stage', null)">All stages</button>
                    </MenuItem>
                    <MenuItem v-for="stage in pipeline_stages" :key="stage" v-slot="{ active }">
                        <button type="button" :class="['dd-option', { active }]" @click="setFilter('pipeline_stage', stage)">{{ PIPELINE_LABELS[stage] }}</button>
                    </MenuItem>
                </MenuItems>
            </Menu>

            <!-- Referrer filter -->
            <Menu as="div" class="dd-menu">
                <MenuButton class="dd-btn">
                    <IconUsersGroup :size="16" stroke-width="1.75" />
                    <span class="lead">Referrer:</span>
                    <span>{{ filters.referrer_id ? referrers.find(r => r.id == filters.referrer_id)?.name : 'All referrers' }}</span>
                    <IconChevronDown :size="14" class="ch" stroke-width="1.75" />
                </MenuButton>
                <MenuItems class="dd-popover">
                    <MenuItem v-slot="{ active }">
                        <button type="button" :class="['dd-option', { active }]" @click="setFilter('referrer_id', null)">All referrers</button>
                    </MenuItem>
                    <MenuItem v-for="r in referrers" :key="r.id" v-slot="{ active }">
                        <button type="button" :class="['dd-option', { active }]" @click="setFilter('referrer_id', r.id)">{{ r.name }}</button>
                    </MenuItem>
                </MenuItems>
            </Menu>

            <div class="right">
                <button
                    type="button"
                    class="btn btn-ghost btn-sm"
                    :class="{ 'btn-dot': hasActiveFilters }"
                    style="color: var(--text-secondary);"
                >
                    Filters
                </button>
                <div class="divider-v" style="height: 20px;" />
                <!-- Sort dropdown -->
                <Menu as="div" class="dd-menu">
                    <MenuButton class="btn btn-ghost btn-sm" style="color: var(--text-secondary);">
                        <IconArrowsSort :size="14" stroke-width="1.75" />
                        Sort: {{ SORT_LABELS[filters.sort] }}
                    </MenuButton>
                    <MenuItems class="dd-popover right-align">
                        <MenuItem v-for="(label, key) in SORT_LABELS" :key="key" v-slot="{ active }">
                            <button type="button" :class="['dd-option', { active }]" @click="setFilter('sort', key)">{{ label }}</button>
                        </MenuItem>
                    </MenuItems>
                </Menu>
            </div>
        </div>

        <!-- Table card -->
        <div class="table-card">
            <table v-if="customers.data.length" class="tbl">
                <colgroup>
                    <col style="width: 44px;">
                    <col>
                    <col style="width: 110px;">
                    <col style="width: 100px;">
                    <col style="width: 110px;">
                    <col style="width: 140px;">
                    <col style="width: 110px;">
                    <col style="width: 130px;">
                    <col style="width: 56px;">
                </colgroup>
                <thead>
                    <tr>
                        <th />
                        <th>Customer</th>
                        <th>Products</th>
                        <th class="num">MRR</th>
                        <th>Pipeline</th>
                        <th>Referrer</th>
                        <th>Last active</th>
                        <th>Status</th>
                        <th />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in customers.data" :key="c.id" @click="openCustomer(c.id)">
                        <td @click.stop>
                            <span class="cb" />
                        </td>
                        <td>
                            <div class="cell-cust">
                                <div class="avatar" :class="customerAvatarClass(c.id)">{{ customerInitials(c.name) }}</div>
                                <div class="cust-meta">
                                    <div class="cust-name">{{ c.name }}</div>
                                    <div class="cust-loc">{{ locationLine(c) || '—' }}</div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div v-if="c.products.length" class="prod-set">
                                <span
                                    v-for="p in c.products"
                                    :key="p.slug"
                                    class="pb"
                                    :class="[p.pb_class, { 'pb-count': p.count > 1 }]"
                                    :data-count="p.count > 1 ? p.count : null"
                                    :title="p.name"
                                >{{ p.name?.[0] ?? '?' }}</span>
                            </div>
                            <span v-else style="color: var(--text-tertiary); font: 500 13px/1 'Inter', sans-serif;">—</span>
                        </td>
                        <td>
                            <span class="mrr" :class="{ muted: !c.mrr }">
                                {{ formatMrr(c.mrr) }}<span v-if="c.mrr" class="u">/mo</span>
                            </span>
                        </td>
                        <td><span class="pip" :class="pipClass(c.pipeline_stage)">{{ PIPELINE_LABELS[c.pipeline_stage] }}</span></td>
                        <td>
                            <span v-if="c.referrer" class="ref-cell">
                                <span class="avatar" :class="referrerAvatarClass(c.referrer.name)">{{ referrerInitials(c.referrer.name) }}</span>
                                {{ firstName(c.referrer.name) }}
                            </span>
                            <span v-else class="ref-cell direct">Direct</span>
                        </td>
                        <td><span class="last-active">{{ lastActive(c.updated_at) }}</span></td>
                        <td>
                            <span class="status-cell">
                                <span class="badge" :class="statusBadge(c).class">{{ statusBadge(c).label }}</span>
                            </span>
                        </td>
                        <td @click.stop>
                            <Menu as="div" class="dd-menu">
                                <MenuButton class="icon-btn" aria-label="Actions">
                                    <IconDots :size="18" stroke-width="1.75" />
                                </MenuButton>
                                <MenuItems class="dd-popover right-align">
                                    <MenuItem v-slot="{ active }">
                                        <Link :href="`/customers/${c.id}`" :class="['dd-option', { active }]">View customer</Link>
                                    </MenuItem>
                                    <MenuItem v-slot="{ active }">
                                        <Link :href="`/invoices/new?customer_id=${c.id}`" :class="['dd-option', { active }]">New invoice</Link>
                                    </MenuItem>
                                    <MenuItem v-slot="{ active }">
                                        <Link :href="`/customers/${c.id}`" :class="['dd-option', { active }]">Edit</Link>
                                    </MenuItem>
                                    <MenuItem v-slot="{ active }">
                                        <button type="button" :class="['dd-option', { active }]">Archive</button>
                                    </MenuItem>
                                </MenuItems>
                            </Menu>
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Empty state -->
            <div v-else class="empty-state">
                <div class="empty-icon"><IconUsers :size="48" stroke-width="1.5" /></div>
                <h3>No customers found</h3>
                <p>Try adjusting your search or filters.</p>
                <a v-if="filters.search" href="#" @click.prevent="clearSearch">Clear search</a>
            </div>

            <!-- Table footer -->
            <div v-if="customers.data.length" class="tbl-foot">
                <div class="info">
                    Showing
                    <strong style="color: var(--text-primary); font-weight: 600;">{{ pageMeta.from }} – {{ pageMeta.to }}</strong>
                    of
                    <strong style="color: var(--text-primary); font-weight: 600;">{{ pageMeta.total }}</strong>
                    customers
                </div>
                <div class="right">
                    <template v-for="(link, i) in pageMeta.links" :key="i">
                        <button
                            v-if="link.label.includes('Previous')"
                            type="button"
                            class="pg-btn"
                            :disabled="!link.url"
                            @click="navigateToLink(link.url)"
                        >
                            <IconChevronLeft :size="14" stroke-width="1.75" />
                            Previous
                        </button>
                        <button
                            v-else-if="link.label.includes('Next')"
                            type="button"
                            class="pg-btn"
                            :disabled="!link.url"
                            @click="navigateToLink(link.url)"
                        >
                            Next
                            <IconChevronRight :size="14" stroke-width="1.75" />
                        </button>
                        <span v-else-if="link.label === '...'" class="pg-ellipsis">…</span>
                        <button
                            v-else
                            type="button"
                            class="pg-btn"
                            :class="{ active: link.active }"
                            :disabled="!link.url"
                            @click="navigateToLink(link.url)"
                        >{{ link.label }}</button>
                    </template>
                </div>
            </div>
        </div>

        <div class="page-foot">
            <div>Powerhouse v3.2.0 · Whitedash</div>
            <div>Sorted by <strong>{{ SORT_LABELS[filters.sort] }}</strong></div>
        </div>

        <!-- ─── New customer slide-over ─── -->
        <TransitionRoot as="template" :show="showCreate">
            <Dialog as="div" class="slide-over" @close="showCreate = false">
                <TransitionChild
                    as="template"
                    enter="ease-out duration-150"
                    enter-from="opacity-0"
                    enter-to="opacity-100"
                    leave="ease-in duration-100"
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
                        <form class="slide-over-form" @submit.prevent="submit">
                            <header class="slide-over-header">
                                <h2>New customer</h2>
                                <button type="button" class="icon-btn" aria-label="Close" @click="showCreate = false">
                                    <IconX :size="18" stroke-width="1.75" />
                                </button>
                            </header>

                            <div class="slide-over-body">
                                <div v-if="form.hasErrors" class="login-error" style="background: var(--danger-bg); color: var(--danger); border-radius: var(--radius-md); padding: 10px 14px; display: flex; gap: 8px; align-items: center;">
                                    <IconAlertCircle :size="18" stroke-width="2" />
                                    <span>Please check the fields below.</span>
                                </div>

                                <!-- Company details -->
                                <div class="form-section">
                                    <div class="form-section-title">Company details</div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label for="name">Name<span class="req">*</span></label>
                                            <input id="name" v-model="form.name" type="text" :class="{ 'has-err': form.errors.name }" required>
                                            <div v-if="form.errors.name" class="err">{{ form.errors.name }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-field">
                                            <label for="trading_name">Trading name</label>
                                            <input id="trading_name" v-model="form.trading_name" type="text">
                                        </div>
                                        <div class="form-field">
                                            <label for="type">Type<span class="req">*</span></label>
                                            <select id="type" v-model="form.type" :class="{ 'has-err': form.errors.type }">
                                                <option v-for="t in types" :key="t" :value="t">{{ TYPE_LABELS[t] }}</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-field">
                                            <label for="company_number">Company number</label>
                                            <input id="company_number" v-model="form.company_number" type="text">
                                        </div>
                                        <div class="form-field">
                                            <label for="vat_number">VAT number</label>
                                            <input id="vat_number" v-model="form.vat_number" type="text">
                                        </div>
                                    </div>
                                </div>

                                <!-- Address -->
                                <div class="form-section">
                                    <div class="form-section-title">Address</div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label for="address_line1">Address line 1<span class="req">*</span></label>
                                            <input id="address_line1" v-model="form.address_line1" type="text" :class="{ 'has-err': form.errors.address_line1 }" required>
                                            <div v-if="form.errors.address_line1" class="err">{{ form.errors.address_line1 }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label for="address_line2">Address line 2</label>
                                            <input id="address_line2" v-model="form.address_line2" type="text">
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-field">
                                            <label for="city">City<span class="req">*</span></label>
                                            <input id="city" v-model="form.city" type="text" :class="{ 'has-err': form.errors.city }" required>
                                            <div v-if="form.errors.city" class="err">{{ form.errors.city }}</div>
                                        </div>
                                        <div class="form-field">
                                            <label for="postcode">Postcode<span class="req">*</span></label>
                                            <input id="postcode" v-model="form.postcode" type="text" :class="{ 'has-err': form.errors.postcode }" required>
                                            <div v-if="form.errors.postcode" class="err">{{ form.errors.postcode }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label for="country">Country</label>
                                            <select id="country" v-model="form.country">
                                                <option value="GB">United Kingdom</option>
                                                <option value="IE">Ireland</option>
                                                <option value="GR">Greece</option>
                                                <option value="CY">Cyprus</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- Primary contact -->
                                <div class="form-section">
                                    <div class="form-section-title">Primary contact</div>
                                    <div class="form-row">
                                        <div class="form-field">
                                            <label for="contact_name">Contact name<span class="req">*</span></label>
                                            <input id="contact_name" v-model="form.contact_name" type="text" :class="{ 'has-err': form.errors.contact_name }" required>
                                            <div v-if="form.errors.contact_name" class="err">{{ form.errors.contact_name }}</div>
                                        </div>
                                        <div class="form-field">
                                            <label for="contact_email">Email<span class="req">*</span></label>
                                            <input id="contact_email" v-model="form.contact_email" type="email" :class="{ 'has-err': form.errors.contact_email }" required>
                                            <div v-if="form.errors.contact_email" class="err">{{ form.errors.contact_email }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-field">
                                            <label for="contact_phone">Phone</label>
                                            <input id="contact_phone" v-model="form.contact_phone" type="tel">
                                        </div>
                                        <div class="form-field">
                                            <label for="contact_role">Role</label>
                                            <select id="contact_role" v-model="form.contact_role">
                                                <option v-for="r in contact_roles" :key="r" :value="r">{{ CONTACT_ROLE_LABELS[r] }}</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- Settings -->
                                <div class="form-section">
                                    <div class="form-section-title">Settings</div>
                                    <div class="form-row">
                                        <div class="form-field">
                                            <label for="pipeline_stage">Pipeline stage</label>
                                            <select id="pipeline_stage" v-model="form.pipeline_stage">
                                                <option v-for="s in pipeline_stages" :key="s" :value="s">{{ PIPELINE_LABELS[s] }}</option>
                                            </select>
                                        </div>
                                        <div class="form-field">
                                            <label for="assigned_to">Assigned to</label>
                                            <select id="assigned_to" v-model="form.assigned_to">
                                                <option value="">— Unassigned —</option>
                                                <option v-for="u in assignable_users" :key="u.id" :value="u.id">{{ u.name }}</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- ─── Acquisition channel ─── -->
                                <!--
                                    Optional. Picker is a grid of pill-cards;
                                    selecting Referral surfaces the referrer
                                    dropdown (required server-side via
                                    required_if). Selecting social/email/event/
                                    google/landing_page surfaces a free-text
                                    detail field.
                                -->
                                <div class="form-section">
                                    <div class="form-section-title">How did they find us? <span class="muted small">(optional)</span></div>
                                    <div class="channel-grid">
                                        <button
                                            v-for="c in CHANNELS"
                                            :key="c.key"
                                            type="button"
                                            class="channel-pill"
                                            :class="{ active: form.acquisition_channel === c.key }"
                                            @click="form.acquisition_channel = (form.acquisition_channel === c.key ? null : c.key); if (form.acquisition_channel !== 'referral') form.referrer_id = null;"
                                        >{{ c.label }}</button>
                                    </div>

                                    <div v-if="channelNeedsDetail" class="form-field" style="margin-top: 10px;">
                                        <label for="channel_detail">{{ channelDetailLabel }}</label>
                                        <input id="channel_detail" v-model="form.channel_detail" type="text" :placeholder="channelDetailPlaceholder" maxlength="255">
                                    </div>

                                    <div v-if="form.acquisition_channel === 'referral'" class="form-field" style="margin-top: 10px;">
                                        <label for="referrer_id">Referred by <span class="req">*</span></label>
                                        <select id="referrer_id" v-model="form.referrer_id" :class="{ 'has-err': form.errors.referrer_id }">
                                            <option :value="null">Select referrer…</option>
                                            <option v-for="r in referrers" :key="r.id" :value="r.id">{{ r.name ?? r.user_name }}</option>
                                        </select>
                                        <div v-if="form.errors.referrer_id" class="err">{{ form.errors.referrer_id }}</div>
                                    </div>
                                </div>
                            </div>

                            <footer class="slide-over-footer">
                                <button type="button" class="btn btn-secondary" @click="showCreate = false">Cancel</button>
                                <button type="submit" class="btn btn-primary" :disabled="form.processing">
                                    {{ form.processing ? 'Creating…' : 'Create customer' }}
                                </button>
                            </footer>
                        </form>
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </TransitionRoot>
    </InternalLayout>
</template>

<style scoped>
.slide-over {
    position: fixed;
    inset: 0;
    z-index: 40;
}
.slide-over-form {
    height: 100%;
    display: flex;
    flex-direction: column;
}
</style>
