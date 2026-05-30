<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Menu,
    MenuButton,
    MenuItem,
    MenuItems,
} from '@headlessui/vue';
import {
    IconPlus,
    IconSearch,
    IconAdjustmentsHorizontal,
    IconDots,
    IconX,
    IconWorld,
    IconRefresh,
    IconShieldCheck,
    IconCheck,
    IconAlertTriangle,
    IconExternalLink,
    IconChevronDown,
    IconChevronLeft,
    IconChevronRight,
    IconLoader2,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const props = defineProps({
    domains: { type: Object, required: true },
    summary: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
    cloudflare_connected: { type: Boolean, default: false },
});

const breadcrumbs = [{ label: 'Domains' }];

const STATUS_LABEL = {
    active: 'Active',
    expiring_soon: 'Expiring',
    expired: 'Expired',
    parked: 'Parked',
    transferred: 'Transferred',
};
const STATUS_CLASS = {
    active: 'badge-active',
    expiring_soon: 'badge-pending',
    expired: 'badge-overdue',
    parked: 'badge-inactive',
    transferred: 'badge-info',
};
const SSL_LABEL = {
    active: 'Active',
    expiring: 'Expiring',
    expired: 'Expired',
    none: '—',
};
const SSL_CLASS = {
    active: 'badge-active',
    expiring: 'badge-pending',
    expired: 'badge-overdue',
    none: 'badge-inactive',
};

/* ─── Filter bar ─── */
const searchInput = ref(props.filters?.search ?? '');
let searchTimer = null;
watch(searchInput, (v) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        if ((v ?? '') === (props.filters?.search ?? '')) return;
        navigate({ search: v });
    }, 350);
});
function navigate(patch) {
    const q = { ...(props.filters ?? {}), ...patch };
    Object.keys(q).forEach((k) => {
        if (q[k] === '' || q[k] === null || q[k] === undefined) delete q[k];
    });
    router.get('/domains', q, { preserveState: true, preserveScroll: true, replace: true });
}
function setStatus(s) { navigate({ status: s }); }

/* ─── Expiry cell helpers ─── */
function expiryPillClass(d) {
    if (d.days_until_expiry === null) return 'muted';
    if (d.days_until_expiry < 0) return 'red';
    if (d.days_until_expiry <= 7) return 'red';
    if (d.days_until_expiry <= 30) return 'amber';
    return 'muted';
}
function expiryText(d) {
    if (d.days_until_expiry === null) return '—';
    if (d.days_until_expiry < 0) return 'Expired';
    if (d.days_until_expiry === 0) return 'Today';
    if (d.days_until_expiry <= 30) return `${d.days_until_expiry} days`;
    return d.expiry_date_display ?? '—';
}

/* ─── Add / edit slide-over ─── */
const showForm = ref(false);
const editingId = ref(null);
const form = useForm({
    customer_id: null,
    domain: '',
    registrar: '',
    registered_at: '',
    expiry_date: '',
    auto_renew: false,
    cloudflare_zone_id: '',
    notes: '',
});
const customerSearch = ref('');
const filteredCustomers = computed(() => {
    const q = customerSearch.value.trim().toLowerCase();
    if (! q) return props.customers.slice(0, 8);
    return props.customers.filter((c) => c.name.toLowerCase().includes(q)).slice(0, 8);
});

/* ─── WHOIS auto-detect ─── */
const whoisLoading = ref(false);
const whoisHint = ref('');

async function autoDetect() {
    if (! form.domain || whoisLoading.value) return;
    whoisLoading.value = true;
    whoisHint.value = '';

    try {
        const res = await fetch('/domains/whois-lookup', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
            },
            body: JSON.stringify({ domain: form.domain }),
        });

        const data = await res.json();

        // Never overwrite a value the operator already typed — the
        // WHOIS server's idea of "registrar" may be slightly different
        // from what the staff member typed, and a silent overwrite
        // would be a worse UX than an empty field.
        let filledAny = false;
        if (data.registrar && ! form.registrar) {
            form.registrar = data.registrar;
            filledAny = true;
        }
        if (data.expiry_date && ! form.expiry_date) {
            form.expiry_date = data.expiry_date;
            filledAny = true;
        }

        if (data.error) {
            whoisHint.value = 'WHOIS lookup failed — fill the fields manually.';
        } else if (! data.found) {
            whoisHint.value = 'No WHOIS data returned for this domain.';
        } else if (! filledAny) {
            whoisHint.value = 'WHOIS data found but fields are already filled.';
        }
    } catch {
        whoisHint.value = 'Network error — fill the fields manually.';
    } finally {
        whoisLoading.value = false;
    }
}

function openCreate() {
    editingId.value = null;
    form.reset();
    customerSearch.value = '';
    form.clearErrors();
    showForm.value = true;
}
function openEdit(d) {
    editingId.value = d.id;
    form.reset();
    form.customer_id = d.customer_id;
    form.domain = d.domain;
    form.registrar = d.registrar ?? '';
    form.registered_at = d.registered_at ?? '';
    form.expiry_date = d.expiry_date ?? '';
    form.auto_renew = !! d.auto_renew;
    form.cloudflare_zone_id = d.cloudflare_zone_id ?? '';
    form.notes = d.notes ?? '';
    customerSearch.value = d.customer_name ?? '';
    form.clearErrors();
    showForm.value = true;
}
function submit() {
    const onSuccess = () => {
        showForm.value = false;
        form.reset();
        editingId.value = null;
    };
    if (editingId.value) form.put(`/domains/${editingId.value}`, { preserveScroll: true, onSuccess });
    else form.post('/domains', { preserveScroll: true, onSuccess });
}

/* ─── Per-row actions ─── */
function checkNow(id) {
    router.post(`/domains/${id}/check`, {}, { preserveScroll: true });
}
const showDeleteModal = ref(false);
const deleteTarget = ref(null);
const deleteProcessing = ref(false);
function askDelete(d) { deleteTarget.value = d; showDeleteModal.value = true; }
function performDelete() {
    if (! deleteTarget.value) return;
    deleteProcessing.value = true;
    router.delete(`/domains/${deleteTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deleteProcessing.value = false;
            showDeleteModal.value = false;
            deleteTarget.value = null;
        },
    });
}

/* ─── DNS records panel ─── */
const showDns = ref(false);
const dnsLoading = ref(false);
const dnsRows = ref([]);
const dnsConnected = ref(false);
const dnsDomain = ref(null);
async function openDns(d) {
    dnsDomain.value = d;
    dnsRows.value = [];
    dnsConnected.value = false;
    showDns.value = true;
    dnsLoading.value = true;
    try {
        const res = await fetch(`/domains/${d.id}/dns`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const data = await res.json();
        dnsConnected.value = !! data.connected;
        dnsRows.value = data.records ?? [];
    } catch (e) {
        dnsRows.value = [];
    } finally {
        dnsLoading.value = false;
    }
}

/* ─── Pagination ─── */
function go(url) { if (url) router.visit(url, { preserveScroll: true }); }
</script>

<template>
    <Head title="Domains" />

    <InternalLayout title="Domains" :breadcrumbs="breadcrumbs" active-nav="domains">
        <template #topbar-actions>
            <button type="button" class="btn btn-primary" @click="openCreate">
                <IconPlus :size="15" stroke-width="1.75" />
                Add domain
            </button>
        </template>

        <div class="domains">
            <!-- Summary strip -->
            <div class="summary-strip">
                <div class="stat-pill">
                    <span class="d gold" /><strong>{{ summary.total }}</strong>
                    <span class="lbl">total</span><span class="sub">managed domains</span>
                </div>
                <div class="stat-pill">
                    <span class="d amber" /><strong>{{ summary.expiring_30 }}</strong>
                    <span class="lbl">expiring 30d</span>
                </div>
                <div v-if="summary.expiring_7 > 0" class="stat-pill">
                    <span class="d red" /><strong>{{ summary.expiring_7 }}</strong>
                    <span class="lbl">expiring 7d</span>
                </div>
                <div v-if="summary.expired > 0" class="stat-pill">
                    <span class="d red" /><strong>{{ summary.expired }}</strong>
                    <span class="lbl">expired</span>
                </div>
                <div v-if="summary.ssl_issues > 0" class="stat-pill">
                    <span class="d red" /><strong>{{ summary.ssl_issues }}</strong>
                    <span class="lbl">SSL issues</span>
                </div>
                <div v-if="! cloudflare_connected" class="cf-warn">
                    <IconAlertTriangle :size="13" stroke-width="2" />
                    Cloudflare not connected — add CLOUDFLARE_API_TOKEN
                </div>
            </div>

            <!-- Filter bar -->
            <div class="filter-bar">
                <div class="field-search">
                    <span class="search-icon"><IconSearch :size="16" stroke-width="1.75" /></span>
                    <input v-model="searchInput" type="search" placeholder="Search domain or customer…">
                </div>
                <Menu as="div" class="dd-menu">
                    <MenuButton class="dd-btn">
                        <IconAdjustmentsHorizontal :size="16" stroke-width="1.75" />
                        <span>{{ filters.status ? STATUS_LABEL[filters.status] : 'All statuses' }}</span>
                        <IconChevronDown :size="14" class="ch" stroke-width="1.75" />
                    </MenuButton>
                    <MenuItems class="dd-popover">
                        <MenuItem v-slot="{ active }">
                            <button type="button" :class="['dd-option', { active }]" @click="setStatus(null)">All statuses</button>
                        </MenuItem>
                        <MenuItem v-for="s in statuses" :key="s" v-slot="{ active }">
                            <button type="button" :class="['dd-option', { active }]" @click="setStatus(s)">{{ STATUS_LABEL[s] }}</button>
                        </MenuItem>
                    </MenuItems>
                </Menu>
            </div>

            <!-- Domains table -->
            <div class="table-card">
                <table v-if="domains.data.length" class="tbl">
                    <colgroup>
                        <col><col style="width: 180px;"><col style="width: 130px;">
                        <col style="width: 130px;"><col style="width: 110px;">
                        <col style="width: 110px;"><col style="width: 130px;"><col style="width: 48px;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Domain</th>
                            <th>Customer</th>
                            <th>Registrar</th>
                            <th>Expiry</th>
                            <th>SSL</th>
                            <th>Status</th>
                            <th>Last checked</th>
                            <th />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="d in domains.data" :key="d.id">
                            <td>
                                <div class="dm-name">
                                    <span class="dm-mono">{{ d.domain }}</span>
                                    <span v-if="d.has_cloudflare" class="dm-cf" title="Cloudflare-attached">CF</span>
                                    <span v-if="d.is_proxied" class="dm-proxied" title="Proxied through Cloudflare">●</span>
                                </div>
                            </td>
                            <td>
                                <Link v-if="d.customer_id" :href="`/customers/${d.customer_id}`" class="dm-cust">{{ d.customer_name }}</Link>
                                <span v-else class="muted">—</span>
                            </td>
                            <td><span class="dm-reg">{{ d.registrar ?? '—' }}</span></td>
                            <td><span :class="['warn-pill', expiryPillClass(d)]">{{ expiryText(d) }}</span></td>
                            <td><span class="badge badge-sm" :class="SSL_CLASS[d.ssl_status]">{{ SSL_LABEL[d.ssl_status] }}</span></td>
                            <td><span class="badge badge-sm" :class="STATUS_CLASS[d.status]">{{ STATUS_LABEL[d.status] }}</span></td>
                            <td><span class="muted small">{{ d.last_synced_at ?? 'Never' }}</span></td>
                            <td>
                                <Menu as="div" class="dd-menu">
                                    <MenuButton class="icon-btn" aria-label="Actions">
                                        <IconDots :size="16" stroke-width="1.75" />
                                    </MenuButton>
                                    <MenuItems class="dd-popover right-align">
                                        <MenuItem v-slot="{ active }">
                                            <button type="button" :class="['dd-option', { active }]" @click="openEdit(d)">Edit domain</button>
                                        </MenuItem>
                                        <MenuItem v-slot="{ active }">
                                            <button type="button" :class="['dd-option', { active }]" @click="checkNow(d.id)">
                                                <IconRefresh :size="13" stroke-width="1.75" />
                                                Check health now
                                            </button>
                                        </MenuItem>
                                        <MenuItem v-if="d.has_cloudflare" v-slot="{ active }">
                                            <button type="button" :class="['dd-option', { active }]" @click="openDns(d)">View DNS records</button>
                                        </MenuItem>
                                        <div style="height: 1px; background: var(--border-soft); margin: 4px 0;" />
                                        <MenuItem v-slot="{ active }">
                                            <button type="button" :class="['dd-option', { active }]" style="color: var(--danger);" @click="askDelete(d)">
                                                Delete domain
                                            </button>
                                        </MenuItem>
                                    </MenuItems>
                                </Menu>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div v-else class="dm-empty">
                    <IconWorld :size="40" stroke-width="1.5" />
                    <h3>No domains tracked yet</h3>
                    <p>Add your customer domains to surface expiry alerts on the dashboard.</p>
                    <button type="button" class="btn btn-primary" @click="openCreate">
                        <IconPlus :size="14" stroke-width="1.75" /> Add first domain
                    </button>
                </div>

                <div v-if="domains.data.length" class="tbl-foot">
                    <div class="info">Showing <strong>{{ domains.from ?? 0 }} – {{ domains.to ?? 0 }}</strong> of <strong>{{ domains.total }}</strong></div>
                    <div class="right">
                        <button type="button" class="pg-btn" :disabled="! domains.prev_page_url" @click="go(domains.prev_page_url)">
                            <IconChevronLeft :size="14" stroke-width="1.75" /> Previous
                        </button>
                        <button type="button" class="pg-btn" :disabled="! domains.next_page_url" @click="go(domains.next_page_url)">
                            Next <IconChevronRight :size="14" stroke-width="1.75" />
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ ADD / EDIT SLIDE-OVER ═══ -->
        <Teleport to="body">
            <transition name="slide-over">
                <div v-if="showForm" class="slide-over">
                    <div class="slide-over-backdrop" @click="showForm = false" />
                    <aside class="slide-over-panel" style="width: 520px;" role="dialog" aria-modal="true">
                        <form class="slide-over-form" @submit.prevent="submit">
                            <header class="slide-over-header">
                                <h2>{{ editingId ? 'Edit domain' : 'Add domain' }}</h2>
                                <button type="button" class="icon-btn" aria-label="Close" @click="showForm = false">
                                    <IconX :size="18" stroke-width="1.75" />
                                </button>
                            </header>
                            <div class="slide-over-body">
                                <div class="form-section">
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Domain<span class="req">*</span></label>
                                            <!--
                                                Domain + Auto-detect side-by-side. The button
                                                hits /domains/whois-lookup and only fills the
                                                registrar / expiry fields below if they're
                                                currently empty — never overwrites manual input.
                                            -->
                                            <div class="dm-input-row">
                                                <input v-model="form.domain" type="text" required maxlength="255" style="font-family: 'JetBrains Mono', monospace;" placeholder="example.co.uk" :class="{ 'has-err': form.errors.domain }">
                                                <button
                                                    type="button"
                                                    class="btn btn-ghost btn-sm"
                                                    :disabled="!form.domain || whoisLoading"
                                                    @click="autoDetect"
                                                >
                                                    <IconLoader2 v-if="whoisLoading" :size="14" stroke-width="2" class="spin" />
                                                    <IconSearch v-else :size="14" stroke-width="2" />
                                                    {{ whoisLoading ? 'Detecting…' : 'Auto-detect' }}
                                                </button>
                                            </div>
                                            <p v-if="whoisHint" class="muted small">{{ whoisHint }}</p>
                                            <div v-if="form.errors.domain" class="err">{{ form.errors.domain }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Customer<span class="req">*</span></label>
                                            <div class="cust-search">
                                                <IconSearch :size="16" stroke-width="1.75" />
                                                <input v-model="customerSearch" type="search" placeholder="Search customer…">
                                            </div>
                                            <div v-if="customerSearch" class="cust-list" style="margin-top: 6px; max-height: 200px; overflow-y: auto;">
                                                <button
                                                    v-for="c in filteredCustomers"
                                                    :key="c.id"
                                                    type="button"
                                                    class="cust-row"
                                                    :class="{ selected: form.customer_id === c.id }"
                                                    @click="form.customer_id = c.id; customerSearch = c.name"
                                                >
                                                    <div class="meta">
                                                        <div class="nm">{{ c.name }}</div>
                                                        <div v-if="c.city" class="sb">{{ c.city }}</div>
                                                    </div>
                                                </button>
                                            </div>
                                            <div v-if="form.errors.customer_id" class="err">{{ form.errors.customer_id }}</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <div class="form-row">
                                        <div class="form-field">
                                            <label>Registrar</label>
                                            <input v-model="form.registrar" type="text" maxlength="100" placeholder="e.g. Cloudflare, GoDaddy">
                                        </div>
                                        <div class="form-field">
                                            <label>Auto-renew</label>
                                            <button type="button" class="toggle" :class="{ on: form.auto_renew }" aria-label="Toggle auto-renew" @click="form.auto_renew = ! form.auto_renew" />
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-field">
                                            <label>Registered</label>
                                            <input v-model="form.registered_at" type="date">
                                        </div>
                                        <div class="form-field">
                                            <label>Expiry</label>
                                            <input v-model="form.expiry_date" type="date">
                                        </div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <h3>Cloudflare</h3>
                                    <p style="margin: 0 0 10px; font: 400 12px/1.5 'Inter', sans-serif; color: var(--text-tertiary);">
                                        Connect to Cloudflare for automatic health monitoring and DNS lookup.
                                    </p>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Zone ID</label>
                                            <input v-model="form.cloudflare_zone_id" type="text" maxlength="50" placeholder="e.g. abc123def456…" style="font-family: 'JetBrains Mono', monospace;">
                                            <div class="field-help">Cloudflare dashboard → your domain → Overview → API → Zone ID.</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Notes</label>
                                            <textarea v-model="form.notes" rows="2" maxlength="2000" />
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <footer class="slide-over-footer">
                                <button type="button" class="btn btn-secondary" @click="showForm = false">Cancel</button>
                                <button type="submit" class="btn btn-primary" :disabled="form.processing">
                                    <IconCheck :size="14" stroke-width="2" />
                                    {{ form.processing ? 'Saving…' : (editingId ? 'Save changes' : 'Add domain') }}
                                </button>
                            </footer>
                        </form>
                    </aside>
                </div>
            </transition>
        </Teleport>

        <!-- ═══ DNS RECORDS SLIDE-OVER ═══ -->
        <Teleport to="body">
            <transition name="slide-over">
                <div v-if="showDns" class="slide-over">
                    <div class="slide-over-backdrop" @click="showDns = false" />
                    <aside class="slide-over-panel" style="width: 640px;" role="dialog" aria-modal="true">
                        <header class="slide-over-header">
                            <h2>
                                <IconShieldCheck :size="16" stroke-width="1.75" style="vertical-align: -2px;" />
                                DNS records · {{ dnsDomain?.domain }}
                            </h2>
                            <button type="button" class="icon-btn" aria-label="Close" @click="showDns = false">
                                <IconX :size="18" stroke-width="1.75" />
                            </button>
                        </header>
                        <div class="slide-over-body">
                            <div v-if="dnsLoading" class="dm-empty small">Loading…</div>
                            <div v-else-if="! dnsConnected" class="dm-empty small">
                                Cloudflare zone not connected for this domain — add a Zone ID to enable live DNS data.
                            </div>
                            <div v-else-if="dnsRows.length === 0" class="dm-empty small">
                                No DNS records found for this zone.
                            </div>
                            <table v-else class="tbl dns-tbl">
                                <thead>
                                    <tr>
                                        <th style="width: 60px;">Type</th>
                                        <th>Name</th>
                                        <th>Content</th>
                                        <th style="width: 70px;">TTL</th>
                                        <th style="width: 70px;">Proxy</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="r in dnsRows" :key="r.id">
                                        <td><span class="badge badge-sm badge-info">{{ r.type }}</span></td>
                                        <td style="font-family: 'JetBrains Mono', monospace; font-size: 12px;">{{ r.name }}</td>
                                        <td style="font-family: 'JetBrains Mono', monospace; font-size: 12px; word-break: break-word;">{{ r.content }}</td>
                                        <td>{{ r.ttl === 1 ? 'Auto' : r.ttl }}</td>
                                        <td>
                                            <span v-if="r.proxied" class="badge badge-sm badge-active" title="Proxied">●</span>
                                            <span v-else class="muted small">DNS only</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <footer class="slide-over-footer">
                            <button type="button" class="btn btn-secondary" @click="showDns = false">Close</button>
                            <a
                                v-if="dnsDomain?.cloudflare_zone_id"
                                :href="`https://dash.cloudflare.com/?to=/:account/${dnsDomain.domain}/dns`"
                                target="_blank"
                                rel="noopener"
                                class="btn btn-primary"
                            >
                                Edit in Cloudflare
                                <IconExternalLink :size="13" stroke-width="2" />
                            </a>
                        </footer>
                    </aside>
                </div>
            </transition>
        </Teleport>

        <ConfirmModal
            v-model:show="showDeleteModal"
            :title="deleteTarget ? `Delete '${deleteTarget.domain}'?` : 'Delete domain?'"
            :message="deleteTarget ? `This will untrack ${deleteTarget.domain}. The DNS itself isn't touched.` : ''"
            confirm-label="Delete"
            variant="danger"
            :loading="deleteProcessing"
            @confirm="performDelete"
        />
    </InternalLayout>
</template>
