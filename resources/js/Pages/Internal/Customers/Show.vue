<script setup>
import { computed, ref } from 'vue';
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
    IconKey,
    IconEye,
    IconCalendarCheck,
    IconCalendarX,
    IconClock,
    IconBan,
    IconRefresh,
    IconUser,
    IconCalendar,
    IconUserCheck,
    IconMessageCircle,
    IconPin,
    IconFileText,
    IconUpload,
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

/* ─── Contracts ─── */
const contracts = computed(() => props.customer.contracts ?? []);

const CONTRACT_TYPE_LABEL = {
    service_agreement: 'Service',
    sow: 'SoW',
    retainer: 'Retainer',
    nda: 'NDA',
    other: 'Other',
};
const CONTRACT_STATUS_LABEL = {
    draft: 'Draft',
    sent: 'Sent',
    signed: 'Signed',
    countersigned: 'Countersigned',
    expired: 'Expired',
    void: 'Void',
};
function contractStatusClass(c) {
    if (c.is_expired) return 'badge-overdue';
    return {
        draft: 'badge-inactive',
        sent: 'badge-pending',
        signed: 'badge-active',
        countersigned: 'badge-active',
        expired: 'badge-overdue',
        void: 'badge-inactive',
    }[c.status] ?? 'badge-inactive';
}
function formatMoney(value) {
    return Number(value || 0).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/* ─── Tabs ─── */
const activeTab = ref('overview');
const tabs = computed(() => [
    { key: 'overview',   label: 'Overview' },
    { key: 'invoices',   label: 'Invoices',   count: props.customer.invoices.length },
    { key: 'products',   label: 'Products',   count: props.customer.products.length },
    { key: 'contracts',  label: 'Contracts',  count: contracts.value.length },
    { key: 'support',    label: 'Support',    count: props.customer.open_tickets },
    { key: 'activities', label: 'Activities', count: props.customer.tasks.length },
    { key: 'activity',   label: 'Audit log' },
    { key: 'notes',      label: 'Notes',      count: props.customer.notes.length },
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

/* ─── CRM activities (formerly just "tasks") ─────────────────────── */

/*
 * The 5 activity types backing the slide-over. Tabler icon names
 * match the server's Task::$type_icon accessor — same source of
 * truth shared with Dashboard.vue.
 */
const ACTIVITY_TYPES = [
    { value: 'task',    label: 'Task',    icon: 'IconCheckbox' },
    { value: 'call',    label: 'Call',    icon: 'IconPhone' },
    { value: 'email',   label: 'Email',   icon: 'IconMail' },
    { value: 'meeting', label: 'Meeting', icon: 'IconUsersGroup' },
    { value: 'note',    label: 'Note',    icon: 'IconNotes' },
];

function placeholderForType(type) {
    return {
        task: 'What needs to be done?',
        call: 'Who to call / purpose of call',
        email: 'Email subject / purpose',
        meeting: 'Meeting title / agenda item',
        note: 'Note title (optional)',
    }[type] ?? '';
}

function descriptionLabelForType(type) {
    return {
        task: 'Details',
        call: 'Call notes',
        email: 'Email content / notes',
        meeting: 'Agenda / notes',
        note: 'Note content',
    }[type] ?? 'Details';
}

function scheduleLabelForType(type) {
    if (type === 'note') return null;
    return ['call', 'meeting', 'email'].includes(type) ? 'Scheduled' : 'Due';
}

function activityTypeLabel(type) {
    return ACTIVITY_TYPES.find((t) => t.value === type)?.label ?? type;
}

function iconForType(type) {
    const map = {
        task: IconCheckbox,
        call: IconPhone,
        email: IconMail,
        meeting: IconUsersGroup,
        note: IconNotes,
    };

    return map[type] ?? IconCheckbox;
}

function iconByName(name) {
    return {
        phone: IconPhone,
        mail: IconMail,
        users: IconUsersGroup,
        notes: IconNotes,
        checkbox: IconCheckbox,
    }[name] ?? IconCheckbox;
}

function formatDueAt(iso) {
    if (! iso) return '';
    const d = new Date(iso);
    const hasTime = d.getHours() !== 0 || d.getMinutes() !== 0;

    return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
        + (hasTime ? ' · ' + d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }) : '');
}

const showAddTask = ref(false);
const editingActivityId = ref(null);

const taskForm = useForm({
    type: 'task',
    title: '',
    description: '',
    priority: 'medium',
    customer_id: props.customer.id,
    contact_id: null,
    assigned_to: null,
    due_at: '',
    due_time: '',
    duration_minutes: null,
});

function openAddTask(type = 'task') {
    taskForm.reset();
    taskForm.clearErrors();
    taskForm.customer_id = props.customer.id;
    taskForm.type = type;
    taskForm.priority = 'medium';
    editingActivityId.value = null;
    showAddTask.value = true;
}

function openEditTask(t) {
    taskForm.reset();
    taskForm.clearErrors();
    taskForm.customer_id = props.customer.id;
    taskForm.type = t.type;
    taskForm.title = t.title ?? '';
    taskForm.description = t.description ?? '';
    taskForm.priority = t.priority ?? 'medium';
    taskForm.contact_id = t.contact_id ?? null;
    taskForm.assigned_to = t.assigned_to ?? null;
    taskForm.duration_minutes = t.duration_minutes ?? null;

    if (t.due_at) {
        const d = new Date(t.due_at);
        taskForm.due_at = d.toISOString().slice(0, 10);
        taskForm.due_time = `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
    } else {
        taskForm.due_at = '';
        taskForm.due_time = '';
    }

    editingActivityId.value = t.id;
    showAddTask.value = true;
}

function closeTaskForm() {
    showAddTask.value = false;
    editingActivityId.value = null;
    taskForm.reset();
}

function submitTask() {
    const payload = { ...taskForm.data() };
    if (payload.due_at && payload.due_time) {
        payload.due_at = `${payload.due_at} ${payload.due_time}`;
    }
    delete payload.due_time;

    if (payload.type === 'note') {
        payload.due_at = null;
        payload.duration_minutes = null;
    }
    if (! ['call', 'meeting'].includes(payload.type)) {
        payload.duration_minutes = null;
    }

    const onSuccess = () => {
        closeTaskForm();
    };

    if (editingActivityId.value) {
        taskForm
            .transform(() => payload)
            .put(`/tasks/${editingActivityId.value}`, { preserveScroll: true, onSuccess });
    } else {
        taskForm
            .transform(() => payload)
            .post('/tasks', { preserveScroll: true, onSuccess });
    }
}

/* Contacts for this customer's activity form */
const customerContactsForPicker = computed(() =>
    props.customer.contacts.map((c) => ({ id: c.id, name: c.name })),
);

/* ─── Activity timeline state ─── */
const activityFilter = ref('all'); // all | task | call | email | meeting | note
const showCompletedActivities = ref(false);

const filteredActivities = computed(() => {
    let list = props.customer.tasks;
    if (activityFilter.value !== 'all') {
        list = list.filter((t) => t.type === activityFilter.value);
    }
    if (! showCompletedActivities.value) {
        list = list.filter((t) => t.status !== 'complete');
    }

    return list;
});

const activityCounts = computed(() => {
    const open = props.customer.tasks.filter((t) => t.status !== 'complete');
    const result = { all: open.length };
    for (const type of ['task', 'call', 'email', 'meeting', 'note']) {
        result[type] = open.filter((t) => t.type === type).length;
    }

    return result;
});

/* ─── Complete activity — outcome modal ────────────────────────────── */
const showCompleteActivity = ref(false);
const completingActivity = ref(null);
const completeOutcome = ref('');
const completeProcessing = ref(false);

function askCompleteActivity(t) {
    completingActivity.value = t;
    completeOutcome.value = '';
    showCompleteActivity.value = true;
}

function performCompleteActivity() {
    if (! completingActivity.value) return;
    completeProcessing.value = true;
    router.post(
        `/tasks/${completingActivity.value.id}/complete`,
        { outcome: completeOutcome.value || null },
        {
            preserveScroll: true,
            onFinish: () => {
                completeProcessing.value = false;
                showCompleteActivity.value = false;
                completingActivity.value = null;
                completeOutcome.value = '';
            },
        },
    );
}

/* Toggle pin (sidebar / timeline) */
function togglePinActivity(t) {
    router.post(`/tasks/${t.id}/pin`, {}, { preserveScroll: true });
}

/* Delete activity */
const showDeleteActivity = ref(false);
const deletingActivity = ref(null);
const deleteActivityProcessing = ref(false);

function askDeleteActivity(t) {
    deletingActivity.value = t;
    showDeleteActivity.value = true;
}

function performDeleteActivity() {
    if (! deletingActivity.value) return;
    deleteActivityProcessing.value = true;
    router.delete(`/tasks/${deletingActivity.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deleteActivityProcessing.value = false;
            showDeleteActivity.value = false;
            deletingActivity.value = null;
        },
    });
}

/* ─── Contacts CRUD ────────────────────────────────────────────────── */
const showAddContact = ref(false);
const showEditContact = ref(false);
const editingContactId = ref(null);

const contactForm = useForm({
    customer_id: props.customer.id,
    name: '',
    job_title: '',
    email: '',
    phone: '',
    role: 'other',
    is_primary: false,
    notes: '',
});

function openAddContact() {
    contactForm.reset();
    contactForm.customer_id = props.customer.id;
    contactForm.role = 'other';
    contactForm.is_primary = false;
    editingContactId.value = null;
    showAddContact.value = true;
}

function openEditContact(c) {
    contactForm.reset();
    contactForm.customer_id = props.customer.id;
    contactForm.name = c.name ?? '';
    contactForm.job_title = c.job_title ?? '';
    contactForm.email = c.email ?? '';
    contactForm.phone = c.phone ?? '';
    contactForm.role = c.role ?? 'other';
    contactForm.is_primary = !! c.is_primary;
    contactForm.notes = c.notes ?? '';
    editingContactId.value = c.id;
    showEditContact.value = true;
}

function closeContactSlideOver() {
    showAddContact.value = false;
    showEditContact.value = false;
    editingContactId.value = null;
}

function submitContact() {
    const onSuccess = () => {
        closeContactSlideOver();
        contactForm.reset();
    };

    if (editingContactId.value) {
        contactForm.put(`/contacts/${editingContactId.value}`, {
            preserveScroll: true,
            onSuccess,
        });
    } else {
        contactForm.post('/contacts', {
            preserveScroll: true,
            onSuccess,
        });
    }
}

/* Delete contact (ConfirmModal) */
const showDeleteContact = ref(false);
const deleteContactTarget = ref(null);
const deleteContactProcessing = ref(false);

function askDeleteContact(c) {
    deleteContactTarget.value = c;
    showDeleteContact.value = true;
}

function performDeleteContact() {
    if (! deleteContactTarget.value) return;
    deleteContactProcessing.value = true;
    router.delete(`/contacts/${deleteContactTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deleteContactProcessing.value = false;
            showDeleteContact.value = false;
            deleteContactTarget.value = null;
        },
    });
}

function setContactPrimary(c) {
    router.post(`/contacts/${c.id}/primary`, {}, { preserveScroll: true });
}

const deleteContactMessage = computed(() =>
    deleteContactTarget.value
        ? `'${deleteContactTarget.value.name}' will be removed permanently. This cannot be undone.`
        : '',
);

/* ─── Per-contact portal access ────────────────────────────────────── */
const invitingPortalForContactId = ref(null);

function inviteContact(contact) {
    invitingPortalForContactId.value = contact.id;
    router.post(
        `/customers/${props.customer.id}/invite-portal`,
        { contact_id: contact.id },
        {
            preserveScroll: true,
            onFinish: () => {
                invitingPortalForContactId.value = null;
            },
        },
    );
}

const showRevokePortal = ref(false);
const revokePortalTarget = ref(null);
const revokePortalProcessing = ref(false);

function askRevokePortal(portalUser) {
    revokePortalTarget.value = portalUser;
    showRevokePortal.value = true;
}

function performRevokePortal() {
    if (! revokePortalTarget.value) return;
    revokePortalProcessing.value = true;
    router.post(
        `/customers/${props.customer.id}/portal-users/${revokePortalTarget.value.id}/revoke`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                revokePortalProcessing.value = false;
                showRevokePortal.value = false;
                revokePortalTarget.value = null;
            },
        },
    );
}

/* ─── Remove referral attribution (super_admin only) ─── */
const showRemoveReferral = ref(false);
const removeReferralProcessing = ref(false);

// usePage() resolves at render time so the role check stays reactive
// if the page reloads with a different session (eg. login swap).
const page = usePage();
const canRemoveReferral = computed(() => page.props.auth?.user?.role === 'super_admin');

// Preview portal is super_admin-only AND requires at least one contact
// with portal access — anything else and the impersonation endpoint
// would return a 422 anyway. Cheap to compute on every render: the
// contacts array is already in the payload.
const canPreviewPortal = computed(() =>
    page.props.auth?.user?.role === 'super_admin'
    && (props.customer?.contacts ?? []).some((c) => c.has_portal_access),
);

/*
 * Use fetch rather than router.post — the impersonation endpoint
 * returns a JSON {url}, and Inertia's router would try to follow it
 * as a page navigation. CSRF token comes from the blade meta tag.
 */
async function openPortalPreview() {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
    try {
        const res = await fetch(`/impersonate/portal/${props.customer.id}`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });
        const data = await res.json().catch(() => ({}));
        if (! res.ok) {
            window.alert(data?.error ?? `Preview failed (HTTP ${res.status}).`);

            return;
        }
        if (data?.url) {
            window.open(data.url, '_blank', 'noopener');
        }
    } catch (e) {
        window.alert('Could not open preview.');
    }
}

function performRemoveReferral() {
    removeReferralProcessing.value = true;
    router.delete(`/customers/${props.customer.id}/referral`, {
        preserveScroll: true,
        onFinish: () => {
            removeReferralProcessing.value = false;
            showRemoveReferral.value = false;
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
    plan_price_id: null,
    interval_count: 1,
    interval_unit: 'month',
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
    enableForm.interval_count = 1;
    enableForm.interval_unit = 'month';
    showEnableProduct.value = true;
}

function selectedAvailableProduct() {
    return props.available_products.find((p) => p.id === enableForm.product_id) ?? null;
}

function selectedPlan() {
    return selectedAvailableProduct()?.plans?.find((p) => p.id === enableForm.plan_id) ?? null;
}

function selectProduct(productId) {
    enableForm.product_id = productId;
    enableForm.plan_id = null;
    enableForm.plan_price_id = null;
    enableForm.plan = '';
    enableForm.price_monthly = null;
    enableForm.interval_count = 1;
    enableForm.interval_unit = 'month';
}

function selectPlan(plan) {
    // Step 1 of the two-step picker — pick the plan (tier). The
    // default price (or the first one) auto-selects so a customer is
    // ready to submit even with one click.
    enableForm.plan_id = plan.id;
    enableForm.plan = plan.name;
    const def = (plan.prices ?? []).find((p) => p.is_default) ?? plan.prices?.[0];
    if (def) {
        selectPrice(def);
    } else {
        enableForm.plan_price_id = null;
        enableForm.price_monthly = null;
        enableForm.interval_count = 1;
        enableForm.interval_unit = 'month';
    }
}

function selectPrice(price) {
    // Step 2 — pick the billing option (price). Plan stays put.
    enableForm.plan_price_id = price.id;
    enableForm.price_monthly = price.price;
    enableForm.interval_count = price.interval_count;
    enableForm.interval_unit = price.interval_unit;
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

// Task completion + outcome flow lives further up in the CRM activities
// block — completingTaskId is gone, replaced by completingActivity +
// performCompleteActivity which posts an optional outcome with the
// completion.

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

/* ─── Contracts CRUD ─── */
const showContractForm = ref(false);
const editingContractId = ref(null);
const contractFileName = ref(''); // local UI hint for the picked file

const contractForm = useForm({
    customer_id: props.customer.id,
    title: '',
    description: '',
    type: 'service_agreement',
    status: 'draft',
    signed_at: '',
    start_date: '',
    end_date: '',
    value: null,
    notes: '',
    file: null,
});

function resetContractForm() {
    contractForm.reset();
    contractForm.clearErrors();
    contractForm.customer_id = props.customer.id;
    contractForm.type = 'service_agreement';
    contractForm.status = 'draft';
    contractFileName.value = '';
}

function openAddContract() {
    editingContractId.value = null;
    resetContractForm();
    showContractForm.value = true;
}

function openEditContract(c) {
    editingContractId.value = c.id;
    resetContractForm();
    contractForm.title = c.title ?? '';
    contractForm.description = c.description ?? '';
    contractForm.type = c.type ?? 'service_agreement';
    contractForm.status = c.status ?? 'draft';
    contractForm.signed_at = c.signed_at_iso ?? (c.signed_at ? toIsoDate(c.signed_at) : '');
    contractForm.start_date = c.start_date ?? '';
    contractForm.end_date = c.end_date ?? '';
    contractForm.value = c.value;
    contractForm.notes = c.notes ?? '';
    contractFileName.value = c.original_name ?? '';
    showContractForm.value = true;
}

// "26 Aug 2026" → "2026-08-26" so the date input accepts the value
// we already formatted server-side for display.
function toIsoDate(human) {
    try {
        const d = new Date(human);
        if (Number.isNaN(d.getTime())) return '';
        return d.toISOString().slice(0, 10);
    } catch (_) {
        return '';
    }
}

function onContractFile(event) {
    const file = event.target.files?.[0] ?? null;
    contractForm.file = file;
    contractFileName.value = file?.name ?? '';
}

function submitContract() {
    const target = editingContractId.value
        ? `/contracts/${editingContractId.value}`
        : '/contracts';
    contractForm.post(target, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            showContractForm.value = false;
            resetContractForm();
            editingContractId.value = null;
        },
    });
}

const showContractDeleteModal = ref(false);
const contractDeleteTarget = ref(null);
const contractDeleteProcessing = ref(false);
function askDeleteContract(c) {
    contractDeleteTarget.value = c;
    showContractDeleteModal.value = true;
}
function performDeleteContract() {
    if (! contractDeleteTarget.value) return;
    contractDeleteProcessing.value = true;
    router.delete(`/contracts/${contractDeleteTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            contractDeleteProcessing.value = false;
            showContractDeleteModal.value = false;
            contractDeleteTarget.value = null;
        },
    });
}
const contractDeleteMessage = computed(() =>
    contractDeleteTarget.value
        ? `"${contractDeleteTarget.value.title}" and its attached PDF (if any) will be permanently removed.`
        : '',
);
</script>

<template>
    <Head :title="customer.name" />

    <InternalLayout :title="customer.name" :breadcrumbs="breadcrumbs" active-nav="customers">
        <template #topbar-actions>
            <button
                v-if="canPreviewPortal"
                type="button"
                class="btn btn-secondary"
                title="Open the customer portal as this customer in a new tab"
                @click="openPortalPreview"
            >
                <IconEye :size="15" stroke-width="1.75" />
                Preview portal
            </button>
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
                                    <div class="cp-dates">
                                        <span v-if="p.started_at" class="cp-date">
                                            <IconCalendarCheck :size="12" stroke-width="1.75" />
                                            Active since {{ formatDate(p.started_at) }}
                                        </span>
                                        <span v-if="p.next_billing_date && p.status === 'active'" class="cp-date cp-date-renew">
                                            <IconRefresh :size="12" stroke-width="1.75" />
                                            Renews {{ formatDate(p.next_billing_date) }}
                                        </span>
                                        <span v-if="p.trial_ends_at && p.status === 'trial'" class="cp-date cp-date-trial">
                                            <IconClock :size="12" stroke-width="1.75" />
                                            Trial ends {{ formatDate(p.trial_ends_at) }}
                                        </span>
                                        <span v-if="p.cancels_at" class="cp-date cp-date-cancels">
                                            <IconCalendarX :size="12" stroke-width="1.75" />
                                            Cancels {{ formatDate(p.cancels_at) }}
                                        </span>
                                        <span v-if="p.cancelled_at && p.status === 'cancelled'" class="cp-date cp-date-cancelled">
                                            <IconBan :size="12" stroke-width="1.75" />
                                            Cancelled {{ formatDate(p.cancelled_at) }}
                                        </span>
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
                            <Link
                                v-for="inv in customer.invoices.slice(0, 3)"
                                :key="inv.id"
                                :href="`/invoices/${inv.id}`"
                                class="inv-row inv-row-clickable"
                            >
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
                                </div>
                            </Link>
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
                    <!-- Contacts (with per-contact portal access folded in) -->
                    <section class="card">
                        <header class="card-header">
                            <div class="h-icon"><IconAddressBook :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Contacts</h3>
                                <div class="sub">{{ customer.contacts.length }} on file</div>
                            </div>
                            <div class="right">
                                <button type="button" class="ghost-link" @click="openAddContact">
                                    <IconPlus :size="14" stroke-width="1.75" />
                                    Add contact
                                </button>
                            </div>
                        </header>

                        <div v-if="customer.contacts.length" style="padding: 14px 16px 16px; display: flex; flex-direction: column; gap: 10px;">
                            <article v-for="c in customer.contacts" :key="c.id" class="contact-card">
                                <header class="contact-card-head">
                                    <div class="avatar av-navy" style="width: 32px; height: 32px;">{{ userInitials(c.name) }}</div>
                                    <div class="contact-card-name-block">
                                        <div class="contact-card-name">
                                            {{ c.name }}
                                            <span v-if="c.is_primary" class="badge badge-gold badge-sm" style="margin-left: 6px;">Primary</span>
                                        </div>
                                        <div v-if="c.job_title || c.role" class="contact-card-role">
                                            <template v-if="c.job_title">{{ c.job_title }}</template>
                                            <template v-else>{{ ({ owner: 'Owner', manager: 'Manager', accounts: 'Accounts', other: 'Other' })[c.role] }}</template>
                                        </div>
                                    </div>
                                    <Menu as="div" class="dd-menu">
                                        <MenuButton class="icon-btn" aria-label="Contact actions">
                                            <IconDots :size="16" stroke-width="1.75" />
                                        </MenuButton>
                                        <MenuItems class="dd-popover right-align">
                                            <MenuItem v-if="! c.is_primary" v-slot="{ active }">
                                                <button type="button" :class="['dd-option', { active }]" @click="setContactPrimary(c)">Set as primary</button>
                                            </MenuItem>
                                            <MenuItem v-slot="{ active }">
                                                <button type="button" :class="['dd-option', { active }]" @click="openEditContact(c)">Edit contact</button>
                                            </MenuItem>
                                            <MenuItem v-if="! c.has_portal_access && c.email" v-slot="{ active }">
                                                <button type="button" :class="['dd-option', { active }]" @click="inviteContact(c)">Invite to portal</button>
                                            </MenuItem>
                                            <MenuItem v-if="c.has_portal_access" v-slot="{ active }">
                                                <button type="button" :class="['dd-option', { active }]" @click="askRevokePortal({ id: c.portal_user_id, email: c.portal_email, name: c.name })">Revoke portal access</button>
                                            </MenuItem>
                                            <div style="height: 1px; background: var(--border-soft); margin: 4px 0;" />
                                            <MenuItem v-slot="{ active }">
                                                <button type="button" :class="['dd-option', { active }]" style="color: var(--danger);" @click="askDeleteContact(c)">Delete contact</button>
                                            </MenuItem>
                                        </MenuItems>
                                    </Menu>
                                </header>

                                <div class="contact-card-detail">
                                    <div v-if="c.email" class="contact-card-field">
                                        <IconMail :size="14" stroke-width="1.75" />
                                        <a :href="`mailto:${c.email}`">{{ c.email }}</a>
                                        <button type="button" class="copy" :aria-label="`Copy ${c.email}`" @click="copyText(c.email)">
                                            <IconCopy :size="12" stroke-width="1.75" />
                                        </button>
                                    </div>
                                    <div v-if="c.phone" class="contact-card-field">
                                        <IconPhone :size="14" stroke-width="1.75" />
                                        <a :href="`tel:${c.phone}`">{{ c.phone }}</a>
                                        <button type="button" class="copy" :aria-label="`Copy ${c.phone}`" @click="copyText(c.phone)">
                                            <IconCopy :size="12" stroke-width="1.75" />
                                        </button>
                                    </div>
                                    <div v-if="c.notes" class="contact-card-notes">
                                        <IconNotes :size="14" stroke-width="1.75" />
                                        <span>{{ c.notes }}</span>
                                    </div>
                                </div>

                                <footer class="contact-card-portal">
                                    <template v-if="c.has_portal_access">
                                        <span class="badge badge-active badge-sm">Portal access</span>
                                        <span class="contact-card-portal-meta">
                                            <template v-if="c.portal_last_login">Last sign-in {{ c.portal_last_login }}</template>
                                            <template v-else>Never signed in</template>
                                        </span>
                                    </template>
                                    <template v-else-if="c.email">
                                        <span class="contact-card-portal-empty">No portal access</span>
                                        <button
                                            type="button"
                                            class="ghost-link accent"
                                            :disabled="invitingPortalForContactId === c.id"
                                            @click="inviteContact(c)"
                                        >
                                            <IconUserPlus :size="12" stroke-width="1.75" />
                                            {{ invitingPortalForContactId === c.id ? 'Inviting…' : 'Invite to portal' }}
                                        </button>
                                    </template>
                                    <template v-else>
                                        <span class="contact-card-portal-empty">No email on file — cannot invite to portal</span>
                                    </template>
                                </footer>
                            </article>
                        </div>
                        <div v-else class="tab-empty" style="padding: 32px 18px;">
                            <p>No contacts on file.</p>
                            <button type="button" class="btn btn-primary" @click="openAddContact" style="margin-top: 8px;">
                                <IconPlus :size="14" stroke-width="1.75" />
                                Add first contact
                            </button>
                        </div>
                    </section>

                    <!-- Activities (open only — full timeline lives in the Activities tab) -->
                    <section class="card">
                        <header class="card-header">
                            <div class="h-icon"><IconCheckbox :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Activities</h3>
                                <div class="sub">{{ activityCounts.all }} open</div>
                            </div>
                            <div class="right">
                                <button type="button" class="ghost-link" @click="openAddTask('task')">
                                    <IconPlus :size="14" stroke-width="1.75" />
                                    New
                                </button>
                            </div>
                        </header>
                        <div v-if="customer.tasks.filter((t) => t.status !== 'complete').length" style="padding: 8px 18px 4px;">
                            <div
                                v-for="t in customer.tasks.filter((t) => t.status !== 'complete').slice(0, 6)"
                                :key="t.id"
                                class="act-preview-row"
                                style="cursor: pointer;"
                                @click="activeTab = 'activities'"
                            >
                                <!--
                                  Coloured type chip — keyed off Task::$type_colour
                                  so the model is the source of truth. The 22 suffix
                                  is a ~13% alpha tint of the same hex, giving a
                                  pastel disc the icon sits on top of cleanly.
                                -->
                                <div
                                    class="apr-type"
                                    :style="{ background: `${t.type_colour}22`, color: t.type_colour }"
                                >
                                    <component :is="iconByName(t.type_icon)" :size="14" stroke-width="2" />
                                </div>

                                <div class="apr-body">
                                    <div class="apr-meta">
                                        <span class="apr-type-label" :style="{ color: t.type_colour }">
                                            {{ activityTypeLabel(t.type) }}
                                        </span>
                                        <template v-if="t.due_at">
                                            <span class="apr-dot">·</span>
                                            <span class="apr-due" :class="{ 'apr-overdue': t.is_overdue }">
                                                {{ formatDueAt(t.due_at) }}
                                            </span>
                                        </template>
                                    </div>

                                    <div class="apr-title">
                                        <IconPin v-if="t.is_pinned" :size="11" stroke-width="2" style="color: var(--accent); margin-right: 4px;" />
                                        {{ t.title }}
                                    </div>

                                    <div v-if="t.description" class="apr-excerpt">
                                        {{ t.description.length > 80 ? t.description.slice(0, 80) + '…' : t.description }}
                                    </div>

                                    <div v-if="t.assigned_to_name" class="apr-assigned">
                                        <IconUser :size="11" stroke-width="1.75" />
                                        {{ t.assigned_to_name }}
                                    </div>
                                </div>

                                <div class="apr-priority" :class="t.priority" :title="`Priority: ${t.priority}`" />
                            </div>
                            <div v-if="activityCounts.all > 6" style="padding: 8px 0 4px; text-align: center;">
                                <button type="button" class="ghost-link" @click.stop="activeTab = 'activities'">
                                    View all activities
                                    <IconArrowRight :size="14" stroke-width="1.75" />
                                </button>
                            </div>
                        </div>
                        <div v-else class="tab-empty" style="padding: 28px 18px;">
                            <p>No open activities.</p>
                            <button type="button" class="ghost-link" @click="openAddTask('task')">
                                <IconPlus :size="14" stroke-width="1.75" />
                                Add first activity
                            </button>
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
                            <button
                                v-if="canRemoveReferral"
                                type="button"
                                class="ghost-link danger"
                                style="font-size: 11px; margin-left: auto;"
                                @click="showRemoveReferral = true"
                            >
                                Remove referral
                            </button>
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
                        <Link
                            v-for="inv in customer.invoices"
                            :key="inv.id"
                            :href="`/invoices/${inv.id}`"
                            class="inv-row inv-row-clickable"
                        >
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
                        </Link>
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
                                </div>
                                <div class="cp-dates">
                                    <span v-if="p.started_at" class="cp-date">
                                        <IconCalendarCheck :size="12" stroke-width="1.75" />
                                        Active since {{ formatDate(p.started_at) }}
                                    </span>
                                    <span v-if="p.next_billing_date && p.status === 'active'" class="cp-date cp-date-renew">
                                        <IconRefresh :size="12" stroke-width="1.75" />
                                        Renews {{ formatDate(p.next_billing_date) }}
                                    </span>
                                    <span v-if="p.trial_ends_at && p.status === 'trial'" class="cp-date cp-date-trial">
                                        <IconClock :size="12" stroke-width="1.75" />
                                        Trial ends {{ formatDate(p.trial_ends_at) }}
                                    </span>
                                    <span v-if="p.cancels_at" class="cp-date cp-date-cancels">
                                        <IconCalendarX :size="12" stroke-width="1.75" />
                                        Cancels {{ formatDate(p.cancels_at) }}
                                    </span>
                                    <span v-if="p.cancelled_at && p.status === 'cancelled'" class="cp-date cp-date-cancelled">
                                        <IconBan :size="12" stroke-width="1.75" />
                                        Cancelled {{ formatDate(p.cancelled_at) }}
                                    </span>
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
            <div v-else-if="activeTab === 'contracts'" class="cust-contracts" style="margin: 0 -24px -24px; padding: 24px;">
                <section class="card">
                    <header class="card-header">
                        <div class="h-icon"><IconReceipt2 :size="16" stroke-width="1.75" /></div>
                        <div>
                            <h3>Contracts</h3>
                            <div class="sub">{{ contracts.length }} on file</div>
                        </div>
                        <div class="right">
                            <button type="button" class="btn btn-primary btn-sm" @click="openAddContract">
                                <IconPlus :size="14" stroke-width="1.75" />
                                Add contract
                            </button>
                        </div>
                    </header>

                    <div v-if="contracts.length === 0" class="tab-empty">
                        <div style="color: var(--text-tertiary); display: inline-flex;">
                            <IconFileText :size="40" stroke-width="1.5" />
                        </div>
                        <h3>No contracts yet</h3>
                        <p>Upload signed agreements, NDAs, or statements of work so they're discoverable next to the account.</p>
                        <button type="button" class="ghost-link" @click="openAddContract">
                            + Add first contract
                        </button>
                    </div>

                    <div v-else class="contract-list">
                        <article v-for="c in contracts" :key="c.id" class="contract-card">
                            <header class="contract-card-head">
                                <span class="contract-type-badge" :class="`type-${c.type}`">{{ CONTRACT_TYPE_LABEL[c.type] || c.type }}</span>
                                <span class="contract-title">{{ c.title }}</span>
                                <span class="badge badge-sm" :class="contractStatusClass(c)">{{ CONTRACT_STATUS_LABEL[c.status] || c.status }}</span>
                                <Menu as="div" class="dd-menu" style="margin-left: auto;">
                                    <MenuButton class="icon-btn" aria-label="Contract actions">
                                        <IconDots :size="16" stroke-width="1.75" />
                                    </MenuButton>
                                    <MenuItems class="dd-popover right-align">
                                        <MenuItem v-slot="{ active }">
                                            <button type="button" :class="['dd-option', { active }]" @click="openEditContract(c)">Edit</button>
                                        </MenuItem>
                                        <div style="height: 1px; background: var(--border-soft); margin: 4px 0;" />
                                        <MenuItem v-slot="{ active }">
                                            <button type="button" :class="['dd-option', { active }]" style="color: var(--danger);" @click="askDeleteContract(c)">Delete</button>
                                        </MenuItem>
                                    </MenuItems>
                                </Menu>
                            </header>

                            <div v-if="c.description" class="contract-desc">{{ c.description }}</div>

                            <div class="contract-meta">
                                <span v-if="c.value !== null" class="contract-value">£{{ formatMoney(c.value) }}</span>
                                <span v-if="c.signed_at" class="contract-meta-row">
                                    <IconCheck :size="11" stroke-width="2" /> Signed {{ c.signed_at }}
                                </span>
                                <span v-else class="contract-meta-row muted">
                                    Not yet signed
                                </span>
                                <span v-if="c.end_date_display && c.expires_in_days !== null && c.expires_in_days < 0" class="warn-pill red">
                                    Expired {{ c.end_date_display }}
                                </span>
                                <span v-else-if="c.end_date_display && c.expires_in_days !== null && c.expires_in_days <= 30" class="warn-pill">
                                    Expires in {{ c.expires_in_days }} day{{ c.expires_in_days === 1 ? '' : 's' }}
                                </span>
                                <span v-else-if="c.end_date_display" class="contract-meta-row muted">
                                    Expires {{ c.end_date_display }}
                                </span>
                            </div>

                            <div v-if="c.notes" class="contract-notes">{{ c.notes }}</div>

                            <footer class="contract-card-foot">
                                <span class="contract-foot-meta">
                                    <template v-if="c.uploader">{{ c.uploader }} · </template>{{ c.created_at }}
                                </span>
                                <a
                                    v-if="c.has_file"
                                    :href="`/contracts/${c.id}/download`"
                                    target="_blank"
                                    rel="noopener"
                                    class="ghost-link"
                                >
                                    <IconDownload :size="13" stroke-width="1.75" />
                                    Download PDF
                                </a>
                                <span v-else class="contract-no-file">No file attached</span>
                            </footer>
                        </article>
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
            <!-- ═══ ACTIVITIES TAB ═══ -->
            <div v-else-if="activeTab === 'activities'" style="margin: 0 -24px -24px; padding: 24px;">
                <section class="card">
                    <header class="card-header">
                        <div class="h-icon"><IconCheckbox :size="16" stroke-width="1.75" /></div>
                        <div>
                            <h3>Activities &amp; tasks</h3>
                            <div class="sub">Calls, emails, meetings, notes &amp; tasks for {{ customer.name }}</div>
                        </div>
                        <div class="right">
                            <button type="button" class="btn btn-primary btn-sm" @click="openAddTask('task')">
                                <IconPlus :size="14" stroke-width="1.75" />
                                New activity
                            </button>
                        </div>
                    </header>

                    <!-- Filter strip -->
                    <div class="activities-filter-bar">
                        <button
                            type="button"
                            class="af-chip"
                            :class="{ active: activityFilter === 'all' }"
                            @click="activityFilter = 'all'"
                        >
                            All
                            <span v-if="activityCounts.all" class="af-count">{{ activityCounts.all }}</span>
                        </button>
                        <button
                            v-for="t in ACTIVITY_TYPES"
                            :key="t.value"
                            type="button"
                            class="af-chip"
                            :class="{ active: activityFilter === t.value }"
                            @click="activityFilter = t.value"
                        >
                            {{ t.label }}s<span v-if="activityCounts[t.value]" class="af-count">{{ activityCounts[t.value] }}</span>
                        </button>
                        <label class="af-toggle">
                            <input v-model="showCompletedActivities" type="checkbox">
                            <span>Show completed</span>
                        </label>
                    </div>

                    <!-- Timeline -->
                    <div v-if="filteredActivities.length" class="activity-timeline">
                        <article
                            v-for="t in filteredActivities"
                            :key="t.id"
                            class="activity-item"
                            :class="[
                                'act-' + t.type,
                                {
                                    completed: t.status === 'complete',
                                    overdue: t.is_overdue,
                                    pinned: t.is_pinned,
                                },
                            ]"
                        >
                            <div class="act-type-icon" :style="{ background: t.type_colour }">
                                <component :is="iconByName(t.type_icon)" :size="14" stroke-width="2" color="#fff" />
                            </div>

                            <div class="act-content">
                                <header class="act-header">
                                    <!-- Activity title routes to the detail page so the
                                         operator can drill into notes + linked tasks. -->
                                    <Link
                                        :href="`/activities/${t.id}`"
                                        class="act-title"
                                        :class="{ done: t.status === 'complete' }"
                                    >{{ t.title || activityTypeLabel(t.type) }}</Link>
                                    <span class="act-priority-dot" :class="t.priority" :title="`Priority: ${t.priority}`" />
                                    <span v-if="t.contact_name" class="act-contact">
                                        <IconUser :size="11" stroke-width="1.75" />
                                        {{ t.contact_name }}
                                    </span>
                                    <span v-if="t.is_overdue" class="warn-pill red">Overdue</span>
                                    <span v-if="t.is_pinned" class="act-pin" title="Pinned">
                                        <IconPin :size="11" stroke-width="2" />
                                    </span>
                                    <Menu as="div" class="dd-menu" style="margin-left: auto;">
                                        <MenuButton class="icon-btn" aria-label="Activity actions">
                                            <IconDots :size="14" stroke-width="1.75" />
                                        </MenuButton>
                                        <MenuItems class="dd-popover right-align">
                                            <MenuItem v-slot="{ active }">
                                                <Link :href="`/activities/${t.id}`" :class="['dd-option', { active }]">View details</Link>
                                            </MenuItem>
                                            <MenuItem v-if="t.status !== 'complete'" v-slot="{ active }">
                                                <button type="button" :class="['dd-option', { active }]" @click="askCompleteActivity(t)">Mark complete</button>
                                            </MenuItem>
                                            <MenuItem v-slot="{ active }">
                                                <button type="button" :class="['dd-option', { active }]" @click="openEditTask(t)">Edit</button>
                                            </MenuItem>
                                            <MenuItem v-slot="{ active }">
                                                <button type="button" :class="['dd-option', { active }]" @click="togglePinActivity(t)">{{ t.is_pinned ? 'Unpin' : 'Pin to top' }}</button>
                                            </MenuItem>
                                            <div style="height: 1px; background: var(--border-soft); margin: 4px 0;" />
                                            <MenuItem v-slot="{ active }">
                                                <button type="button" :class="['dd-option', { active }]" style="color: var(--danger);" @click="askDeleteActivity(t)">Delete</button>
                                            </MenuItem>
                                        </MenuItems>
                                    </Menu>
                                </header>

                                <div v-if="t.description" class="act-desc">{{ t.description }}</div>

                                <div v-if="t.outcome" class="act-outcome">
                                    <IconMessageCircle :size="13" stroke-width="1.75" />
                                    <span>{{ t.outcome }}</span>
                                </div>

                                <div class="act-meta">
                                    <span v-if="t.due_at">
                                        <IconCalendar :size="12" stroke-width="1.75" />
                                        {{ formatDueAt(t.due_at) }}
                                    </span>
                                    <span v-if="t.duration_minutes">
                                        <IconClock :size="12" stroke-width="1.75" />
                                        {{ t.duration_minutes }}min
                                    </span>
                                    <span v-if="t.assigned_to_name">
                                        <IconUserCheck :size="12" stroke-width="1.75" />
                                        {{ t.assigned_to_name }}
                                    </span>
                                    <span v-if="t.completed_at_human" class="act-done-time">
                                        ✓ Completed {{ t.completed_at_human }}
                                    </span>
                                </div>
                            </div>

                            <button
                                v-if="t.type !== 'note' && t.status !== 'complete'"
                                type="button"
                                class="act-complete-btn"
                                :title="`Mark '${t.title}' complete`"
                                @click="askCompleteActivity(t)"
                            >
                                <IconCheck :size="14" stroke-width="2.25" />
                            </button>
                        </article>
                    </div>
                    <div v-else class="tab-empty">
                        <h3>No matching activities</h3>
                        <p v-if="customer.tasks.length === 0">Log a call, schedule a meeting, or add a note to get started.</p>
                        <p v-else>Adjust the filters above or create a new activity.</p>
                    </div>
                </section>
            </div>

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
                                    <!-- STEP 1 — pick the plan (tier). Plans render grouped by category;
                                         backend keeps a flat .plans array as the source of truth for
                                         selectedPlan() lookups. -->
                                    <template v-if="(selectedAvailableProduct()?.plans ?? []).length > 0">
                                        <div style="display: flex; flex-direction: column; gap: 8px;">
                                            <!-- Categorised groups (header + plan cards) -->
                                            <template
                                                v-for="category in (selectedAvailableProduct()?.plan_categories ?? [])"
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
                                                    @click="selectPlan(plan)"
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
                                            <template v-if="(selectedAvailableProduct()?.uncategorised_plans ?? []).length">
                                                <div
                                                    v-if="(selectedAvailableProduct()?.plan_categories ?? []).length"
                                                    class="enable-category-header enable-category-uncategorised"
                                                >
                                                    Other
                                                </div>
                                                <button
                                                    v-for="plan in selectedAvailableProduct().uncategorised_plans"
                                                    :key="`up-${plan.id}`"
                                                    type="button"
                                                    class="enable-plan-card"
                                                    :class="{ selected: enableForm.plan_id === plan.id }"
                                                    @click="selectPlan(plan)"
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

                                            <!-- Defensive fallback: an older payload shape with no
                                                 plan_categories / uncategorised_plans still renders. -->
                                            <template
                                                v-if="!(selectedAvailableProduct()?.plan_categories ?? []).length
                                                    && !(selectedAvailableProduct()?.uncategorised_plans ?? []).length"
                                            >
                                                <button
                                                    v-for="plan in selectedAvailableProduct().plans"
                                                    :key="`fp-${plan.id}`"
                                                    type="button"
                                                    class="enable-plan-card"
                                                    :class="{ selected: enableForm.plan_id === plan.id }"
                                                    @click="selectPlan(plan)"
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
                                    <!-- Fallback: free-text when product has no plans -->
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

                                <!-- STEP 2 — pick the billing option (price). Auto-selects the default. -->
                                <div v-if="enableForm.plan_id && (selectedPlan()?.prices ?? []).length > 0" class="form-section">
                                    <div class="enable-step-label">Select billing interval</div>
                                    <button
                                        v-for="price in selectedPlan().prices"
                                        :key="`pp-${price.id}`"
                                        type="button"
                                        class="enable-price-row"
                                        :class="{ selected: enableForm.plan_price_id === price.id }"
                                        @click="selectPrice(price)"
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

        <!-- Add/Edit contact slide-over -->
        <TransitionRoot as="template" :show="showAddContact || showEditContact">
            <Dialog as="div" class="slide-over-dialog" @close="closeContactSlideOver">
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
                    <DialogPanel class="slide-over-panel" style="width: 480px;">
                        <form class="slide-over-form" @submit.prevent="submitContact">
                            <header class="slide-over-header">
                                <h2>{{ showEditContact ? 'Edit contact' : 'Add contact' }}</h2>
                                <button type="button" class="icon-btn" aria-label="Close" @click="closeContactSlideOver">
                                    <IconX :size="18" stroke-width="1.75" />
                                </button>
                            </header>
                            <div class="slide-over-body">
                                <div class="form-section">
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Name<span class="req">*</span></label>
                                            <input
                                                v-model="contactForm.name"
                                                type="text"
                                                :class="{ 'has-err': contactForm.errors.name }"
                                                placeholder="Full name"
                                                required
                                            >
                                            <div v-if="contactForm.errors.name" class="err">{{ contactForm.errors.name }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Job title <span style="color: var(--text-tertiary); font-weight: 400;">(optional)</span></label>
                                            <input
                                                v-model="contactForm.job_title"
                                                type="text"
                                                :class="{ 'has-err': contactForm.errors.job_title }"
                                                placeholder="e.g. Head Chef, Owner, Manager"
                                            >
                                            <div v-if="contactForm.errors.job_title" class="err">{{ contactForm.errors.job_title }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Email <span style="color: var(--text-tertiary); font-weight: 400;">(optional)</span></label>
                                            <input
                                                v-model="contactForm.email"
                                                type="email"
                                                :class="{ 'has-err': contactForm.errors.email }"
                                                placeholder="contact@example.com"
                                            >
                                            <div v-if="contactForm.errors.email" class="err">{{ contactForm.errors.email }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Phone <span style="color: var(--text-tertiary); font-weight: 400;">(optional)</span></label>
                                            <input
                                                v-model="contactForm.phone"
                                                type="tel"
                                                :class="{ 'has-err': contactForm.errors.phone }"
                                                placeholder="+44 7700 900000"
                                            >
                                            <div v-if="contactForm.errors.phone" class="err">{{ contactForm.errors.phone }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Role</label>
                                            <select v-model="contactForm.role">
                                                <option value="owner">Owner</option>
                                                <option value="manager">Manager</option>
                                                <option value="accounts">Accounts</option>
                                                <option value="other">Other</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Notes <span style="color: var(--text-tertiary); font-weight: 400;">(optional)</span></label>
                                            <textarea
                                                v-model="contactForm.notes"
                                                rows="2"
                                                :class="{ 'has-err': contactForm.errors.notes }"
                                                placeholder="Preferred contact times, nicknames, etc."
                                            />
                                            <div v-if="contactForm.errors.notes" class="err">{{ contactForm.errors.notes }}</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-section">
                                    <h3>Primary contact</h3>
                                    <div class="status-rows">
                                        <div class="set-row">
                                            <div>
                                                <div class="nm">Set as primary contact</div>
                                                <div class="sb">Primary contact receives invoices and main communications.</div>
                                            </div>
                                            <button type="button" class="toggle" :class="{ on: contactForm.is_primary }" aria-label="Toggle primary" @click="contactForm.is_primary = ! contactForm.is_primary" />
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <footer class="slide-over-footer">
                                <button type="button" class="btn btn-secondary" @click="closeContactSlideOver">Cancel</button>
                                <button type="submit" class="btn btn-primary" :disabled="contactForm.processing">
                                    {{ contactForm.processing ? 'Saving…' : 'Save contact' }}
                                </button>
                            </footer>
                        </form>
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </TransitionRoot>

        <ConfirmModal
            v-model:show="showDeleteContact"
            :title="deleteContactTarget ? `Delete ${deleteContactTarget.name}?` : 'Delete contact?'"
            :message="deleteContactMessage"
            confirm-label="Delete contact"
            variant="danger"
            :loading="deleteContactProcessing"
            @confirm="performDeleteContact"
        />

        <ConfirmModal
            v-model:show="showRevokePortal"
            :title="revokePortalTarget ? `Revoke portal access for ${revokePortalTarget.email}?` : 'Revoke portal access?'"
            message="They will be signed out and unable to sign back in until you re-invite them."
            confirm-label="Revoke access"
            variant="danger"
            :loading="revokePortalProcessing"
            @confirm="performRevokePortal"
        />

        <ConfirmModal
            v-model:show="showRemoveReferral"
            title="Remove referral attribution?"
            :message="`This will remove the referral attribution from ${customer.name}. Any pending commissions from this referral will be voided. This cannot be undone.`"
            confirm-label="Remove referral"
            variant="danger"
            :loading="removeReferralProcessing"
            @confirm="performRemoveReferral"
        />

        <!-- Activity create/edit slide-over -->
        <TransitionRoot as="template" :show="showAddTask">
            <Dialog as="div" class="slide-over-dialog" @close="closeTaskForm">
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
                    <DialogPanel class="slide-over-panel" style="width: 480px;">
                        <form class="slide-over-form" @submit.prevent="submitTask">
                            <header class="slide-over-header">
                                <h2>{{ editingActivityId ? 'Edit activity' : 'New activity' }}</h2>
                                <button type="button" class="icon-btn" aria-label="Close" @click="closeTaskForm">
                                    <IconX :size="18" stroke-width="1.75" />
                                </button>
                            </header>
                            <div class="slide-over-body">
                                <div class="form-section">
                                    <div class="activity-type-picker">
                                        <button
                                            v-for="at in ACTIVITY_TYPES"
                                            :key="at.value"
                                            type="button"
                                            class="atp-btn"
                                            :class="{ active: taskForm.type === at.value }"
                                            @click="taskForm.type = at.value"
                                        >
                                            <component :is="iconForType(at.value)" :size="18" stroke-width="1.75" />
                                            <span>{{ at.label }}</span>
                                        </button>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>
                                                {{ taskForm.type === 'note' ? 'Title (optional)' : 'Title' }}
                                                <span v-if="taskForm.type !== 'note'" class="req">*</span>
                                            </label>
                                            <input
                                                v-model="taskForm.title"
                                                type="text"
                                                :placeholder="placeholderForType(taskForm.type)"
                                                maxlength="500"
                                                :class="{ 'has-err': taskForm.errors.title }"
                                                :required="taskForm.type !== 'note'"
                                            >
                                            <div v-if="taskForm.errors.title" class="err">{{ taskForm.errors.title }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>{{ descriptionLabelForType(taskForm.type) }}</label>
                                            <textarea
                                                v-model="taskForm.description"
                                                rows="4"
                                                maxlength="5000"
                                                :class="{ 'has-err': taskForm.errors.description }"
                                            />
                                            <div v-if="taskForm.errors.description" class="err">{{ taskForm.errors.description }}</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <div class="form-row">
                                        <div class="form-field">
                                            <label>Priority</label>
                                            <div class="priority-pills">
                                                <button
                                                    v-for="p in ['low', 'medium', 'high']"
                                                    :key="p"
                                                    type="button"
                                                    class="pp-btn"
                                                    :class="[p, { active: taskForm.priority === p }]"
                                                    @click="taskForm.priority = p"
                                                >{{ p.charAt(0).toUpperCase() + p.slice(1) }}</button>
                                            </div>
                                        </div>
                                        <div v-if="['call', 'meeting'].includes(taskForm.type)" class="form-field">
                                            <label>Duration (minutes)</label>
                                            <input
                                                v-model.number="taskForm.duration_minutes"
                                                type="number"
                                                min="1"
                                                max="480"
                                                placeholder="e.g. 30"
                                                :class="{ 'has-err': taskForm.errors.duration_minutes }"
                                            >
                                            <div v-if="taskForm.errors.duration_minutes" class="err">{{ taskForm.errors.duration_minutes }}</div>
                                        </div>
                                    </div>
                                </div>

                                <div v-if="customerContactsForPicker.length > 0" class="form-section">
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Contact (optional)</label>
                                            <select v-model="taskForm.contact_id">
                                                <option :value="null">— no specific contact —</option>
                                                <option v-for="ct in customerContactsForPicker" :key="ct.id" :value="ct.id">{{ ct.name }}</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div v-if="taskForm.type !== 'note'" class="form-section">
                                    <div class="form-row">
                                        <div class="form-field">
                                            <label>{{ scheduleLabelForType(taskForm.type) }} date</label>
                                            <input
                                                v-model="taskForm.due_at"
                                                type="date"
                                                :class="{ 'has-err': taskForm.errors.due_at }"
                                            >
                                            <div v-if="taskForm.errors.due_at" class="err">{{ taskForm.errors.due_at }}</div>
                                        </div>
                                        <div class="form-field">
                                            <label>Time (optional)</label>
                                            <input v-model="taskForm.due_time" type="time">
                                        </div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Assign to</label>
                                            <select v-model="taskForm.assigned_to">
                                                <option :value="null">— default to me —</option>
                                                <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <footer class="slide-over-footer">
                                <button type="button" class="btn btn-secondary" @click="closeTaskForm">Cancel</button>
                                <button type="submit" class="btn btn-primary" :disabled="taskForm.processing">
                                    {{ taskForm.processing ? 'Saving…' : 'Save activity' }}
                                </button>
                            </footer>
                        </form>
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </TransitionRoot>

        <ConfirmModal
            v-model:show="showCompleteActivity"
            :title="completingActivity ? `Complete: ${completingActivity.title}` : 'Complete activity'"
            message=""
            confirm-label="Mark complete"
            variant="primary"
            :loading="completeProcessing"
            @confirm="performCompleteActivity"
        >
            <div class="form-field" style="margin-top: 8px;">
                <label>Outcome / notes (optional)</label>
                <textarea
                    v-model="completeOutcome"
                    rows="3"
                    maxlength="2000"
                    placeholder="What was the result? Any follow-up needed?"
                    style="width: 100%; font: inherit; padding: 8px 10px; border: 1px solid var(--border); border-radius: var(--radius-md);"
                />
            </div>
        </ConfirmModal>

        <ConfirmModal
            v-model:show="showDeleteActivity"
            :title="deletingActivity ? `Delete ${deletingActivity.title}?` : 'Delete activity?'"
            message="This activity will be permanently removed. This cannot be undone."
            confirm-label="Delete"
            variant="danger"
            :loading="deleteActivityProcessing"
            @confirm="performDeleteActivity"
        />

        <!--
          Portal invite credential modal. Surfaces the temp password
          exactly once via the portal_invite flash; the modal closes on
          dismiss and the credentials are never retrievable again — only
          a re-invite issues a fresh password.
        -->
        <Teleport to="body">
            <template v-if="$page.props.flash?.portal_invite">
                <div class="slide-over-backdrop" style="background: rgba(15, 23, 42, .5);" />
                <div
                    role="dialog"
                    aria-modal="true"
                    style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #fff; border: 1px solid var(--border); border-radius: var(--radius-xl); box-shadow: var(--shadow-lg); z-index: 60; width: 460px; max-width: calc(100vw - 32px); padding: 24px; display: flex; flex-direction: column; gap: 14px;"
                >
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div class="h-icon" style="background: var(--success-bg); color: var(--success);">
                            <IconKey :size="16" stroke-width="1.75" />
                        </div>
                        <div>
                            <h3 style="margin: 0; font: 600 16px/1.2 'Inter', sans-serif; color: var(--text-primary);">Portal invite ready</h3>
                            <p style="margin: 2px 0 0; font: 400 12.5px/1.4 'Inter', sans-serif; color: var(--text-secondary);">
                                Copy these credentials — they will not be shown again.
                            </p>
                        </div>
                    </div>

                    <div style="background: var(--neutral-bg); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 14px; display: flex; flex-direction: column; gap: 10px;">
                        <div>
                            <div style="font: 500 11px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary); margin-bottom: 4px;">
                                Email
                            </div>
                            <code style="font: 500 13.5px/1.3 'JetBrains Mono', monospace; color: var(--text-primary); user-select: all;">{{ $page.props.flash.portal_invite.email }}</code>
                        </div>
                        <div>
                            <div style="font: 500 11px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary); margin-bottom: 4px;">
                                Temporary password
                            </div>
                            <code style="font: 500 13.5px/1.3 'JetBrains Mono', monospace; color: var(--text-primary); user-select: all;">{{ $page.props.flash.portal_invite.password }}</code>
                        </div>
                    </div>

                    <p style="margin: 0; font: 400 12.5px/1.45 'Inter', sans-serif; color: var(--text-secondary);">
                        {{ $page.props.flash.portal_invite.message }}
                    </p>

                    <div style="display: flex; justify-content: flex-end;">
                        <button
                            type="button"
                            class="btn btn-primary"
                            @click="router.reload({ only: ['flash'], preserveScroll: true, preserveState: false })"
                        >
                            Done
                        </button>
                    </div>
                </div>
            </template>
        </Teleport>

        <!-- ═══ CONTRACT SLIDE-OVER (add / edit) ═══ -->
        <Teleport to="body">
            <transition name="slide-over">
                <div v-if="showContractForm" class="slide-over">
                    <div class="slide-over-backdrop" @click="showContractForm = false" />
                    <aside class="slide-over-panel contract-slide-over" role="dialog" aria-modal="true">
                        <form class="slide-over-form" @submit.prevent="submitContract">
                            <header class="slide-over-header">
                                <h2>{{ editingContractId ? 'Edit contract' : 'Add contract' }}</h2>
                                <button type="button" class="icon-btn" aria-label="Close" @click="showContractForm = false">
                                    <IconX :size="18" stroke-width="1.75" />
                                </button>
                            </header>
                            <div class="slide-over-body">
                                <div class="form-section">
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Title<span class="req">*</span></label>
                                            <input v-model="contractForm.title" type="text" required maxlength="255" :class="{ 'has-err': contractForm.errors.title }">
                                            <div v-if="contractForm.errors.title" class="err">{{ contractForm.errors.title }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Description</label>
                                            <textarea v-model="contractForm.description" rows="2" maxlength="5000" placeholder="A short summary of what this contract covers." />
                                        </div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <div class="form-row">
                                        <div class="form-field">
                                            <label>Type</label>
                                            <select v-model="contractForm.type">
                                                <option value="service_agreement">Service agreement</option>
                                                <option value="sow">Statement of work</option>
                                                <option value="retainer">Retainer</option>
                                                <option value="nda">NDA</option>
                                                <option value="other">Other</option>
                                            </select>
                                        </div>
                                        <div class="form-field">
                                            <label>Status</label>
                                            <select v-model="contractForm.status">
                                                <option value="draft">Draft</option>
                                                <option value="sent">Sent</option>
                                                <option value="signed">Signed</option>
                                                <option value="countersigned">Countersigned</option>
                                                <option value="expired">Expired</option>
                                                <option value="void">Void</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <div class="form-row">
                                        <div class="form-field">
                                            <label>Start date</label>
                                            <input v-model="contractForm.start_date" type="date">
                                        </div>
                                        <div class="form-field">
                                            <label>Expiry date</label>
                                            <input v-model="contractForm.end_date" type="date" :class="{ 'has-err': contractForm.errors.end_date }">
                                            <div v-if="contractForm.errors.end_date" class="err">{{ contractForm.errors.end_date }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-field">
                                            <label>Signed on</label>
                                            <input v-model="contractForm.signed_at" type="date">
                                        </div>
                                        <div class="form-field">
                                            <label>Contract value</label>
                                            <div style="display: flex; align-items: center; gap: 6px;">
                                                <span style="color: var(--text-tertiary);">£</span>
                                                <input v-model.number="contractForm.value" type="number" step="0.01" min="0" placeholder="Optional" style="flex: 1;">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <label>PDF</label>
                                    <label class="contract-upload-zone">
                                        <input
                                            type="file"
                                            accept="application/pdf"
                                            style="display: none;"
                                            @change="onContractFile"
                                        >
                                        <IconUpload :size="18" stroke-width="1.75" />
                                        <div class="contract-upload-text">
                                            <div class="contract-upload-title">
                                                <template v-if="contractFileName">{{ contractFileName }}</template>
                                                <template v-else>Click to upload PDF</template>
                                            </div>
                                            <div class="contract-upload-sub">PDF only · max 10 MB</div>
                                        </div>
                                    </label>
                                    <div v-if="contractForm.errors.file" class="err">{{ contractForm.errors.file }}</div>
                                </div>

                                <div class="form-section">
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Internal notes</label>
                                            <textarea v-model="contractForm.notes" rows="3" maxlength="2000" placeholder="Not visible to the customer." />
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <footer class="slide-over-footer">
                                <button type="button" class="btn btn-secondary" @click="showContractForm = false">Cancel</button>
                                <button type="submit" class="btn btn-primary" :disabled="contractForm.processing">
                                    <IconCheck :size="14" stroke-width="2" />
                                    {{ contractForm.processing ? 'Saving…' : (editingContractId ? 'Save changes' : 'Add contract') }}
                                </button>
                            </footer>
                        </form>
                    </aside>
                </div>
            </transition>
        </Teleport>

        <ConfirmModal
            v-model:show="showContractDeleteModal"
            :title="contractDeleteTarget ? `Delete '${contractDeleteTarget.title}'?` : 'Delete contract?'"
            :message="contractDeleteMessage"
            confirm-label="Delete"
            variant="danger"
            :loading="contractDeleteProcessing"
            @confirm="performDeleteContract"
        />
    </InternalLayout>
</template>

<style scoped>
.slide-over { position: fixed; inset: 0; z-index: 40; }
.slide-over-form { height: 100%; display: flex; flex-direction: column; }
</style>
