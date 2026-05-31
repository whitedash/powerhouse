<script setup>
/**
 * Suppliers — the vendor register landing page.
 *
 * Server hands us:
 *   - suppliers: paginated, server-mapped slim payload
 *   - summary:   total / active / unsynced counts
 *   - filters:   echoed-back filter state
 *   - types, expense_categories: enum lists for the form + filters
 *
 * The QuickBooks block on the slide-over is display-only — qbo_vendor_id
 * and qbo_sync_status are populated by a future sync sprint, never
 * written from this form.
 */
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    IconPlus, IconX, IconChevronLeft, IconChevronRight,
    IconDeviceDesktop, IconServer, IconSpeakerphone, IconWorld,
    IconCoin, IconBolt, IconBriefcase, IconBuildingStore,
    IconBuildingFactory2, IconDots, IconPencil, IconTrash,
    IconCircleOff, IconChevronDown, IconBuildingBank,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const props = defineProps({
    suppliers: { type: Object, required: true },
    summary: { type: Object, required: true },
    filters: { type: Object, required: true },
    types: { type: Array, default: () => [] },
    expense_categories: { type: Array, default: () => [] },
});

/* ─── Type metadata ─── */
const TYPE_META = {
    software:              { label: 'Software',              icon: IconDeviceDesktop, tone: 'info' },
    hosting:               { label: 'Hosting',               icon: IconServer,        tone: 'teal' },
    marketing:             { label: 'Marketing',             icon: IconSpeakerphone,  tone: 'purple' },
    domain_registrar:      { label: 'Domain registrar',      icon: IconWorld,         tone: 'info' },
    finance:               { label: 'Finance',               icon: IconCoin,          tone: 'gold' },
    utilities:             { label: 'Utilities',             icon: IconBolt,          tone: 'amber' },
    professional_services: { label: 'Professional services', icon: IconBriefcase,     tone: 'muted' },
    other:                 { label: 'Other',                 icon: IconBuildingStore, tone: 'muted' },
};
function typeMeta(t) { return TYPE_META[t] ?? TYPE_META.other; }
function typeLabel(t) { return typeMeta(t).label; }

/* ─── Expense category labels (mirror the expenses enum) ─── */
const CATEGORY_LABEL = {
    referral_commission: 'Referral commission',
    software: 'Software', hosting: 'Hosting', travel: 'Travel',
    office: 'Office', marketing: 'Marketing', advertising: 'Advertising',
    equipment: 'Equipment', other: 'Other',
};
function categoryLabel(c) { return CATEGORY_LABEL[c] ?? c; }

/* ─── QBO status chip ─── */
const QBO_META = {
    not_synced: { label: 'Not synced', cls: 'qbo-grey' },
    synced:     { label: 'In QBO',     cls: 'qbo-green' },
    error:      { label: 'Sync error', cls: 'qbo-red' },
    excluded:   { label: 'Excluded',   cls: 'qbo-grey' },
};
function qboMeta(s) { return QBO_META[s] ?? QBO_META.not_synced; }

/* ─── Filters ─── */
const search = ref(props.filters.search ?? '');
const type = ref(props.filters.type ?? '');
const activeOnly = ref(props.filters.active_only ?? false);

let searchTimer = null;
function navigate() {
    router.get('/suppliers', {
        search: search.value || undefined,
        type: type.value || undefined,
        active_only: activeOnly.value ? 1 : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}
function onSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(navigate, 300);
}
function clearFilters() {
    search.value = '';
    type.value = '';
    activeOnly.value = false;
    navigate();
}

/* ─── Row dropdown menu ─── */
const openMenuId = ref(null);
function toggleMenu(id) { openMenuId.value = openMenuId.value === id ? null : id; }
function closeMenu() { openMenuId.value = null; }

/* ─── Add / Edit slide-over ─── */
const showForm = ref(false);
const editingId = ref(null);
const showQbo = ref(false);
const qboDisplay = ref({ vendor_id: null, sync_status: 'not_synced' });

const form = useForm({
    name: '',
    type: 'other',
    contact_name: '',
    email: '',
    phone: '',
    website: '',
    account_number: '',
    payment_terms: '',
    default_expense_category: null,
    default_vat_rate: 20,
    notes: '',
    is_active: true,
});

function openCreate() {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    showQbo.value = false;
    qboDisplay.value = { vendor_id: null, sync_status: 'not_synced' };
    showForm.value = true;
}
function openEdit(s) {
    closeMenu();
    editingId.value = s.id;
    form.name = s.name;
    form.type = s.type;
    form.contact_name = s.contact_name ?? '';
    form.email = s.email ?? '';
    form.phone = s.phone ?? '';
    form.website = s.website ?? '';
    form.account_number = s.account_number ?? '';
    form.payment_terms = s.payment_terms ?? '';
    form.default_expense_category = s.default_expense_category ?? null;
    form.default_vat_rate = Number(s.default_vat_rate ?? 20);
    form.notes = s.notes ?? '';
    form.is_active = s.is_active;
    form.clearErrors();
    showQbo.value = false;
    qboDisplay.value = { vendor_id: s.qbo_vendor_id ?? null, sync_status: s.qbo_sync_status ?? 'not_synced' };
    showForm.value = true;
}

function submit() {
    const opts = { preserveScroll: true, onSuccess: () => { showForm.value = false; } };
    if (editingId.value) {
        form.put(`/suppliers/${editingId.value}`, opts);
    } else {
        form.post('/suppliers', opts);
    }
}

/* ─── Deactivate / reactivate (quick toggle via update) ───────────
 * We send only the required fields + is_active. update() validates
 * nullable fields as "sometimes present", so the omitted contact /
 * billing / notes columns are left untouched. */
function deactivate(s) {
    closeMenu();
    router.put(`/suppliers/${s.id}`, {
        name: s.name,
        type: s.type,
        default_expense_category: s.default_expense_category,
        default_vat_rate: s.default_vat_rate,
        is_active: !s.is_active,
    }, { preserveScroll: true });
}

/* ─── Delete ─── */
const showDelete = ref(false);
const toDelete = ref(null);
function askDelete(s) { closeMenu(); toDelete.value = s; showDelete.value = true; }
function confirmDelete() {
    if (! toDelete.value) return;
    router.delete(`/suppliers/${toDelete.value.id}`, {
        preserveScroll: true,
        onFinish: () => { showDelete.value = false; toDelete.value = null; },
    });
}

function go(url) { if (url) router.visit(url, { preserveScroll: true, preserveState: true }); }
</script>

<template>
    <Head title="Suppliers" />

    <InternalLayout
        title="Suppliers"
        active-nav="suppliers"
        :breadcrumbs="[{ label: 'Powerhouse', href: '/' }, { label: 'Suppliers' }]"
    >
        <div class="suppliers-list" @click="closeMenu">
            <div class="page-actions">
                <button type="button" class="btn btn-primary" @click="openCreate">
                    <IconPlus :size="16" stroke-width="2" />
                    New supplier
                </button>
            </div>

            <!-- ─── Summary strip ─── -->
            <div class="summary-strip">
                <div class="stat-pill">
                    <span class="d blue"></span>
                    <span class="n">{{ summary.total }}</span>
                    <span class="l">Total</span>
                </div>
                <div class="stat-pill">
                    <span class="d green"></span>
                    <span class="n">{{ summary.active }}</span>
                    <span class="l">Active</span>
                </div>
                <div v-if="summary.unsynced > 0" class="stat-pill">
                    <span class="d grey"></span>
                    <span class="n">{{ summary.unsynced }}</span>
                    <span class="l">Not in QBO</span>
                </div>
            </div>

            <!-- ─── Filter bar ─── -->
            <div class="filter-bar sup-filters">
                <input
                    v-model="search"
                    type="search"
                    class="filter-select grow"
                    placeholder="Search name or email…"
                    @input="onSearch"
                />
                <select v-model="type" class="filter-select" @change="navigate">
                    <option value="">All types</option>
                    <option v-for="t in types" :key="t" :value="t">{{ typeLabel(t) }}</option>
                </select>
                <label class="active-toggle">
                    <input type="checkbox" v-model="activeOnly" @change="navigate" />
                    <span>Active only</span>
                </label>
                <button type="button" class="ghost-link" @click="clearFilters">
                    <IconX :size="13" stroke-width="2" />
                    Clear
                </button>
            </div>

            <!-- ─── Table ─── -->
            <div class="card">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th style="width: 160px;">Type</th>
                            <th style="width: 180px;">Email</th>
                            <th style="width: 130px;">Account no.</th>
                            <th style="width: 150px;">Default category</th>
                            <th style="width: 90px;" class="num">Expenses</th>
                            <th style="width: 110px;">QBO</th>
                            <th style="width: 50px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in suppliers.data" :key="s.id">
                            <td>
                                <Link :href="`/suppliers?search=${encodeURIComponent(s.name)}`" class="sup-name">{{ s.name }}</Link>
                                <span v-if="!s.is_active" class="badge badge-inactive badge-sm" style="margin-left: 8px;">Inactive</span>
                            </td>
                            <td>
                                <span class="type-badge" :class="`tone-${typeMeta(s.type).tone}`">
                                    <component :is="typeMeta(s.type).icon" :size="13" stroke-width="2" />
                                    {{ typeLabel(s.type) }}
                                </span>
                            </td>
                            <td>
                                <a v-if="s.email" :href="`mailto:${s.email}`" class="sup-email">{{ s.email }}</a>
                                <span v-else class="muted">—</span>
                            </td>
                            <td>
                                <span v-if="s.account_number" class="sup-acct">{{ s.account_number }}</span>
                                <span v-else class="muted">—</span>
                            </td>
                            <td>
                                <span v-if="s.default_expense_category" class="cat-pill">{{ categoryLabel(s.default_expense_category) }}</span>
                                <span v-else class="muted">—</span>
                            </td>
                            <td class="num">
                                <span class="exp-count">{{ s.expenses_count }}</span>
                            </td>
                            <td>
                                <span class="qbo-chip" :class="qboMeta(s.qbo_sync_status).cls">{{ qboMeta(s.qbo_sync_status).label }}</span>
                            </td>
                            <td>
                                <div class="row-menu" @click.stop>
                                    <button type="button" class="icon-btn xs" title="Actions" @click="toggleMenu(s.id)">
                                        <IconDots :size="16" stroke-width="2" />
                                    </button>
                                    <div v-if="openMenuId === s.id" class="menu-pop">
                                        <button type="button" @click="openEdit(s)">
                                            <IconPencil :size="14" stroke-width="2" /> Edit
                                        </button>
                                        <button type="button" @click="deactivate(s)">
                                            <IconCircleOff :size="14" stroke-width="2" /> {{ s.is_active ? 'Deactivate' : 'Reactivate' }}
                                        </button>
                                        <button type="button" class="danger" @click="askDelete(s)">
                                            <IconTrash :size="14" stroke-width="2" /> Delete
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="suppliers.data.length === 0">
                            <td colspan="8" class="muted center">
                                No suppliers yet. <button class="ghost-link inline" @click="openCreate">Add the first one</button>.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ─── Pagination ─── -->
            <div v-if="suppliers.data.length > 0" class="pg-foot">
                <span class="pg-info">
                    Showing <strong>{{ suppliers.from }}–{{ suppliers.to }}</strong> of <strong>{{ suppliers.total }}</strong>
                </span>
                <div class="pg-buttons">
                    <button class="pg-btn" :disabled="!suppliers.prev_page_url" @click="go(suppliers.prev_page_url)">
                        <IconChevronLeft :size="14" stroke-width="2" /> Previous
                    </button>
                    <button class="pg-btn" :disabled="!suppliers.next_page_url" @click="go(suppliers.next_page_url)">
                        Next <IconChevronRight :size="14" stroke-width="2" />
                    </button>
                </div>
            </div>
        </div>

        <!-- ─── Add/Edit slide-over ─── -->
        <Teleport to="body">
            <div v-if="showForm" class="slide-over-overlay" @click.self="showForm = false">
                <div class="slide-over suppliers-form" style="width: 520px;">
                    <div class="slide-over-head">
                        <h2>{{ editingId ? 'Edit supplier' : 'New supplier' }}</h2>
                        <button type="button" class="icon-btn" @click="showForm = false">
                            <IconX :size="18" stroke-width="2" />
                        </button>
                    </div>
                    <form @submit.prevent="submit" class="slide-over-body">
                        <!-- BASIC INFO -->
                        <div class="sec-head">Basic info</div>
                        <div class="form-section">
                            <label class="form-label">Name <span class="req">*</span></label>
                            <input v-model="form.name" type="text" class="form-input" required maxlength="255" placeholder="e.g. Cloudflare" />
                            <p v-if="form.errors.name" class="form-error">{{ form.errors.name }}</p>
                        </div>
                        <div class="form-section">
                            <label class="form-label">Type</label>
                            <select v-model="form.type" class="form-input">
                                <option v-for="t in types" :key="t" :value="t">{{ typeLabel(t) }}</option>
                            </select>
                        </div>

                        <!-- CONTACT -->
                        <div class="sec-head">Contact</div>
                        <div class="form-row-2">
                            <div class="form-section">
                                <label class="form-label">Contact name</label>
                                <input v-model="form.contact_name" type="text" class="form-input" maxlength="255" />
                            </div>
                            <div class="form-section">
                                <label class="form-label">Email</label>
                                <input v-model="form.email" type="email" class="form-input" maxlength="255" />
                                <p v-if="form.errors.email" class="form-error">{{ form.errors.email }}</p>
                            </div>
                        </div>
                        <div class="form-row-2">
                            <div class="form-section">
                                <label class="form-label">Phone</label>
                                <input v-model="form.phone" type="text" class="form-input" maxlength="50" />
                            </div>
                            <div class="form-section">
                                <label class="form-label">Website</label>
                                <input v-model="form.website" type="url" class="form-input" maxlength="500" placeholder="https://" />
                                <p v-if="form.errors.website" class="form-error">{{ form.errors.website }}</p>
                            </div>
                        </div>

                        <!-- BILLING -->
                        <div class="sec-head">Billing</div>
                        <div class="form-section">
                            <label class="form-label">Account number</label>
                            <input v-model="form.account_number" type="text" class="form-input" maxlength="100" />
                            <p class="field-help">Your reference number with this supplier.</p>
                        </div>
                        <div class="form-section">
                            <label class="form-label">Payment terms</label>
                            <input v-model="form.payment_terms" type="text" class="form-input" maxlength="100" placeholder="e.g. Net 30" />
                            <p class="field-help">e.g. Net 30, Monthly DD.</p>
                        </div>

                        <!-- DEFAULTS -->
                        <div class="sec-head">Defaults <span class="sec-sub">auto-fill on expense creation</span></div>
                        <div class="form-section">
                            <label class="form-label">Default expense category</label>
                            <select v-model="form.default_expense_category" class="form-input">
                                <option :value="null">—</option>
                                <option v-for="c in expense_categories" :key="c" :value="c">{{ categoryLabel(c) }}</option>
                            </select>
                        </div>
                        <div class="form-section">
                            <label class="form-label">Default VAT rate (%)</label>
                            <div class="vat-row">
                                <button type="button" class="pill" :class="{ active: Number(form.default_vat_rate) === 0 }" @click="form.default_vat_rate = 0">0</button>
                                <button type="button" class="pill" :class="{ active: Number(form.default_vat_rate) === 5 }" @click="form.default_vat_rate = 5">5</button>
                                <button type="button" class="pill" :class="{ active: Number(form.default_vat_rate) === 20 }" @click="form.default_vat_rate = 20">20</button>
                                <input v-model="form.default_vat_rate" type="number" min="0" max="100" step="0.01" class="form-input sm" />
                            </div>
                            <p v-if="form.errors.default_vat_rate" class="form-error">{{ form.errors.default_vat_rate }}</p>
                        </div>

                        <!-- NOTES -->
                        <div class="form-section">
                            <label class="form-label">Notes</label>
                            <textarea v-model="form.notes" class="form-input" rows="2" maxlength="2000"></textarea>
                        </div>

                        <!-- QUICKBOOKS (collapsed) -->
                        <div class="qbo-block">
                            <button type="button" class="qbo-toggle" @click="showQbo = !showQbo">
                                <IconBuildingBank :size="15" stroke-width="2" />
                                <span>QuickBooks</span>
                                <IconChevronDown :size="15" stroke-width="2" class="chev" :class="{ open: showQbo }" />
                            </button>
                            <div v-if="showQbo" class="qbo-body">
                                <div class="form-section">
                                    <label class="form-label">QBO Vendor ID</label>
                                    <input :value="qboDisplay.vendor_id" type="text" class="form-input" readonly disabled placeholder="Populated automatically when QBO sync is built" />
                                </div>
                                <div class="form-section">
                                    <label class="form-label">Sync status</label>
                                    <div><span class="qbo-chip" :class="qboMeta(qboDisplay.sync_status).cls">{{ qboMeta(qboDisplay.sync_status).label }}</span></div>
                                </div>
                                <p class="field-help">QuickBooks sync will be configured in a future sprint.</p>
                            </div>
                        </div>
                    </form>
                    <div class="slide-over-foot">
                        <button type="button" class="btn btn-ghost" @click="showForm = false">Cancel</button>
                        <button type="button" class="btn btn-primary" :disabled="form.processing" @click="submit">
                            {{ form.processing ? 'Saving…' : (editingId ? 'Save' : 'Create supplier') }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <ConfirmModal
            v-model:show="showDelete"
            variant="danger"
            title="Delete supplier?"
            :message="`${toDelete?.name ?? 'This supplier'} will be permanently removed. Suppliers with linked expenses can't be deleted — deactivate instead.`"
            confirm-label="Delete supplier"
            @confirm="confirmDelete"
        />
    </InternalLayout>
</template>
