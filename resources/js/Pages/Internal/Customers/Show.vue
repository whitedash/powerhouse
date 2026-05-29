<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
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
    IconPencil,
    IconReceipt,
    IconBuilding,
    IconLayoutGrid,
    IconNotes,
    IconAddressBook,
    IconCheckbox,
    IconUsersGroup,
    IconWorld,
    IconActivity,
    IconArrowRight,
    IconExternalLink,
    IconPlus,
    IconDownload,
    IconSend,
    IconLink,
    IconMail,
    IconPhone,
    IconCopy,
    IconCheck,
    IconX,
    IconAlertCircle,
    IconUserPlus,
    IconReceipt2,
    IconArchive,
    IconDots,
    IconAlertTriangle,
} from '@tabler/icons-vue';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
import InternalLayout from '@/Layouts/InternalLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

dayjs.extend(relativeTime);

const props = defineProps({
    customer: { type: Object, required: true },
    users: { type: Array, default: () => [] },
    all_products: { type: Array, default: () => [] },
    available_products: { type: Array, default: () => [] },
    billing_entities: { type: Array, default: () => [] },
    pipeline_stages: { type: Array, default: () => [] },
    contact_roles: { type: Array, default: () => [] },
    note_types: { type: Array, default: () => [] },
    types: { type: Array, default: () => [] },
});

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

const NOTE_TYPE_LABELS = {
    internal: 'Internal',
    call: 'Call',
    meeting: 'Meeting',
    email: 'Email',
};

const ACTION_LABELS = {
    'customer.created': 'Customer created',
    'customer.updated': 'Customer updated',
    'customer.note_added': 'Note added',
    'customer.task_added': 'Task added',
    'customer.archived': 'Customer archived',
};

const ROLE_LABELS = {
    super_admin: 'Super Admin',
    staff: 'Whitedash Staff',
    referrer: 'Referrer',
};

const PRODUCT_PB_COLOURS = {
    maavelus: 'teal',
    myorderpad: 'blue',
    whitedash: 'purple',
    smscube: 'violet',
};

/* ─── Tabs ─── */
const activeTab = ref('overview');
const tabs = computed(() => [
    { key: 'overview',  label: 'Overview' },
    { key: 'invoices',  label: 'Invoices',  count: props.customer.invoices.length },
    { key: 'products',  label: 'Products',  count: props.customer.products.length },
    { key: 'contracts', label: 'Contracts', count: props.customer.contracts_count },
    { key: 'support',   label: 'Support',   count: props.customer.open_tickets },
    { key: 'activity',  label: 'Activity' },
    { key: 'notes',     label: 'Notes',     count: props.customer.notes.length },
]);

/* ─── Breadcrumbs / header data ─── */
const breadcrumbs = computed(() => [
    { label: 'Powerhouse', href: '/' },
    { label: 'Customers', href: '/customers' },
    { label: props.customer.name },
]);

function customerInitials(name) {
    const parts = String(name || '').trim().split(/\s+/);
    return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || '?';
}

function locationLine() {
    const c = props.customer;
    return [c.city, c.country].filter(Boolean).join(', ');
}

function formatGBP(value) {
    return '£' + Math.round(Number(value || 0)).toLocaleString('en-GB');
}

function formatDate(iso) {
    if (!iso) return '—';
    return dayjs(iso).format('D MMM YYYY');
}

function timeAgo(iso) {
    if (!iso) return '—';
    return dayjs(iso).fromNow();
}

function dueLabel(due) {
    if (!due) return { label: 'No date', class: 'muted' };
    const d = dayjs(due);
    const today = dayjs().startOf('day');
    if (d.isSame(today, 'day')) return { label: 'Due today', class: 'red' };
    if (d.isBefore(today)) return { label: 'Overdue', class: 'red' };
    if (d.isSame(today.add(1, 'day'), 'day')) return { label: 'Tomorrow', class: 'amber' };
    return { label: d.format('D MMM'), class: 'muted' };
}

function userInitials(name) {
    return customerInitials(name);
}

function avatarClassForUser(role) {
    if (role === 'super_admin') return 'av-admin';
    if (role === 'referrer') return 'av-amber';
    return 'av-teal';
}

function noteRowClass(type) {
    return type || 'internal';
}

function pbClassForSlug(slug) {
    return PRODUCT_PB_COLOURS[slug] || 'teal';
}

function pipelineSubclass(stage, target) {
    const order = ['lead', 'prospect', 'active', 'churned'];
    if (stage === target) return 'active';
    return order.indexOf(target) < order.indexOf(stage) ? 'done' : '';
}

function invIcClass(status) {
    if (status === 'paid') return 'green';
    if (status === 'overdue') return 'red';
    return 'amber';
}

function invBadgeClass(status) {
    return ({
        paid: 'badge-active',
        sent: 'badge-pending',
        overdue: 'badge-overdue',
        draft: 'badge-inactive',
        void: 'badge-inactive',
    })[status] || 'badge-inactive';
}

function domainTagClass(status) {
    return ({
        healthy: 'act',
        expiring: 'expiring',
        critical: 'critical',
        external: 'external',
    })[status] || 'act';
}

function activityIconClass(action) {
    if (action === 'customer.created') return 'gold';
    if (action === 'customer.note_added' || action === 'customer.task_added') return 'blue';
    if (action === 'customer.archived') return 'red';
    if (action === 'customer.updated') return 'neutral';
    return 'neutral';
}

function activityLabel(action) {
    return ACTION_LABELS[action] || action;
}

/* ─── Note filter ─── */
const noteFilter = ref('all');
const filteredNotes = computed(() => {
    if (noteFilter.value === 'all') return props.customer.notes;
    return props.customer.notes.filter((n) => n.type === noteFilter.value);
});

/* ─── Edit slide-over ─── */
const showEdit = ref(false);
const editForm = useForm({
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
    assigned_to: '',
});

function openEdit() {
    const c = props.customer;
    editForm.name = c.name ?? '';
    editForm.trading_name = c.trading_name ?? '';
    editForm.company_number = c.company_number ?? '';
    editForm.vat_number = c.vat_number ?? '';
    editForm.type = c.type ?? 'restaurant';
    editForm.address_line1 = c.address_line1 ?? '';
    editForm.address_line2 = c.address_line2 ?? '';
    editForm.city = c.city ?? '';
    editForm.postcode = c.postcode ?? '';
    editForm.country = c.country ?? 'GB';
    editForm.pipeline_stage = c.pipeline_stage ?? 'lead';
    editForm.assigned_to = c.assigned_to ?? '';
    editForm.clearErrors();
    showEdit.value = true;
}

function submitEdit() {
    editForm.put(`/customers/${props.customer.id}`, {
        preserveScroll: true,
        onSuccess: () => { showEdit.value = false; },
    });
}

/* ─── Add note ─── */
const showAddNote = ref(false);
const noteForm = useForm({ type: 'internal', body: '' });

function submitNote() {
    noteForm.post(`/customers/${props.customer.id}/notes`, {
        preserveScroll: true,
        onSuccess: () => {
            noteForm.reset();
            showAddNote.value = false;
        },
    });
}

/* ─── Add task ─── */
const showAddTask = ref(false);
const taskForm = useForm({ title: '', due_date: '' });

function submitTask() {
    taskForm.post(`/customers/${props.customer.id}/tasks`, {
        preserveScroll: true,
        onSuccess: () => {
            taskForm.reset();
            showAddTask.value = false;
        },
    });
}

/* ─── Archive ─── */
const showArchiveModal = ref(false);
const archiveProcessing = ref(false);

function archive() {
    showArchiveModal.value = true;
}

function handleArchive() {
    archiveProcessing.value = true;
    router.delete(`/customers/${props.customer.id}/archive`, {
        preserveScroll: true,
        onFinish: () => {
            archiveProcessing.value = false;
            showArchiveModal.value = false;
        },
    });
}

function gotoInvoice() {
    router.visit(`/invoices/new?customer_id=${props.customer.id}`);
}

/* ─── Enable product slide-over ─── */
const showEnableProduct = ref(false);
const enableForm = useForm({
    product_id: null,
    plan_id: null,
    billing_interval: 'monthly',
    billing_entity_id: null,
    plan: '',
    price_monthly: null,
    status: 'active',
    trial_ends_at: '',
});

function openEnableProduct() {
    enableForm.reset();
    enableForm.clearErrors();
    enableForm.billing_entity_id = props.billing_entities[0]?.id ?? null;
    enableForm.billing_interval = 'monthly';
    showEnableProduct.value = true;
}

function selectedAvailableProduct() {
    return props.available_products.find((p) => p.id === enableForm.product_id) ?? null;
}

function selectProduct(productId) {
    enableForm.product_id = productId;
    enableForm.plan_id = null;
    enableForm.plan = '';
    enableForm.price_monthly = null;
    enableForm.billing_interval = 'monthly';
}

function selectPlan(plan) {
    enableForm.plan_id = plan.id;
    enableForm.plan = plan.name;
    enableForm.price_monthly = plan.price_monthly;
    enableForm.billing_interval = 'monthly';
}

function setEnableInterval(interval) {
    const sel = selectedAvailableProduct();
    const plan = sel?.plans?.find((p) => p.id === enableForm.plan_id);
    enableForm.billing_interval = interval;
    if (plan) {
        enableForm.price_monthly = interval === 'annual' && plan.price_annual !== null
            ? plan.price_annual
            : plan.price_monthly;
    }
}

function submitEnableProduct() {
    enableForm.post(`/customers/${props.customer.id}/products`, {
        preserveScroll: true,
        onSuccess: () => {
            showEnableProduct.value = false;
            enableForm.reset();
        },
    });
}

/* ─── Suspend product confirm modal ─── */
const showSuspendModal = ref(false);
const suspendTarget = ref(null);
const suspendProcessing = ref(false);

function askSuspend(p) {
    suspendTarget.value = p;
    showSuspendModal.value = true;
}

function handleSuspend() {
    if (! suspendTarget.value) return;
    suspendProcessing.value = true;
    router.post(`/customers/${props.customer.id}/products/${suspendTarget.value.id}/suspend`, {}, {
        preserveScroll: true,
        onFinish: () => {
            suspendProcessing.value = false;
            showSuspendModal.value = false;
            suspendTarget.value = null;
        },
    });
}

const suspendMessage = computed(() => {
    if (! suspendTarget.value) return '';
    return `This will suspend ${suspendTarget.value.name} for ${props.customer.name}. Their access will be removed immediately.`;
});

/* ─── Task completion (optimistic dim before refresh) ─── */
const completingTaskId = ref(null);

function completeTask(taskId) {
    if (completingTaskId.value === taskId) return;
    completingTaskId.value = taskId;
    router.post(`/tasks/${taskId}/complete`, {}, {
        preserveScroll: true,
        onFinish: () => {
            completingTaskId.value = null;
        },
    });
}

function copyText(value) {
    if (!value) return;
    if (navigator?.clipboard?.writeText) {
        navigator.clipboard.writeText(value).catch(() => {});
    }
}

/* ─── Top-level "header active pill" ─── */
const headerStatusBadge = computed(() => {
    if (props.customer.archived_at) return { class: 'badge-inactive', label: 'Archived' };
    if (props.customer.pipeline_stage === 'active') return { class: 'badge-active', label: 'Active' };
    if (props.customer.pipeline_stage === 'prospect') return { class: 'badge-info', label: 'Prospect' };
    if (props.customer.pipeline_stage === 'churned') return { class: 'badge-overdue', label: 'Churned' };
    return { class: 'badge-inactive', label: 'Lead' };
});
</script>

<template>
    <Head :title="customer.name" />

    <InternalLayout :title="customer.name" :breadcrumbs="breadcrumbs" active-nav="customers">
        <template #topbar-actions>
            <button class="btn btn-secondary" type="button" @click="openEdit">
                <IconPencil :size="15" stroke-width="1.75" />
                Edit customer
            </button>
            <button class="btn btn-primary" type="button" @click="gotoInvoice">
                <IconReceipt :size="15" stroke-width="1.75" />
                New invoice
            </button>
        </template>

        <div class="cust-detail">
            <!-- ─── Customer header card ─── -->
            <div class="cust-header" style="margin: -24px -24px 0; border-radius: 0;">
                <div class="ch-left">
                    <div class="ch-avatar">{{ customerInitials(customer.name) }}</div>
                    <div>
                        <div class="ch-name">{{ customer.name }}</div>
                        <div class="ch-type">
                            {{ TYPE_LABELS[customer.type] || customer.type }}<span v-if="locationLine()"> · {{ locationLine() }}</span>
                        </div>
                        <div class="ch-badges">
                            <span
                                v-for="p in customer.products.filter((x) => x.status === 'active')"
                                :key="p.id"
                                class="badge badge-active"
                            >{{ p.name }}<span v-if="p.plan"> {{ p.plan }}</span></span>
                            <span class="badge" :class="headerStatusBadge.class">{{ headerStatusBadge.label }}</span>
                            <span v-if="customer.referrer" class="badge badge-neutral-icon">
                                <IconUsersGroup :size="13" stroke-width="1.75" />
                                Referred by {{ customer.referrer.name }}
                            </span>
                        </div>
                    </div>
                </div>
                <div class="ch-right">
                    <div class="ch-stat">
                        <div class="val">{{ formatGBP(customer.total_spend) }}</div>
                        <div class="lbl">Total spend</div>
                        <div class="sub">since joining</div>
                    </div>
                    <div class="ch-stat">
                        <div class="val">
                            {{ formatGBP(customer.mrr) }}<span style="font: 600 14px/1 'Inter'; color: var(--text-secondary); margin-left: 2px;">/mo</span>
                        </div>
                        <div class="lbl">Monthly value</div>
                        <div class="sub">MRR</div>
                    </div>
                    <div class="ch-stat">
                        <div class="val">{{ customer.open_invoices }}</div>
                        <div class="lbl">Open invoices</div>
                        <div class="sub">{{ customer.open_invoices === 0 ? 'all paid' : 'awaiting' }}</div>
                    </div>
                    <div class="ch-stat">
                        <div class="val">{{ customer.open_tickets }}</div>
                        <div class="lbl">Support tickets</div>
                        <div class="sub">open</div>
                    </div>
                </div>
            </div>

            <!-- ─── Tab bar ─── -->
            <nav class="tabs" style="margin: 0 -24px;">
                <button
                    v-for="t in tabs"
                    :key="t.key"
                    type="button"
                    class="tab"
                    :class="{ active: activeTab === t.key }"
                    @click="activeTab = t.key"
                >
                    {{ t.label }}
                    <span v-if="t.count != null" class="count">{{ t.count }}</span>
                </button>
            </nav>

            <!-- ═══ Flash success banner (post-redirect) ═══ -->
            <div
                v-if="$page.props.flash?.success"
                style="margin: 16px -24px 0; padding: 10px 14px; background: var(--success-bg); color: #047857; border-bottom: 1px solid #A7F3D0; font: 500 13px/1 'Inter', sans-serif;"
            >
                <IconCheck :size="16" stroke-width="2" style="vertical-align: middle; margin-right: 6px;" />
                {{ $page.props.flash.success }}
            </div>

            <!-- ═══ OVERVIEW TAB ═══ -->
            <div v-if="activeTab === 'overview'" class="cust-detail-content" style="margin: 0 -24px -24px;">
                <!-- LEFT COLUMN -->
                <div class="col">
                    <!-- Account details -->
                    <section class="card">
                        <header class="card-header">
                            <div class="h-icon"><IconBuilding :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Account details</h3>
                                <div class="sub">Customer record</div>
                            </div>
                            <div class="right">
                                <button type="button" class="ghost-link" @click="openEdit">
                                    Edit <IconArrowRight :size="14" stroke-width="1.75" />
                                </button>
                            </div>
                        </header>
                        <div class="acc-grid">
                            <div class="acc-cell">
                                <div class="acc-label">Company name</div>
                                <div class="acc-value">{{ customer.name }}</div>
                            </div>
                            <div class="acc-cell">
                                <div class="acc-label">Company no.</div>
                                <div class="acc-value" style="font-family: 'JetBrains Mono', monospace; font-size: 13px;">
                                    {{ customer.company_number || '—' }}
                                </div>
                            </div>
                            <div class="acc-cell">
                                <div class="acc-label">VAT number</div>
                                <div class="acc-value" style="font-family: 'JetBrains Mono', monospace; font-size: 13px;">
                                    {{ customer.vat_number || '—' }}
                                </div>
                            </div>
                            <div class="acc-cell">
                                <div class="acc-label">Industry</div>
                                <div class="acc-value">{{ TYPE_LABELS[customer.type] }}</div>
                            </div>
                            <div class="acc-cell">
                                <div class="acc-label">Primary address</div>
                                <div class="acc-value">
                                    {{ customer.address_line1 || '—' }}<template v-if="customer.address_line2"><br>{{ customer.address_line2 }}</template>
                                    <br>{{ customer.city }} {{ customer.postcode }}
                                </div>
                            </div>
                            <div class="acc-cell">
                                <div class="acc-label">Billing address</div>
                                <div class="acc-value muted">
                                    {{ customer.billing_address ? 'Custom' : 'Same as primary' }}
                                </div>
                            </div>
                            <div class="acc-cell">
                                <div class="acc-label">Account owner</div>
                                <div class="acc-value" style="display: flex; align-items: center; gap: 8px;">
                                    <template v-if="customer.assigned_user">
                                        <span class="avatar" :class="avatarClassForUser(customer.assigned_user.role)" style="width: 22px; height: 22px; font-size: 9px;">{{ userInitials(customer.assigned_user.name) }}</span>
                                        {{ customer.assigned_user.name }} <span style="font-weight: 400; color: var(--text-secondary);">· {{ ROLE_LABELS[customer.assigned_user.role] || customer.assigned_user.role }}</span>
                                    </template>
                                    <span v-else class="muted">Unassigned</span>
                                </div>
                            </div>
                            <div class="acc-cell">
                                <div class="acc-label">Pipeline stage</div>
                                <div class="acc-value" style="display: flex; flex-direction: column; gap: 0;">
                                    <span><span class="badge" :class="headerStatusBadge.class">{{ PIPELINE_LABELS[customer.pipeline_stage] }}</span></span>
                                    <div class="pipeline">
                                        <span class="stg" :class="pipelineSubclass(customer.pipeline_stage, 'lead')"><span class="dot" />Lead</span>
                                        <span class="line" />
                                        <span class="stg" :class="pipelineSubclass(customer.pipeline_stage, 'prospect')"><span class="dot" />Prospect</span>
                                        <span class="line" />
                                        <span class="stg" :class="pipelineSubclass(customer.pipeline_stage, 'active')"><span class="dot" />Active</span>
                                        <span class="line" />
                                        <span class="stg" :class="pipelineSubclass(customer.pipeline_stage, 'churned')"><span class="dot" />Churned</span>
                                    </div>
                                </div>
                            </div>
                            <div class="acc-cell acc-row-last">
                                <div class="acc-label">Customer since</div>
                                <div class="acc-value">
                                    {{ formatDate(customer.created_at) }}
                                    <span style="font-weight: 400; color: var(--text-tertiary);">· {{ timeAgo(customer.created_at) }}</span>
                                </div>
                            </div>
                            <div class="acc-cell acc-row-last">
                                <div class="acc-label">Referred by</div>
                                <div class="acc-value" style="display: flex; align-items: center; gap: 8px;">
                                    <template v-if="customer.referrer">
                                        <span class="avatar av-amber" style="width: 22px; height: 22px; font-size: 9px;">{{ userInitials(customer.referrer.name) }}</span>
                                        <span class="acc-link">{{ customer.referrer.name }}</span>
                                    </template>
                                    <span v-else class="muted">Direct</span>
                                </div>
                            </div>
                            <div class="acc-cell acc-row-last" style="grid-column: 1 / -1; border-right: 0;">
                                <div class="acc-label">Group account</div>
                                <div class="acc-value muted" style="display: flex; align-items: center; gap: 12px;">
                                    <template v-if="customer.group">
                                        {{ customer.group.name }} <span>· {{ customer.group.member_count }} member{{ customer.group.member_count === 1 ? '' : 's' }}</span>
                                    </template>
                                    <template v-else>
                                        None assigned
                                        <a href="#" class="acc-link" style="font-size: 13px;" @click.prevent>Add to group<IconArrowRight :size="14" stroke-width="1.75" /></a>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Active products -->
                    <section class="card">
                        <header class="card-header">
                            <div class="h-icon gold"><IconLayoutGrid :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Products</h3>
                                <div class="sub">{{ customer.products.length }} on file · {{ formatGBP(customer.mrr) }} MRR</div>
                            </div>
                            <div class="right">
                                <button type="button" class="btn btn-ghost btn-sm" @click="openEnableProduct">
                                    <IconPlus :size="14" stroke-width="1.75" />
                                    Enable product
                                </button>
                            </div>
                        </header>
                        <div v-if="customer.products.length">
                            <div v-for="p in customer.products" :key="p.id" class="prod-row">
                                <div class="prod-logo" :class="pbClassForSlug(p.slug)">{{ p.name?.[0] || '?' }}</div>
                                <div class="prod-meta">
                                    <div class="pname">{{ p.name }}<span class="role">· {{ p.plan || 'No plan' }}</span></div>
                                    <div class="pdesc">
                                        <template v-if="p.price_monthly">{{ formatGBP(p.price_monthly) }}/mo</template>
                                        <template v-else>Pre-revenue</template>
                                        <template v-if="p.billing_entity"> · {{ p.billing_entity.name }}</template>
                                    </div>
                                </div>
                                <div class="prod-actions">
                                    <span class="badge" :class="{ 'badge-active': p.status === 'active', 'badge-trial': p.status === 'trial', 'badge-inactive': ['suspended', 'cancelled'].includes(p.status) }">
                                        {{ p.status }}
                                    </span>
                                    <Menu v-if="['active', 'trial'].includes(p.status)" as="div" class="dd-menu">
                                        <MenuButton class="icon-btn" aria-label="Product actions">
                                            <IconDots :size="16" stroke-width="1.75" />
                                        </MenuButton>
                                        <MenuItems class="dd-popover right-align">
                                            <MenuItem v-slot="{ active }">
                                                <button type="button" :class="['dd-option', { active }]" disabled style="opacity: .55; cursor: not-allowed;">
                                                    Open admin
                                                </button>
                                            </MenuItem>
                                            <div style="height: 1px; background: var(--border-soft); margin: 4px 0;" />
                                            <MenuItem v-slot="{ active }">
                                                <button type="button" :class="['dd-option', { active }]" style="color: var(--warning);" @click="askSuspend(p)">
                                                    Suspend product
                                                </button>
                                            </MenuItem>
                                        </MenuItems>
                                    </Menu>
                                </div>
                            </div>
                            <div class="sso-line">
                                <IconLink :size="14" stroke-width="1.75" />
                                SSO access: <span style="color: var(--text-secondary); font-weight: 500;">account.whitedash.co.uk</span> → <span class="ok">active</span>
                            </div>
                        </div>
                        <div v-else class="tab-empty" style="padding: 32px 18px;">
                            <p>No products enabled yet.</p>
                            <button type="button" class="btn btn-primary btn-sm" style="margin-top: 12px;" @click="openEnableProduct">
                                <IconPlus :size="14" stroke-width="1.75" />
                                Enable product
                            </button>
                        </div>
                    </section>

                    <!-- Recent invoices -->
                    <section class="card">
                        <header class="card-header">
                            <div class="h-icon"><IconReceipt :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Invoices</h3>
                                <div class="sub">{{ customer.invoices.length }} recent</div>
                            </div>
                            <div class="right">
                                <button type="button" class="ghost-link" @click="activeTab = 'invoices'">View all<IconArrowRight :size="14" stroke-width="1.75" /></button>
                            </div>
                        </header>
                        <div v-if="customer.invoices.length">
                            <div v-for="inv in customer.invoices.slice(0, 3)" :key="inv.id" class="inv-row">
                                <div class="inv-ic" :class="invIcClass(inv.status)">
                                    <IconReceipt :size="16" stroke-width="1.75" />
                                </div>
                                <div class="inv-meta">
                                    <div class="num">
                                        {{ inv.number }}
                                        <span v-if="inv.status === 'draft'" class="draft">— DRAFT</span>
                                    </div>
                                    <div class="sub">{{ inv.billing_entity?.name || '—' }}<span v-if="inv.type"> · {{ inv.type }}</span></div>
                                </div>
                                <div class="inv-right">
                                    <div class="inv-amt">{{ formatGBP(inv.total) }}</div>
                                    <span class="badge" :class="invBadgeClass(inv.status)">{{ inv.status }}</span>
                                    <button v-if="inv.status === 'draft'" type="button" class="btn btn-primary btn-sm">
                                        <IconSend :size="14" stroke-width="1.75" />
                                        Send
                                    </button>
                                    <a v-else href="#" class="ghost-link" @click.prevent>
                                        <IconDownload :size="14" stroke-width="1.75" />
                                        Download
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div v-else class="tab-empty" style="padding: 32px 18px;">
                            <p>No invoices yet.</p>
                        </div>
                    </section>

                    <!-- Notes -->
                    <section class="card">
                        <header class="card-header">
                            <div class="h-icon"><IconNotes :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Notes</h3>
                                <div class="sub">{{ customer.notes.length }} total<span v-if="customer.notes[0]"> · most recent {{ timeAgo(customer.notes[0].created_at) }}</span></div>
                            </div>
                            <div class="right">
                                <span class="h-badge gold">{{ customer.notes.length }}</span>
                                <button type="button" class="btn btn-ghost btn-sm" @click="showAddNote = !showAddNote">
                                    <IconPlus :size="14" stroke-width="1.75" />
                                    Add note
                                </button>
                            </div>
                        </header>
                        <div class="note-pills">
                            <button type="button" class="note-pill" :class="{ active: noteFilter === 'all' }" @click="noteFilter = 'all'">All</button>
                            <button v-for="t in note_types" :key="t" type="button" class="note-pill" :class="{ active: noteFilter === t }" @click="noteFilter = t">
                                {{ NOTE_TYPE_LABELS[t] }}
                            </button>
                        </div>
                        <div v-if="showAddNote" style="padding: 16px 18px; border-bottom: 1px solid var(--border-soft); background: #FBFCFE;">
                            <form class="form-section" @submit.prevent="submitNote">
                                <div class="form-row">
                                    <div class="form-field">
                                        <label>Type</label>
                                        <select v-model="noteForm.type">
                                            <option v-for="t in note_types" :key="t" :value="t">{{ NOTE_TYPE_LABELS[t] }}</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-field">
                                    <label>Note<span class="req">*</span></label>
                                    <textarea v-model="noteForm.body" rows="3" :class="{ 'has-err': noteForm.errors.body }" required />
                                    <div v-if="noteForm.errors.body" class="err">{{ noteForm.errors.body }}</div>
                                </div>
                                <div style="display: flex; justify-content: flex-end; gap: 8px;">
                                    <button type="button" class="btn btn-secondary btn-sm" @click="showAddNote = false">Cancel</button>
                                    <button type="submit" class="btn btn-primary btn-sm" :disabled="noteForm.processing">
                                        {{ noteForm.processing ? 'Saving…' : 'Save note' }}
                                    </button>
                                </div>
                            </form>
                        </div>
                        <div v-if="filteredNotes.length">
                            <div v-for="n in filteredNotes.slice(0, 3)" :key="n.id" class="note-row" :class="noteRowClass(n.type)">
                                <div class="note-head">
                                    <span class="avatar" :class="avatarClassForUser(n.creator?.role)">{{ userInitials(n.creator?.name) }}</span>
                                    <span class="who">{{ n.creator?.name || 'Unknown' }}</span>
                                    <span class="sep">·</span>
                                    <span class="meta">{{ NOTE_TYPE_LABELS[n.type] }} · {{ timeAgo(n.created_at) }}</span>
                                </div>
                                <div class="note-body">{{ n.body }}</div>
                            </div>
                        </div>
                        <div v-else class="tab-empty" style="padding: 32px 18px;">
                            <p>No notes match this filter.</p>
                        </div>
                        <div v-if="customer.notes.length > 3" class="note-foot">
                            <button type="button" class="ghost-link" @click="activeTab = 'notes'">
                                Show all {{ customer.notes.length }} notes
                                <IconArrowRight :size="14" stroke-width="1.75" />
                            </button>
                        </div>
                    </section>
                </div>

                <!-- RIGHT COLUMN -->
                <div class="col">
                    <!-- Contacts -->
                    <section class="card">
                        <header class="card-header">
                            <div class="h-icon"><IconAddressBook :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Contacts</h3>
                                <div class="sub">{{ customer.contacts.length }} on file</div>
                            </div>
                            <div class="right">
                                <a href="#" class="ghost-link" @click.prevent>
                                    <IconPlus :size="14" stroke-width="1.75" />
                                    Add contact
                                </a>
                            </div>
                        </header>
                        <div v-if="customer.contacts.length">
                            <div v-for="c in customer.contacts" :key="c.id" class="contact-row">
                                <span v-if="c.is_primary" class="contact-primary-pill">Primary</span>
                                <div class="contact-top">
                                    <div class="avatar av-navy">{{ userInitials(c.name) }}</div>
                                    <div>
                                        <div class="contact-name">{{ c.name }}</div>
                                        <div class="contact-role">
                                            {{ ({ owner: 'Owner', manager: 'Manager', accounts: 'Accounts', other: 'Other' })[c.role] }}
                                            <template v-if="c.is_primary"> · Primary contact</template>
                                        </div>
                                    </div>
                                </div>
                                <div class="contact-fields">
                                    <div class="contact-field">
                                        <IconMail :size="16" stroke-width="1.75" />
                                        <a :href="`mailto:${c.email}`" style="color: inherit; text-decoration: none;">{{ c.email }}</a>
                                        <button type="button" class="copy" :aria-label="`Copy ${c.email}`" @click="copyText(c.email)">
                                            <IconCopy :size="14" stroke-width="1.75" />
                                        </button>
                                    </div>
                                    <div v-if="c.phone" class="contact-field">
                                        <IconPhone :size="16" stroke-width="1.75" />
                                        <a :href="`tel:${c.phone}`" style="color: inherit; text-decoration: none;">{{ c.phone }}</a>
                                        <button type="button" class="copy" :aria-label="`Copy ${c.phone}`" @click="copyText(c.phone)">
                                            <IconCopy :size="14" stroke-width="1.75" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div v-else class="tab-empty" style="padding: 32px 18px;">
                            <p>No contacts on file.</p>
                        </div>
                    </section>

                    <!-- Tasks -->
                    <section class="card">
                        <header class="card-header">
                            <div class="h-icon"><IconCheckbox :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Tasks</h3>
                                <div class="sub">Tied to this customer</div>
                            </div>
                            <div class="right">
                                <span class="h-badge amber">{{ customer.tasks.length }} open</span>
                                <button type="button" class="ghost-link" @click="showAddTask = !showAddTask">
                                    <IconPlus :size="14" stroke-width="1.75" />
                                    Add
                                </button>
                            </div>
                        </header>
                        <div v-if="showAddTask" style="padding: 14px 18px; border-bottom: 1px solid var(--border-soft); background: #FBFCFE;">
                            <form class="form-section" @submit.prevent="submitTask">
                                <div class="form-field">
                                    <label>Title<span class="req">*</span></label>
                                    <input v-model="taskForm.title" type="text" :class="{ 'has-err': taskForm.errors.title }" required>
                                </div>
                                <div class="form-field">
                                    <label>Due date</label>
                                    <input v-model="taskForm.due_date" type="date">
                                </div>
                                <div style="display: flex; justify-content: flex-end; gap: 8px;">
                                    <button type="button" class="btn btn-secondary btn-sm" @click="showAddTask = false">Cancel</button>
                                    <button type="submit" class="btn btn-primary btn-sm" :disabled="taskForm.processing">
                                        {{ taskForm.processing ? 'Saving…' : 'Add task' }}
                                    </button>
                                </div>
                            </form>
                        </div>
                        <div v-if="customer.tasks.length">
                            <div
                                v-for="t in customer.tasks"
                                :key="t.id"
                                class="task-row"
                                :class="{ completing: completingTaskId === t.id }"
                            >
                                <button
                                    type="button"
                                    class="cb"
                                    :aria-label="`Complete task: ${t.title}`"
                                    :disabled="completingTaskId === t.id"
                                    @click="completeTask(t.id)"
                                />
                                <div>
                                    <div class="task-text">{{ t.title }}</div>
                                </div>
                                <div class="due" :class="dueLabel(t.due_date).class">{{ dueLabel(t.due_date).label }}</div>
                            </div>
                        </div>
                        <div v-else class="tab-empty" style="padding: 28px 18px;">
                            <p>No open tasks.</p>
                        </div>
                    </section>

                    <!-- Referral -->
                    <section v-if="customer.referrer" class="card">
                        <header class="card-header">
                            <div class="h-icon"><IconUsersGroup :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Referral</h3>
                                <div class="sub">Commission tracking</div>
                            </div>
                        </header>
                        <div class="ref-block">
                            <div class="avatar av-amber">{{ userInitials(customer.referrer.name) }}</div>
                            <div>
                                <div class="ref-name">{{ customer.referrer.name }}</div>
                                <div class="ref-sub">Referred {{ formatDate(customer.referrer.attributed_at) }}</div>
                            </div>
                        </div>
                        <div class="meta-pair">
                            <div class="k">Commission model<span class="sub">{{ customer.products[0]?.name || 'No active product' }} hybrid</span></div>
                        </div>
                        <div class="note-foot">
                            <Link href="/referrers" class="ghost-link">View referrer<IconArrowRight :size="14" stroke-width="1.75" /></Link>
                        </div>
                    </section>

                    <!-- Domains -->
                    <section class="card">
                        <header class="card-header">
                            <div class="h-icon"><IconWorld :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Domains</h3>
                                <div class="sub">{{ customer.domains.length }} domain{{ customer.domains.length === 1 ? '' : 's' }}</div>
                            </div>
                            <div class="right">
                                <Link href="/domains" class="ghost-link">Manage DNS<IconArrowRight :size="14" stroke-width="1.75" /></Link>
                            </div>
                        </header>
                        <div v-if="customer.domains.length">
                            <div v-for="d in customer.domains" :key="d.id" class="dom-row">
                                <IconWorld class="world" :size="18" stroke-width="1.75" />
                                <div>
                                    <div class="dom-name">{{ d.domain }}</div>
                                    <div class="dom-sub">
                                        <template v-if="d.is_in_cloudflare">Cloudflare</template>
                                        <template v-else>External</template>
                                        <template v-if="d.expiry_date"> · expires {{ formatDate(d.expiry_date) }}</template>
                                    </div>
                                </div>
                                <div class="dom-tags">
                                    <span v-if="d.ssl_expiry_date" class="tiny-badge ssl">
                                        <IconCheck :size="11" stroke-width="2" />
                                        SSL
                                    </span>
                                    <span class="tiny-badge" :class="domainTagClass(d.status)">{{ d.status }}</span>
                                </div>
                            </div>
                        </div>
                        <div v-else class="tab-empty" style="padding: 28px 18px;">
                            <p>No domains tracked.</p>
                        </div>
                        <div class="add-line">
                            <a href="#" class="ghost-link" @click.prevent><IconPlus :size="14" stroke-width="1.75" />Add domain</a>
                        </div>
                    </section>

                    <!-- Archive button (deliberately bottom-right of the right col) -->
                    <div style="display: flex; justify-content: flex-end; padding-top: 4px;">
                        <button type="button" class="ghost-link" style="color: var(--danger);" @click="archive">
                            <IconArchive :size="14" stroke-width="1.75" />
                            Archive customer
                        </button>
                    </div>
                </div>
            </div>

            <!-- ═══ INVOICES TAB ═══ -->
            <div v-else-if="activeTab === 'invoices'" style="margin: 0 -24px -24px; padding: 24px;">
                <section class="card">
                    <header class="card-header">
                        <div class="h-icon"><IconReceipt :size="16" stroke-width="1.75" /></div>
                        <div>
                            <h3>All invoices</h3>
                            <div class="sub">{{ customer.invoices.length }} on file</div>
                        </div>
                        <div class="right">
                            <button type="button" class="btn btn-primary" @click="gotoInvoice">
                                <IconPlus :size="15" stroke-width="1.75" />
                                New invoice
                            </button>
                        </div>
                    </header>
                    <div v-if="customer.invoices.length">
                        <div v-for="inv in customer.invoices" :key="inv.id" class="inv-row">
                            <div class="inv-ic" :class="invIcClass(inv.status)">
                                <IconReceipt :size="16" stroke-width="1.75" />
                            </div>
                            <div class="inv-meta">
                                <div class="num">
                                    {{ inv.number }}
                                    <span v-if="inv.status === 'draft'" class="draft">— DRAFT</span>
                                </div>
                                <div class="sub">{{ inv.billing_entity?.name || '—' }}<span v-if="inv.issue_date"> · {{ formatDate(inv.issue_date) }}</span></div>
                            </div>
                            <div class="inv-right">
                                <div class="inv-amt">{{ formatGBP(inv.total) }}</div>
                                <span class="badge" :class="invBadgeClass(inv.status)">{{ inv.status }}</span>
                            </div>
                        </div>
                    </div>
                    <div v-else class="tab-empty">
                        <h3>No invoices yet</h3>
                        <p>Create the first one for this customer.</p>
                    </div>
                </section>
            </div>

            <!-- ═══ PRODUCTS TAB ═══ -->
            <div v-else-if="activeTab === 'products'" style="margin: 0 -24px -24px; padding: 24px;">
                <section class="card">
                    <header class="card-header">
                        <div class="h-icon gold"><IconLayoutGrid :size="16" stroke-width="1.75" /></div>
                        <div>
                            <h3>Products</h3>
                            <div class="sub">All product subscriptions for this customer</div>
                        </div>
                        <div class="right">
                            <button type="button" class="btn btn-primary btn-sm" @click="openEnableProduct">
                                <IconPlus :size="14" stroke-width="1.75" />
                                Enable product
                            </button>
                        </div>
                    </header>
                    <div v-if="customer.products.length">
                        <div v-for="p in customer.products" :key="p.id" class="prod-row">
                            <div class="prod-logo" :class="pbClassForSlug(p.slug)">{{ p.name?.[0] || '?' }}</div>
                            <div class="prod-meta">
                                <div class="pname">{{ p.name }}<span class="role">· {{ p.plan || 'No plan' }}</span></div>
                                <div class="pdesc">
                                    <template v-if="p.price_monthly">{{ formatGBP(p.price_monthly) }}/mo</template>
                                    <template v-else>—</template>
                                    <template v-if="p.status === 'trial' && p.trial_ends_at"> · trial ends {{ formatDate(p.trial_ends_at) }}</template>
                                </div>
                            </div>
                            <div class="prod-actions">
                                <span class="badge" :class="{ 'badge-active': p.status === 'active', 'badge-trial': p.status === 'trial', 'badge-inactive': ['suspended', 'cancelled'].includes(p.status) }">{{ p.status }}</span>
                                <Menu v-if="['active', 'trial'].includes(p.status)" as="div" class="dd-menu">
                                    <MenuButton class="icon-btn" aria-label="Product actions">
                                        <IconDots :size="16" stroke-width="1.75" />
                                    </MenuButton>
                                    <MenuItems class="dd-popover right-align">
                                        <MenuItem v-slot="{ active }">
                                            <button type="button" :class="['dd-option', { active }]" style="color: var(--warning);" @click="askSuspend(p)">
                                                Suspend product
                                            </button>
                                        </MenuItem>
                                    </MenuItems>
                                </Menu>
                            </div>
                        </div>
                    </div>
                    <div v-else class="tab-empty">
                        <h3>No products yet</h3>
                        <p>Enable a product to start tracking subscriptions for this customer.</p>
                        <button type="button" class="btn btn-primary btn-sm" style="margin-top: 12px;" @click="openEnableProduct">
                            <IconPlus :size="14" stroke-width="1.75" />
                            Enable product
                        </button>
                    </div>
                </section>
            </div>

            <!-- ═══ CONTRACTS TAB ═══ -->
            <div v-else-if="activeTab === 'contracts'" style="margin: 0 -24px -24px; padding: 24px;">
                <section class="card">
                    <header class="card-header">
                        <div class="h-icon"><IconReceipt2 :size="16" stroke-width="1.75" /></div>
                        <div>
                            <h3>Contracts</h3>
                            <div class="sub">{{ customer.contracts_count }} on file</div>
                        </div>
                    </header>
                    <div class="tab-empty">
                        <h3>No contracts yet</h3>
                        <p>Contracts are managed in the next phase.</p>
                        <a href="#" class="ghost-link" @click.prevent>New contract<IconArrowRight :size="14" stroke-width="1.75" /></a>
                    </div>
                </section>
            </div>

            <!-- ═══ SUPPORT TAB ═══ -->
            <div v-else-if="activeTab === 'support'" style="margin: 0 -24px -24px; padding: 24px;">
                <section class="card">
                    <header class="card-header">
                        <div class="h-icon"><IconUsersGroup :size="16" stroke-width="1.75" /></div>
                        <div>
                            <h3>Support tickets</h3>
                            <div class="sub">{{ customer.open_tickets }} open</div>
                        </div>
                    </header>
                    <div class="tab-empty">
                        <template v-if="customer.open_tickets > 0">
                            <h3>{{ customer.open_tickets }} open ticket{{ customer.open_tickets === 1 ? '' : 's' }}</h3>
                            <Link :href="`/support?customer_id=${customer.id}`" class="ghost-link">View in Support<IconArrowRight :size="14" stroke-width="1.75" /></Link>
                        </template>
                        <template v-else>
                            <h3>No open tickets</h3>
                            <p>This customer is all clear.</p>
                        </template>
                    </div>
                </section>
            </div>

            <!-- ═══ ACTIVITY TAB ═══ -->
            <div v-else-if="activeTab === 'activity'" style="margin: 0 -24px -24px; padding: 24px;">
                <section class="card">
                    <header class="card-header">
                        <div class="h-icon"><IconActivity :size="16" stroke-width="1.75" /></div>
                        <div>
                            <h3>Activity</h3>
                            <div class="sub">Audit log for this customer</div>
                        </div>
                    </header>
                    <div v-if="customer.activity.length">
                        <div v-for="a in customer.activity" :key="a.id" class="act-row">
                            <div class="act-ic" :class="activityIconClass(a.action)">
                                <IconCheck v-if="a.action === 'customer.created'" :size="16" stroke-width="1.75" />
                                <IconNotes v-else-if="a.action === 'customer.note_added'" :size="16" stroke-width="1.75" />
                                <IconCheckbox v-else-if="a.action === 'customer.task_added'" :size="16" stroke-width="1.75" />
                                <IconArchive v-else-if="a.action === 'customer.archived'" :size="16" stroke-width="1.75" />
                                <IconPencil v-else :size="16" stroke-width="1.75" />
                            </div>
                            <div class="act-text">
                                <span class="em">{{ activityLabel(a.action) }}</span>
                                <span v-if="a.after?.name" class="muted"> · {{ a.after.name }}</span>
                                <span v-else-if="a.after?.type" class="muted"> · {{ a.after.type }}</span>
                                <span v-else-if="a.after?.title" class="muted"> · {{ a.after.title }}</span>
                            </div>
                            <div class="act-time">{{ timeAgo(a.created_at) }}</div>
                        </div>
                    </div>
                    <div v-else class="tab-empty">
                        <h3>No activity yet</h3>
                        <p>Edits, notes, and tasks for this customer will appear here.</p>
                    </div>
                </section>
            </div>

            <!-- ═══ NOTES TAB ═══ -->
            <div v-else-if="activeTab === 'notes'" style="margin: 0 -24px -24px; padding: 24px;">
                <section class="card">
                    <header class="card-header">
                        <div class="h-icon"><IconNotes :size="16" stroke-width="1.75" /></div>
                        <div>
                            <h3>All notes</h3>
                            <div class="sub">{{ customer.notes.length }} note{{ customer.notes.length === 1 ? '' : 's' }}</div>
                        </div>
                        <div class="right">
                            <button type="button" class="btn btn-ghost btn-sm" @click="showAddNote = !showAddNote">
                                <IconPlus :size="14" stroke-width="1.75" />
                                Add note
                            </button>
                        </div>
                    </header>
                    <div v-if="showAddNote" style="padding: 16px 18px; border-bottom: 1px solid var(--border-soft); background: #FBFCFE;">
                        <form class="form-section" @submit.prevent="submitNote">
                            <div class="form-row">
                                <div class="form-field">
                                    <label>Type</label>
                                    <select v-model="noteForm.type">
                                        <option v-for="t in note_types" :key="t" :value="t">{{ NOTE_TYPE_LABELS[t] }}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-field">
                                <label>Note<span class="req">*</span></label>
                                <textarea v-model="noteForm.body" rows="3" :class="{ 'has-err': noteForm.errors.body }" required />
                                <div v-if="noteForm.errors.body" class="err">{{ noteForm.errors.body }}</div>
                            </div>
                            <div style="display: flex; justify-content: flex-end; gap: 8px;">
                                <button type="button" class="btn btn-secondary btn-sm" @click="showAddNote = false">Cancel</button>
                                <button type="submit" class="btn btn-primary btn-sm" :disabled="noteForm.processing">{{ noteForm.processing ? 'Saving…' : 'Save note' }}</button>
                            </div>
                        </form>
                    </div>
                    <div class="note-pills">
                        <button type="button" class="note-pill" :class="{ active: noteFilter === 'all' }" @click="noteFilter = 'all'">All</button>
                        <button v-for="t in note_types" :key="t" type="button" class="note-pill" :class="{ active: noteFilter === t }" @click="noteFilter = t">
                            {{ NOTE_TYPE_LABELS[t] }}
                        </button>
                    </div>
                    <div v-if="filteredNotes.length">
                        <div v-for="n in filteredNotes" :key="n.id" class="note-row" :class="noteRowClass(n.type)">
                            <div class="note-head">
                                <span class="avatar" :class="avatarClassForUser(n.creator?.role)">{{ userInitials(n.creator?.name) }}</span>
                                <span class="who">{{ n.creator?.name || 'Unknown' }}</span>
                                <span class="sep">·</span>
                                <span class="meta">{{ NOTE_TYPE_LABELS[n.type] }} · {{ timeAgo(n.created_at) }}</span>
                            </div>
                            <div class="note-body">{{ n.body }}</div>
                        </div>
                    </div>
                    <div v-else class="tab-empty">
                        <h3>No notes match</h3>
                        <p>Try a different filter or add the first note.</p>
                    </div>
                </section>
            </div>
        </div>

        <!-- ─── Edit customer slide-over ─── -->
        <TransitionRoot as="template" :show="showEdit">
            <Dialog as="div" class="slide-over" @close="showEdit = false">
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
                        <form class="slide-over-form" @submit.prevent="submitEdit">
                            <header class="slide-over-header">
                                <h2>Edit customer</h2>
                                <button type="button" class="icon-btn" aria-label="Close" @click="showEdit = false">
                                    <IconX :size="18" stroke-width="1.75" />
                                </button>
                            </header>
                            <div class="slide-over-body">
                                <div v-if="editForm.hasErrors" style="background: var(--danger-bg); color: var(--danger); border-radius: var(--radius-md); padding: 10px 14px; display: flex; gap: 8px; align-items: center;">
                                    <IconAlertCircle :size="18" stroke-width="2" />
                                    <span>Please check the fields below.</span>
                                </div>

                                <div class="form-section">
                                    <div class="form-section-title">Company</div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Name<span class="req">*</span></label>
                                            <input v-model="editForm.name" type="text" :class="{ 'has-err': editForm.errors.name }" required>
                                            <div v-if="editForm.errors.name" class="err">{{ editForm.errors.name }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-field">
                                            <label>Trading name</label>
                                            <input v-model="editForm.trading_name" type="text">
                                        </div>
                                        <div class="form-field">
                                            <label>Type</label>
                                            <select v-model="editForm.type">
                                                <option v-for="t in types" :key="t" :value="t">{{ TYPE_LABELS[t] }}</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-field">
                                            <label>Company number</label>
                                            <input v-model="editForm.company_number" type="text">
                                        </div>
                                        <div class="form-field">
                                            <label>VAT number</label>
                                            <input v-model="editForm.vat_number" type="text">
                                        </div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <div class="form-section-title">Address</div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Line 1<span class="req">*</span></label>
                                            <input v-model="editForm.address_line1" type="text" required>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Line 2</label>
                                            <input v-model="editForm.address_line2" type="text">
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-field">
                                            <label>City<span class="req">*</span></label>
                                            <input v-model="editForm.city" type="text" required>
                                        </div>
                                        <div class="form-field">
                                            <label>Postcode<span class="req">*</span></label>
                                            <input v-model="editForm.postcode" type="text" required>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Country</label>
                                            <select v-model="editForm.country">
                                                <option value="GB">United Kingdom</option>
                                                <option value="IE">Ireland</option>
                                                <option value="GR">Greece</option>
                                                <option value="CY">Cyprus</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <div class="form-section-title">Settings</div>
                                    <div class="form-row">
                                        <div class="form-field">
                                            <label>Pipeline stage</label>
                                            <select v-model="editForm.pipeline_stage">
                                                <option v-for="s in pipeline_stages" :key="s" :value="s">{{ PIPELINE_LABELS[s] }}</option>
                                            </select>
                                        </div>
                                        <div class="form-field">
                                            <label>Account owner</label>
                                            <select v-model="editForm.assigned_to">
                                                <option value="">— Unassigned —</option>
                                                <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <footer class="slide-over-footer">
                                <button type="button" class="btn btn-secondary" @click="showEdit = false">Cancel</button>
                                <button type="submit" class="btn btn-primary" :disabled="editForm.processing">
                                    {{ editForm.processing ? 'Saving…' : 'Save changes' }}
                                </button>
                            </footer>
                        </form>
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </TransitionRoot>

        <ConfirmModal
            v-model:show="showArchiveModal"
            :title="`Archive ${customer.name}?`"
            message="This customer will be archived and hidden from active lists. Their invoices and history will be preserved."
            confirm-label="Archive customer"
            variant="warning"
            :loading="archiveProcessing"
            @confirm="handleArchive"
        />

        <!-- Enable product slide-over -->
        <TransitionRoot as="template" :show="showEnableProduct">
            <Dialog as="div" class="slide-over-dialog" @close="showEnableProduct = false">
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
                        <form class="slide-over-form" @submit.prevent="submitEnableProduct">
                            <header class="slide-over-header">
                                <h2>Enable product for {{ customer.name }}</h2>
                                <button type="button" class="icon-btn" aria-label="Close" @click="showEnableProduct = false">
                                    <IconX :size="18" stroke-width="1.75" />
                                </button>
                            </header>

                            <div class="slide-over-body">
                                <div class="form-section">
                                    <h3>Product</h3>
                                    <div v-if="! available_products.length" style="padding: 12px 14px; background: var(--neutral-bg); border-radius: var(--radius-md); color: var(--text-secondary); font: 400 13px/1.5 'Inter', sans-serif;">
                                        All products are already enabled for this customer.
                                    </div>
                                    <div v-else class="ent-grid">
                                        <button
                                            v-for="p in available_products"
                                            :key="p.id"
                                            type="button"
                                            class="ent-opt"
                                            :class="{ selected: enableForm.product_id === p.id }"
                                            @click="selectProduct(p.id)"
                                        >
                                            <div class="ent-icon" :style="{ background: p.icon_colour || '#0D9488', color: '#fff' }">
                                                {{ p.name?.[0] || '?' }}
                                            </div>
                                            <div class="ent-meta">
                                                <div class="nm">{{ p.name }}</div>
                                                <div class="slug">{{ p.slug }}</div>
                                            </div>
                                        </button>
                                    </div>
                                    <div v-if="enableForm.errors.product_id" class="err">{{ enableForm.errors.product_id }}</div>
                                </div>

                                <div v-if="enableForm.product_id" class="form-section">
                                    <h3>Plan</h3>
                                    <!-- Plan radio cards when the product has defined plans -->
                                    <template v-if="(selectedAvailableProduct()?.plans ?? []).length > 0">
                                        <div style="display: flex; flex-direction: column; gap: 8px;">
                                            <button
                                                v-for="plan in selectedAvailableProduct().plans"
                                                :key="plan.id"
                                                type="button"
                                                class="ent-opt"
                                                :class="{ selected: enableForm.plan_id === plan.id }"
                                                style="padding: 12px 14px; align-items: flex-start; flex-direction: column; gap: 4px;"
                                                @click="selectPlan(plan)"
                                            >
                                                <div style="display: flex; align-items: center; gap: 10px; width: 100%;">
                                                    <span style="font: 600 14px/1.2 'Inter', sans-serif;">{{ plan.name }}</span>
                                                    <span style="margin-left: auto; font: 600 14px/1.2 'Inter', sans-serif; color: var(--accent);">£{{ Number(plan.price_monthly).toFixed(2) }}/mo</span>
                                                </div>
                                                <span v-if="plan.price_annual" style="font: 400 11.5px/1.3 'Inter', sans-serif; color: var(--text-secondary);">
                                                    or £{{ Number(plan.price_annual).toFixed(2) }}/yr
                                                </span>
                                                <span v-if="plan.description" style="font: 400 12px/1.3 'Inter', sans-serif; color: var(--text-secondary);">{{ plan.description }}</span>
                                            </button>
                                        </div>
                                        <div v-if="enableForm.plan_id && selectedAvailableProduct().plans.find((p) => p.id === enableForm.plan_id)?.price_annual" style="margin-top: 10px;">
                                            <label class="field-label">Billing interval</label>
                                            <div class="type-toggle">
                                                <button type="button" class="type-opt" :class="{ active: enableForm.billing_interval === 'monthly' }" @click="setEnableInterval('monthly')">Monthly</button>
                                                <button type="button" class="type-opt" :class="{ active: enableForm.billing_interval === 'annual' }" @click="setEnableInterval('annual')">Annual</button>
                                            </div>
                                        </div>
                                    </template>
                                    <!-- Fallback: free-text plan + price when the product has no plans defined -->
                                    <template v-else>
                                        <div class="form-row two">
                                            <div class="form-field">
                                                <label>Plan</label>
                                                <input v-model="enableForm.plan" type="text" placeholder="e.g. Pro, Basic, Enterprise">
                                                <div v-if="enableForm.errors.plan" class="err">{{ enableForm.errors.plan }}</div>
                                            </div>
                                            <div class="form-field">
                                                <label>Monthly price (£)</label>
                                                <input v-model.number="enableForm.price_monthly" type="number" min="0" step="0.01" placeholder="29.00">
                                                <div v-if="enableForm.errors.price_monthly" class="err">{{ enableForm.errors.price_monthly }}</div>
                                            </div>
                                        </div>
                                        <div class="field-help" style="margin-top: 8px;">
                                            No plans defined for this product yet. Add plans in Settings → Products for a better experience.
                                        </div>
                                    </template>
                                </div>

                                <div v-if="enableForm.product_id && billing_entities.length" class="form-section">
                                    <h3>Billing entity</h3>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Bills under</label>
                                            <select v-model="enableForm.billing_entity_id">
                                                <option :value="null">— None —</option>
                                                <option v-for="be in billing_entities" :key="be.id" :value="be.id">{{ be.name }}</option>
                                            </select>
                                            <div v-if="enableForm.errors.billing_entity_id" class="err">{{ enableForm.errors.billing_entity_id }}</div>
                                        </div>
                                    </div>
                                </div>

                                <div v-if="enableForm.product_id" class="form-section">
                                    <h3>Status</h3>
                                    <div class="form-row two">
                                        <button
                                            type="button"
                                            class="ent-opt"
                                            :class="{ selected: enableForm.status === 'active' }"
                                            style="padding: 10px 14px;"
                                            @click="enableForm.status = 'active'"
                                        >
                                            <div class="ent-meta">
                                                <div class="nm">Active</div>
                                                <div class="slug">Billing starts now</div>
                                            </div>
                                        </button>
                                        <button
                                            type="button"
                                            class="ent-opt"
                                            :class="{ selected: enableForm.status === 'trial' }"
                                            style="padding: 10px 14px;"
                                            @click="enableForm.status = 'trial'"
                                        >
                                            <div class="ent-meta">
                                                <div class="nm">Trial</div>
                                                <div class="slug">Free until end date</div>
                                            </div>
                                        </button>
                                    </div>
                                    <div v-if="enableForm.status === 'trial'" class="form-row single" style="margin-top: 10px;">
                                        <div class="form-field">
                                            <label>Trial ends<span class="req">*</span></label>
                                            <input v-model="enableForm.trial_ends_at" type="date" required>
                                            <div v-if="enableForm.errors.trial_ends_at" class="err">{{ enableForm.errors.trial_ends_at }}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <footer class="slide-over-footer">
                                <button type="button" class="btn btn-secondary" @click="showEnableProduct = false">Cancel</button>
                                <button type="submit" class="btn btn-primary" :disabled="! enableForm.product_id || enableForm.processing">
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
            v-model:show="showSuspendModal"
            :title="suspendTarget ? `Suspend ${suspendTarget.name}?` : 'Suspend product?'"
            :message="suspendMessage"
            confirm-label="Suspend"
            variant="warning"
            :loading="suspendProcessing"
            @confirm="handleSuspend"
        />
    </InternalLayout>
</template>

<style scoped>
.slide-over { position: fixed; inset: 0; z-index: 40; }
.slide-over-form { height: 100%; display: flex; flex-direction: column; }
</style>
