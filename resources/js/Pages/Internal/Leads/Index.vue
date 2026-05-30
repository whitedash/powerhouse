<script setup>
/**
 * Leads — kanban pipeline (default) + list view toggle.
 *
 * Server hands us:
 *   - leads:    full row set (no pagination — kanban needs them all)
 *   - summary:  KPI counts + pipeline value
 *   - staff:    options for assignment + filter
 *   - statuses/sources: form options + dropdown values
 *
 * Status changes go via POST /leads/{id}/status (JSON endpoint).
 * The kanban applies them optimistically — local state flips
 * immediately, then we router.reload() to reconcile.
 */
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    IconPlus, IconSearch, IconX, IconUserPlus, IconLayoutKanban,
    IconList, IconTrophy, IconBan, IconChevronLeft, IconChevronRight,
    IconDots, IconTrash, IconArrowRight,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const props = defineProps({
    leads: { type: Array, required: true },
    summary: { type: Object, required: true },
    staff: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    sources: { type: Array, default: () => [] },
    filters: { type: Object, required: true },
});

/* ─── Status helpers ─── */
const STATUS_LABEL = {
    new: 'New', contacted: 'Contacted', qualified: 'Qualified',
    proposal: 'Proposal', negotiation: 'Negotiation',
    won: 'Won', lost: 'Lost', unresponsive: 'Unresponsive',
};
const SOURCE_LABEL = {
    manual: 'Manual', landing_page: 'Landing page',
    facebook: 'Facebook', google: 'Google',
    referral: 'Referral', email: 'Email', phone: 'Phone',
    event: 'Event', word_of_mouth: 'Word of mouth', other: 'Other',
};
// Kanban column order; won + lost shown collapsed at the right.
const COLUMN_ORDER = ['new', 'contacted', 'qualified', 'proposal', 'negotiation', 'won', 'lost'];

/* ─── View toggle + filters ─── */
const view = ref('pipeline'); // 'pipeline' | 'list'
const search = ref(props.filters.search ?? '');
const source = ref(props.filters.source ?? '');
const assignedToMe = ref(!! props.filters.assigned_to_me);

let searchTimer = null;
function onSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(navigate, 300);
}
function navigate() {
    router.get('/leads', {
        search: search.value || undefined,
        source: source.value || undefined,
        assigned_to_me: assignedToMe.value ? 1 : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}
watch([source, assignedToMe], navigate);
function clearFilters() { search.value = ''; source.value = ''; assignedToMe.value = false; }

/* ─── Group by status for the kanban ─── */
const grouped = computed(() => {
    const buckets = {};
    for (const s of COLUMN_ORDER) buckets[s] = [];
    for (const l of props.leads) {
        if (! buckets[l.status]) buckets[l.status] = [];
        buckets[l.status].push(l);
    }
    return buckets;
});

const columnValue = computed(() => {
    const out = {};
    for (const s of COLUMN_ORDER) {
        out[s] = (grouped.value[s] ?? []).reduce((sum, l) => sum + Number(l.estimated_value || 0), 0);
    }
    return out;
});

/* ─── Won/Lost collapsed by default ─── */
const collapsed = reactive({ won: true, lost: true });
function toggleCollapsed(key) { collapsed[key] = ! collapsed[key]; }

/* ─── Drag and drop status change ─── */
const dragId = ref(null);

function onDragStart(lead) { dragId.value = lead.id; }
function onDragOver(e) { e.preventDefault(); }
function onDrop(toStatus) {
    if (! dragId.value) return;
    const id = dragId.value;
    const card = props.leads.find(l => l.id === id);
    if (! card || card.status === toStatus) {
        dragId.value = null;
        return;
    }
    if (toStatus === 'lost') {
        // Lost requires a reason — open the lost modal first.
        lostLeadId.value = id;
        lostReason.value = '';
        showLostModal.value = true;
        dragId.value = null;
        return;
    }
    flipStatus(id, toStatus);
    dragId.value = null;
}

function flipStatus(id, newStatus, lostReason = null) {
    // Optimistic update — flip locally first, then reconcile
    // with a router.reload() so the server's count + summary
    // numbers re-render once the row lands.
    const card = props.leads.find(l => l.id === id);
    if (card) card.status = newStatus;

    fetch(`/leads/${id}/status`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ status: newStatus, lost_reason: lostReason }),
    }).then(() => router.reload({ only: ['leads', 'summary'] }));
}

/* ─── Lost-reason modal (replaces window.prompt) ─── */
const showLostModal = ref(false);
const lostLeadId = ref(null);
const lostReason = ref('');
function confirmLost() {
    if (! lostReason.value.trim() || ! lostLeadId.value) return;
    flipStatus(lostLeadId.value, 'lost', lostReason.value.trim());
    showLostModal.value = false;
    lostLeadId.value = null;
}

/* ─── Quick-add to New column ─── */
const quickAddOpen = ref(false);
const quickAddName = ref('');
function submitQuickAdd() {
    const parts = quickAddName.value.trim().split(/\s+/);
    if (! parts[0]) return;
    router.post('/leads', {
        first_name: parts[0],
        last_name: parts.slice(1).join(' ') || null,
        status: 'new',
        source: 'manual',
        assigned_to: null,
    }, {
        preserveScroll: true,
        onSuccess: () => { quickAddName.value = ''; quickAddOpen.value = false; },
    });
}

/* ─── New lead slide-over (full form) ─── */
const CHANNEL_DETAIL_LABEL = {
    landing_page: 'Which page / campaign?',
    facebook: 'Which post / ad?',
    google: 'Search term / campaign',
    email: 'Campaign name',
    event: 'Event name',
    referral: 'Referred by',
};

const showCreate = ref(false);
const form = useForm({
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    company: '',
    job_title: '',
    status: 'new',
    source: 'manual',
    source_detail: '',
    assigned_to: null,
    estimated_value: '',
    notes: '',
});
function openCreate() {
    form.reset();
    form.clearErrors();
    showCreate.value = true;
}
function submitCreate() {
    form.post('/leads', {
        preserveScroll: true,
        onSuccess: () => { showCreate.value = false; },
    });
}

const sourceDetailLabel = computed(() => CHANNEL_DETAIL_LABEL[form.source] ?? 'Details');
const showSourceDetail = computed(() => form.source in CHANNEL_DETAIL_LABEL);

/* ─── Delete confirm ─── */
const showDelete = ref(false);
const toDelete = ref(null);
function askDelete(id) { toDelete.value = id; showDelete.value = true; }
function confirmDelete() {
    if (! toDelete.value) return;
    router.delete(`/leads/${toDelete.value}`, {
        preserveScroll: true,
        onFinish: () => { showDelete.value = false; toDelete.value = null; },
    });
}

function money(n) { return `£${Number(n || 0).toLocaleString('en-GB', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}`; }
function initials(name) { return (name || '').split(/\s+/).map(s => s[0]).slice(0, 2).join('').toUpperCase(); }
</script>

<template>
    <Head title="Leads" />

    <InternalLayout
        title="Leads"
        active-nav="leads"
        :breadcrumbs="[{ label: 'Powerhouse', href: '/' }, { label: 'Leads' }]"
    >
        <div class="leads-index">
            <div class="page-actions">
                <div
                    class="my-projects-toggle"
                    :class="{ active: assignedToMe }"
                    role="switch"
                    :aria-checked="assignedToMe"
                    tabindex="0"
                    @click="assignedToMe = ! assignedToMe"
                    @keydown.space.prevent="assignedToMe = ! assignedToMe"
                >
                    <div class="mpt-switch"><div class="mpt-knob" /></div>
                    <span class="mpt-label">My leads</span>
                </div>

                <div class="view-toggle">
                    <button type="button" :class="{ active: view === 'pipeline' }" @click="view = 'pipeline'">
                        <IconLayoutKanban :size="14" stroke-width="2" /> Pipeline
                    </button>
                    <button type="button" :class="{ active: view === 'list' }" @click="view = 'list'">
                        <IconList :size="14" stroke-width="2" /> List
                    </button>
                </div>

                <button type="button" class="btn btn-primary" @click="openCreate">
                    <IconPlus :size="16" stroke-width="2" />
                    New lead
                </button>
            </div>

            <!-- Summary strip -->
            <div class="summary-strip">
                <div class="stat-pill"><span class="d"></span><span class="n">{{ summary.total }}</span><span class="l">Active</span></div>
                <div v-if="summary.new > 0" class="stat-pill"><span class="d amber"></span><span class="n">{{ summary.new }}</span><span class="l">New</span></div>
                <div v-if="summary.qualified_plus > 0" class="stat-pill"><span class="d"></span><span class="n">{{ summary.qualified_plus }}</span><span class="l">Qualified+</span></div>
                <div class="stat-pill"><span class="d"></span><span class="n">{{ money(summary.total_pipeline_value) }}</span><span class="l">Pipeline value</span></div>
                <div v-if="summary.converted_this_month > 0" class="stat-pill"><span class="d green"></span><span class="n">{{ summary.converted_this_month }}</span><span class="l">Converted this month</span></div>
            </div>

            <!-- Filter bar -->
            <div class="filter-bar">
                <div class="filter-search">
                    <IconSearch :size="16" stroke-width="2" />
                    <input v-model="search" type="text" placeholder="Search name, email or company…" @input="onSearch" />
                </div>
                <select v-model="source" class="filter-select">
                    <option value="">All sources</option>
                    <option v-for="s in sources" :key="s" :value="s">{{ SOURCE_LABEL[s] }}</option>
                </select>
                <button v-if="search || source || assignedToMe" type="button" class="ghost-link" @click="clearFilters">
                    <IconX :size="13" stroke-width="2" /> Clear
                </button>
            </div>

            <!-- ─── PIPELINE VIEW ─── -->
            <div v-if="view === 'pipeline'" class="leads-pipeline">
                <div class="kanban-board">
                    <div
                        v-for="status in COLUMN_ORDER"
                        :key="status"
                        class="kanban-column"
                        :class="{ collapsed: collapsed[status] && (status === 'won' || status === 'lost') }"
                        @dragover="onDragOver"
                        @drop="onDrop(status)"
                    >
                        <div class="kanban-column-header" :style="{ borderTopColor: grouped[status][0]?.status_colour ?? 'var(--border)' }">
                            <span class="kanban-column-title">
                                <IconTrophy v-if="status === 'won'" :size="13" stroke-width="2" />
                                <IconBan v-else-if="status === 'lost'" :size="13" stroke-width="2" />
                                {{ STATUS_LABEL[status] }}
                            </span>
                            <span class="kanban-column-count">{{ grouped[status].length }}</span>
                            <button v-if="status === 'won' || status === 'lost'" type="button" class="icon-btn xs" @click="toggleCollapsed(status)">
                                <IconChevronLeft v-if="!collapsed[status]" :size="13" stroke-width="2" />
                                <IconChevronRight v-else :size="13" stroke-width="2" />
                            </button>
                        </div>
                        <div v-if="!collapsed[status] || (status !== 'won' && status !== 'lost')" class="kanban-meta">
                            {{ money(columnValue[status]) }}
                        </div>

                        <div v-if="!collapsed[status] || (status !== 'won' && status !== 'lost')" class="kanban-cards">
                            <Link
                                v-for="lead in grouped[status]"
                                :key="lead.id"
                                :href="`/leads/${lead.id}`"
                                class="lead-card"
                                :style="{ borderLeftColor: lead.status_colour }"
                                draggable="true"
                                @dragstart="onDragStart(lead)"
                            >
                                <div class="lead-card-head">
                                    <span class="av sm" :style="{ background: lead.status_colour }">{{ lead.initials }}</span>
                                    <div class="lead-card-id">
                                        <div class="lead-card-name">{{ lead.name }}</div>
                                        <div v-if="lead.company || lead.job_title" class="lead-card-company">
                                            {{ lead.company }}{{ lead.company && lead.job_title ? ' · ' : '' }}{{ lead.job_title }}
                                        </div>
                                    </div>
                                </div>
                                <span class="lead-source-chip">{{ SOURCE_LABEL[lead.source] }}</span>
                                <div class="lead-card-footer">
                                    <span v-if="lead.estimated_value" class="lead-card-value">{{ money(lead.estimated_value) }}</span>
                                    <span v-else class="muted small">—</span>
                                    <span v-if="lead.assigned_to" class="av xs" :style="{ background: lead.assigned_to.avatar_colour ?? 'var(--text-tertiary)' }" :title="lead.assigned_to.name">
                                        {{ initials(lead.assigned_to.name) }}
                                    </span>
                                </div>
                            </Link>

                            <!-- Quick-add in the New column -->
                            <div v-if="status === 'new'" class="lead-quick-add-wrap">
                                <button v-if="!quickAddOpen" type="button" class="kanban-add" @click="quickAddOpen = true">
                                    <IconPlus :size="13" stroke-width="2" /> Add lead
                                </button>
                                <div v-else class="lead-quick-add">
                                    <input
                                        v-model="quickAddName"
                                        type="text"
                                        placeholder="Name…"
                                        autofocus
                                        @keyup.enter="submitQuickAdd"
                                        @keyup.esc="quickAddOpen = false"
                                    />
                                    <div class="qa-actions">
                                        <button class="btn btn-primary btn-sm" @click="submitQuickAdd">Add</button>
                                        <button class="btn btn-ghost btn-sm" @click="quickAddOpen = false">Cancel</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button v-else type="button" class="kanban-column-expand-btn" @click="toggleCollapsed(status)">
                            {{ STATUS_LABEL[status] }} ({{ grouped[status].length }})
                        </button>
                    </div>
                </div>
            </div>

            <!-- ─── LIST VIEW ─── -->
            <div v-else class="card">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th style="width: 180px;">Company</th>
                            <th style="width: 120px;">Status</th>
                            <th style="width: 130px;">Source</th>
                            <th style="width: 100px;" class="num">Value</th>
                            <th style="width: 80px;">Assigned</th>
                            <th style="width: 120px;">Created</th>
                            <th style="width: 50px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="lead in leads" :key="lead.id">
                            <td>
                                <div class="lead-row-name">
                                    <span class="av xs" :style="{ background: lead.status_colour }">{{ lead.initials }}</span>
                                    <Link :href="`/leads/${lead.id}`" class="tbl-link">{{ lead.name }}</Link>
                                </div>
                            </td>
                            <td>{{ lead.company ?? '—' }}</td>
                            <td><span class="badge" :style="{ background: lead.status_colour + '20', color: lead.status_colour, borderColor: lead.status_colour + '40' }">{{ STATUS_LABEL[lead.status] }}</span></td>
                            <td><span class="lead-source-chip">{{ SOURCE_LABEL[lead.source] }}</span></td>
                            <td class="num">{{ lead.estimated_value ? money(lead.estimated_value) : '—' }}</td>
                            <td>
                                <span v-if="lead.assigned_to" class="av xs" :style="{ background: lead.assigned_to.avatar_colour ?? 'var(--text-tertiary)' }" :title="lead.assigned_to.name">
                                    {{ initials(lead.assigned_to.name) }}
                                </span>
                                <span v-else class="muted">—</span>
                            </td>
                            <td class="muted small">{{ lead.created_at_diff }}</td>
                            <td>
                                <div class="row-actions">
                                    <Link :href="`/leads/${lead.id}`" class="icon-btn xs" title="View">
                                        <IconArrowRight :size="14" stroke-width="2" />
                                    </Link>
                                    <button type="button" class="icon-btn xs danger" title="Delete" @click="askDelete(lead.id)">
                                        <IconTrash :size="13" stroke-width="2" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="leads.length === 0">
                            <td colspan="8" class="muted center">
                                <IconUserPlus :size="32" stroke-width="1.5" />
                                <div>No leads yet. <button class="ghost-link inline" @click="openCreate">Add one</button>.</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- New lead slide-over -->
        <Teleport to="body">
            <div v-if="showCreate" class="slide-over-overlay" @click.self="showCreate = false">
                <div class="slide-over" style="width: 480px;">
                    <div class="slide-over-head">
                        <h2>New lead</h2>
                        <button type="button" class="icon-btn" @click="showCreate = false">
                            <IconX :size="18" stroke-width="2" />
                        </button>
                    </div>
                    <form @submit.prevent="submitCreate" class="slide-over-body">
                        <div class="form-row-2">
                            <div class="form-section">
                                <label class="form-label">First name <span class="req">*</span></label>
                                <input v-model="form.first_name" type="text" class="form-input" required maxlength="100" />
                                <p v-if="form.errors.first_name" class="form-error">{{ form.errors.first_name }}</p>
                            </div>
                            <div class="form-section">
                                <label class="form-label">Last name</label>
                                <input v-model="form.last_name" type="text" class="form-input" maxlength="100" />
                            </div>
                        </div>

                        <div class="form-row-2">
                            <div class="form-section">
                                <label class="form-label">Email</label>
                                <input v-model="form.email" type="email" class="form-input" maxlength="255" />
                            </div>
                            <div class="form-section">
                                <label class="form-label">Phone</label>
                                <input v-model="form.phone" type="text" class="form-input" maxlength="50" />
                            </div>
                        </div>

                        <div class="form-row-2">
                            <div class="form-section">
                                <label class="form-label">Company</label>
                                <input v-model="form.company" type="text" class="form-input" maxlength="255" />
                            </div>
                            <div class="form-section">
                                <label class="form-label">Job title</label>
                                <input v-model="form.job_title" type="text" class="form-input" maxlength="255" />
                            </div>
                        </div>

                        <div class="form-row-2">
                            <div class="form-section">
                                <label class="form-label">Status</label>
                                <select v-model="form.status" class="form-input">
                                    <option v-for="s in statuses" :key="s" :value="s">{{ STATUS_LABEL[s] }}</option>
                                </select>
                            </div>
                            <div class="form-section">
                                <label class="form-label">Estimated value (£)</label>
                                <input v-model="form.estimated_value" type="number" min="0" step="0.01" class="form-input" />
                            </div>
                        </div>

                        <div class="form-section">
                            <label class="form-label">Assigned to</label>
                            <select v-model="form.assigned_to" class="form-input">
                                <option :value="null">Unassigned</option>
                                <option v-for="s in staff" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </select>
                        </div>

                        <div class="form-section">
                            <label class="form-label">Source</label>
                            <div class="channel-grid">
                                <button
                                    v-for="s in sources"
                                    :key="s"
                                    type="button"
                                    class="channel-pill"
                                    :class="{ active: form.source === s }"
                                    @click="form.source = s"
                                >{{ SOURCE_LABEL[s] }}</button>
                            </div>
                            <div v-if="showSourceDetail" class="form-section" style="margin-top: 10px;">
                                <label class="form-label">{{ sourceDetailLabel }}</label>
                                <input v-model="form.source_detail" type="text" class="form-input" maxlength="255" />
                            </div>
                        </div>

                        <div class="form-section">
                            <label class="form-label">Notes</label>
                            <textarea v-model="form.notes" class="form-input" rows="3" maxlength="5000" placeholder="Initial conversation details, decision criteria, etc." />
                        </div>
                    </form>
                    <div class="slide-over-foot">
                        <button type="button" class="btn btn-ghost" @click="showCreate = false">Cancel</button>
                        <button type="button" class="btn btn-primary" :disabled="form.processing" @click="submitCreate">
                            {{ form.processing ? 'Adding…' : 'Add lead' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Lost modal — replaces window.prompt -->
        <Teleport to="body">
            <div v-if="showLostModal" class="slide-over-overlay" @click.self="showLostModal = false">
                <div class="slide-over" style="width: 440px;">
                    <div class="slide-over-head">
                        <h2>Mark lead as lost</h2>
                        <button type="button" class="icon-btn" @click="showLostModal = false">
                            <IconX :size="18" stroke-width="2" />
                        </button>
                    </div>
                    <form @submit.prevent="confirmLost" class="slide-over-body">
                        <div class="form-section">
                            <label class="form-label">Why was this lead lost?</label>
                            <textarea
                                v-model="lostReason"
                                class="form-input"
                                rows="3"
                                maxlength="1000"
                                placeholder="e.g. Went with a competitor / out of budget / unresponsive after follow-up"
                                autofocus
                            />
                            <p class="muted small">Recorded against the lead for future reporting.</p>
                        </div>
                    </form>
                    <div class="slide-over-foot">
                        <button type="button" class="btn btn-ghost" @click="showLostModal = false">Cancel</button>
                        <button type="button" class="btn btn-primary" :disabled="!lostReason.trim()" @click="confirmLost">Mark as lost</button>
                    </div>
                </div>
            </div>
        </Teleport>

        <ConfirmModal
            v-model:show="showDelete"
            variant="danger"
            title="Delete this lead?"
            message="The lead and its pipeline history will be removed. Any tasks or notes hung off the lead will be detached but not deleted."
            confirm-label="Delete lead"
            @confirm="confirmDelete"
        />
    </InternalLayout>
</template>
