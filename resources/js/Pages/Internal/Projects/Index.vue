<script setup>
/**
 * Projects index — the card-grid landing page for the PM feature.
 *
 * Server hands us:
 *   - projects: paginated list (each entry already mapped server-side
 *     by ProjectController::mapProject — slim payload for the card)
 *   - summary:  KPI counts (total, active, on_hold, overdue)
 *   - filters:  echoed-back current filter state
 *   - customers, staff: dropdown options for the New-project slide-over
 *
 * Everything mutating goes through useForm + router so flash messages
 * land in the global ToastContainer.
 */
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    IconPlus, IconSearch, IconX,
    IconChevronLeft, IconChevronRight, IconLayoutKanban,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';

const props = defineProps({
    projects: { type: Object, required: true },
    summary: { type: Object, required: true },
    filters: { type: Object, required: true },
    customers: { type: Array, default: () => [] },
    staff: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    priorities: { type: Array, default: () => [] },
});

/* ─── Filters ─── */
const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
const customerId = ref(props.filters.customer_id ?? '');
const priority = ref(props.filters.priority ?? '');
const assignedToMe = ref(!! props.filters.assigned_to_me);

// 300ms debounce on the search input so a busy typist doesn't
// hammer the index endpoint on every keystroke.
let searchTimer = null;
function onSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(navigate, 350);
}

function navigate() {
    router.get('/projects', {
        search: search.value || undefined,
        status: status.value || undefined,
        customer_id: customerId.value || undefined,
        priority: priority.value || undefined,
        assigned_to_me: assignedToMe.value ? 1 : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

watch([status, customerId, priority, assignedToMe], navigate);

function clearFilters() {
    search.value = '';
    status.value = '';
    customerId.value = '';
    priority.value = '';
    assignedToMe.value = false;
    navigate();
}

const hasFilters = computed(() => search.value || status.value || customerId.value || priority.value || assignedToMe.value);

/* ─── Status labels (display) ─── */
const STATUS_LABEL = {
    planning: 'Planning',
    active: 'Active',
    on_hold: 'On hold',
    completed: 'Completed',
    cancelled: 'Cancelled',
};
function statusLabel(s) { return STATUS_LABEL[s] ?? s; }

const PRIORITY_LABEL = {
    low: 'Low', medium: 'Medium', high: 'High', urgent: 'Urgent',
};
function priorityLabel(p) { return PRIORITY_LABEL[p] ?? p; }

const PRESET_COLOURS = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899', '#0EA5E9', '#64748B'];

/* ─── New project slide-over ─── */
const showCreate = ref(false);
const form = useForm({
    title: '',
    description: '',
    customer_id: null,
    status: 'planning',
    priority: 'medium',
    colour: '#3B82F6',
    start_date: '',
    due_date: '',
    budget: '',
    hourly_rate: '',
    project_lead: null,
    member_ids: [],
});

function openCreate() {
    form.reset();
    form.clearErrors();
    showCreate.value = true;
}
function closeCreate() { showCreate.value = false; }

function submit() {
    form.post('/projects', {
        preserveScroll: true,
        onSuccess: () => { closeCreate(); },
    });
}

/* ─── Customer search inside slide-over ─── */
const customerSearch = ref('');
const filteredCustomers = computed(() => {
    const q = customerSearch.value.trim().toLowerCase();
    if (! q) return props.customers.slice(0, 8);
    return props.customers.filter(c => c.name.toLowerCase().includes(q)).slice(0, 8);
});
const selectedCustomer = computed(() => props.customers.find(c => c.id === form.customer_id) ?? null);

function pickCustomer(c) {
    form.customer_id = c.id;
    customerSearch.value = '';
}
function clearCustomer() { form.customer_id = null; }

/* ─── Member chips ─── */
function toggleMember(uid) {
    const i = form.member_ids.indexOf(uid);
    if (i >= 0) form.member_ids.splice(i, 1);
    else form.member_ids.push(uid);
}

const selectedMembers = computed(() => form.member_ids.map(id => props.staff.find(s => s.id === id)).filter(Boolean));

/* ─── Pagination links ─── */
const prevLink = computed(() => props.projects.prev_page_url);
const nextLink = computed(() => props.projects.next_page_url);
function go(url) { if (url) router.visit(url, { preserveScroll: true, preserveState: true }); }
</script>

<template>
    <Head title="Projects" />

    <InternalLayout
        title="Projects"
        active-nav="projects"
        :breadcrumbs="[{ label: 'Powerhouse', href: '/' }, { label: 'Projects' }]"
    >
        <div class="projects-list">
            <!-- ─── Topbar actions ─── -->
            <div class="page-actions">
                <label class="toggle" :class="{ on: assignedToMe }">
                    <input type="checkbox" v-model="assignedToMe" />
                    <span>My projects only</span>
                </label>
                <button type="button" class="btn btn-primary" @click="openCreate">
                    <IconPlus :size="16" stroke-width="2" />
                    New project
                </button>
            </div>

            <!-- ─── Summary strip ─── -->
            <div class="summary-strip">
                <div class="stat-pill">
                    <span class="d"></span>
                    <span class="n">{{ summary.total }}</span>
                    <span class="l">Total</span>
                </div>
                <div class="stat-pill" v-if="summary.active > 0">
                    <span class="d green"></span>
                    <span class="n">{{ summary.active }}</span>
                    <span class="l">Active</span>
                </div>
                <div class="stat-pill" v-if="summary.on_hold > 0">
                    <span class="d amber"></span>
                    <span class="n">{{ summary.on_hold }}</span>
                    <span class="l">On hold</span>
                </div>
                <div class="stat-pill" v-if="summary.overdue > 0">
                    <span class="d red"></span>
                    <span class="n">{{ summary.overdue }}</span>
                    <span class="l">Overdue</span>
                </div>
            </div>

            <!-- ─── Filter bar ─── -->
            <div class="filter-bar">
                <div class="filter-search">
                    <IconSearch :size="16" stroke-width="2" />
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search projects…"
                        @input="onSearch"
                    />
                </div>

                <div class="filter-tabs">
                    <button
                        type="button"
                        class="filter-tab"
                        :class="{ active: status === '' }"
                        @click="status = ''"
                    >All</button>
                    <button
                        v-for="s in statuses"
                        :key="s"
                        type="button"
                        class="filter-tab"
                        :class="{ active: status === s }"
                        @click="status = s"
                    >{{ statusLabel(s) }}</button>
                </div>

                <select v-model="customerId" class="filter-select">
                    <option value="">All customers</option>
                    <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>

                <select v-model="priority" class="filter-select">
                    <option value="">All priorities</option>
                    <option v-for="p in priorities" :key="p" :value="p">{{ priorityLabel(p) }}</option>
                </select>

                <button v-if="hasFilters" type="button" class="ghost-link" @click="clearFilters">
                    <IconX :size="14" stroke-width="2" />
                    Clear
                </button>
            </div>

            <!-- ─── Project cards grid ─── -->
            <div v-if="projects.data.length > 0" class="project-grid">
                <Link
                    v-for="p in projects.data"
                    :key="p.id"
                    :href="`/projects/${p.id}`"
                    class="project-card"
                    :class="{ overdue: p.is_overdue, 'on-hold': p.status === 'on_hold' }"
                >
                    <div class="project-colour-bar" :style="{ background: p.colour }"></div>
                    <div class="project-card-body">
                        <div class="project-card-head">
                            <div class="project-title-line">
                                <span class="priority-dot" :class="`pri-${p.priority}`" :title="priorityLabel(p.priority)"></span>
                                <span class="project-title">{{ p.title }}</span>
                            </div>
                        </div>

                        <div class="project-meta">
                            <span v-if="p.customer_name">{{ p.customer_name }}</span>
                            <span v-else class="muted">Internal project</span>
                            <span v-if="p.due_date" class="dot-sep">·</span>
                            <span v-if="p.due_date" :class="{ overdue: p.is_overdue }">
                                Due {{ p.due_date }}
                            </span>
                        </div>

                        <div class="project-progress-section">
                            <div class="project-progress-bar">
                                <div
                                    class="project-progress-fill"
                                    :style="{ width: p.progress + '%', background: p.status === 'completed' ? 'var(--info)' : 'var(--success)' }"
                                ></div>
                            </div>
                            <div class="project-progress-meta">
                                <span><strong>{{ p.progress }}%</strong></span>
                                <span class="muted">{{ p.completed_tasks }}/{{ p.tasks_count }} tasks · {{ p.milestones_done }}/{{ p.milestones_total }} milestones</span>
                            </div>
                        </div>

                        <div class="project-card-foot">
                            <div class="avatar-stack">
                                <span
                                    v-for="m in p.members"
                                    :key="m.id"
                                    class="av"
                                    :style="{ background: m.avatar_colour ?? 'var(--text-tertiary)' }"
                                    :title="m.name"
                                >{{ m.name.split(' ').map(s => s[0]).slice(0,2).join('') }}</span>
                                <span v-if="p.total_members > 4" class="av av-more">+{{ p.total_members - 4 }}</span>
                            </div>
                            <span class="status-badge" :class="`status-${p.status}`">{{ statusLabel(p.status) }}</span>
                        </div>
                    </div>
                </Link>
            </div>

            <!-- ─── Empty state ─── -->
            <div v-else class="project-empty">
                <IconLayoutKanban :size="44" stroke-width="1.4" />
                <h3>No projects found</h3>
                <p v-if="hasFilters">Try adjusting your filters or <button type="button" class="ghost-link inline" @click="clearFilters">clear them</button>.</p>
                <p v-else>Create your first project to start tracking work.</p>
                <button type="button" class="btn btn-primary" @click="openCreate">
                    <IconPlus :size="16" stroke-width="2" />
                    New project
                </button>
            </div>

            <!-- ─── Pagination ─── -->
            <div v-if="projects.data.length > 0" class="pg-foot">
                <span class="pg-info">
                    Showing <strong>{{ projects.from }}–{{ projects.to }}</strong> of <strong>{{ projects.total }}</strong>
                </span>
                <div class="pg-buttons">
                    <button class="pg-btn" :disabled="!prevLink" @click="go(prevLink)">
                        <IconChevronLeft :size="14" stroke-width="2" />
                        Previous
                    </button>
                    <button class="pg-btn" :disabled="!nextLink" @click="go(nextLink)">
                        Next
                        <IconChevronRight :size="14" stroke-width="2" />
                    </button>
                </div>
            </div>
        </div>

        <!-- ─── New project slide-over ─── -->
        <Teleport to="body">
            <div v-if="showCreate" class="slide-over-overlay" @click.self="closeCreate">
                <div class="slide-over" style="width: 560px;">
                    <div class="slide-over-head">
                        <h2>New project</h2>
                        <button type="button" class="icon-btn" @click="closeCreate">
                            <IconX :size="18" stroke-width="2" />
                        </button>
                    </div>

                    <form @submit.prevent="submit" class="slide-over-body">
                        <!-- Basic info -->
                        <div class="form-section">
                            <label class="form-label">Title <span class="req">*</span></label>
                            <input v-model="form.title" type="text" class="form-input lg" placeholder="Project title" required maxlength="255" />
                            <p v-if="form.errors.title" class="form-error">{{ form.errors.title }}</p>
                        </div>

                        <div class="form-section">
                            <label class="form-label">Description</label>
                            <textarea v-model="form.description" class="form-input" rows="2" maxlength="5000" placeholder="What's this project about?"></textarea>
                        </div>

                        <div class="form-section">
                            <label class="form-label">Customer</label>
                            <div v-if="selectedCustomer" class="picked-chip">
                                <span>{{ selectedCustomer.name }}</span>
                                <button type="button" class="icon-btn xs" @click="clearCustomer">
                                    <IconX :size="13" stroke-width="2" />
                                </button>
                            </div>
                            <div v-else class="cust-search">
                                <input v-model="customerSearch" type="text" class="form-input" placeholder="Search customers… (leave blank for internal project)" />
                                <ul v-if="customerSearch" class="cust-list">
                                    <li v-for="c in filteredCustomers" :key="c.id" @click="pickCustomer(c)">
                                        {{ c.name }}
                                    </li>
                                    <li v-if="filteredCustomers.length === 0" class="muted">No matches</li>
                                </ul>
                            </div>
                        </div>

                        <!-- Status + priority -->
                        <div class="form-row-2">
                            <div class="form-section">
                                <label class="form-label">Status</label>
                                <select v-model="form.status" class="form-input">
                                    <option v-for="s in statuses" :key="s" :value="s">{{ statusLabel(s) }}</option>
                                </select>
                            </div>
                            <div class="form-section">
                                <label class="form-label">Priority</label>
                                <div class="pill-row">
                                    <button
                                        v-for="p in priorities"
                                        :key="p"
                                        type="button"
                                        class="pill"
                                        :class="{ active: form.priority === p, [`pri-${p}`]: form.priority === p }"
                                        @click="form.priority = p"
                                    >{{ priorityLabel(p) }}</button>
                                </div>
                            </div>
                        </div>

                        <!-- Dates -->
                        <div class="form-row-2">
                            <div class="form-section">
                                <label class="form-label">Start date</label>
                                <input v-model="form.start_date" type="date" class="form-input" />
                            </div>
                            <div class="form-section">
                                <label class="form-label">Due date</label>
                                <input v-model="form.due_date" type="date" class="form-input" />
                                <p v-if="form.errors.due_date" class="form-error">{{ form.errors.due_date }}</p>
                            </div>
                        </div>

                        <!-- Colour -->
                        <div class="form-section">
                            <label class="form-label">Project colour</label>
                            <p class="field-help">Used everywhere — kanban headers, cards, and the personal "My Work" page — so every project is visually distinct.</p>
                            <div class="colour-row">
                                <button
                                    v-for="c in PRESET_COLOURS"
                                    :key="c"
                                    type="button"
                                    class="colour-swatch"
                                    :class="{ active: form.colour === c }"
                                    :style="{ background: c }"
                                    @click="form.colour = c"
                                ></button>
                                <input v-model="form.colour" type="text" class="form-input sm" maxlength="7" placeholder="#3B82F6" />
                            </div>
                            <p v-if="form.errors.colour" class="form-error">{{ form.errors.colour }}</p>
                        </div>

                        <!-- Team -->
                        <div class="form-section">
                            <label class="form-label">Project lead</label>
                            <select v-model="form.project_lead" class="form-input">
                                <option :value="null">No lead</option>
                                <option v-for="s in staff" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </select>
                        </div>

                        <div class="form-section">
                            <label class="form-label">Team members</label>
                            <div class="member-grid">
                                <button
                                    v-for="s in staff"
                                    :key="s.id"
                                    type="button"
                                    class="member-chip"
                                    :class="{ active: form.member_ids.includes(s.id) }"
                                    @click="toggleMember(s.id)"
                                >
                                    <span class="av sm" :style="{ background: s.avatar_colour ?? 'var(--text-tertiary)' }">
                                        {{ s.name.split(' ').map(p => p[0]).slice(0,2).join('') }}
                                    </span>
                                    <span>{{ s.name }}</span>
                                </button>
                            </div>
                        </div>

                        <!-- Billing -->
                        <div class="form-row-2">
                            <div class="form-section">
                                <label class="form-label">Budget (£)</label>
                                <input v-model="form.budget" type="number" min="0" step="0.01" class="form-input" placeholder="0.00" />
                            </div>
                            <div class="form-section">
                                <label class="form-label">Hourly rate (£)</label>
                                <input v-model="form.hourly_rate" type="number" min="0" step="0.01" class="form-input" placeholder="0.00" />
                                <p class="field-help">Default billing rate for time entries.</p>
                            </div>
                        </div>
                    </form>

                    <div class="slide-over-foot">
                        <button type="button" class="btn btn-ghost" @click="closeCreate">Cancel</button>
                        <button type="button" class="btn btn-primary" :disabled="form.processing" @click="submit">
                            {{ form.processing ? 'Creating…' : 'Create project' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </InternalLayout>
</template>
