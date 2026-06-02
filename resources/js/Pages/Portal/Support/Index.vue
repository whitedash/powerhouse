<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { IconX, IconAlertCircle } from '@tabler/icons-vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';

const props = defineProps({
    tickets: { type: Array, default: () => [] },
});

const OPEN = ['open', 'in_progress'];
const AWAITING = ['awaiting_customer'];
const RESOLVED = ['resolved', 'closed'];

const openCount = computed(() => props.tickets.filter((t) => OPEN.includes(t.status) || AWAITING.includes(t.status)).length);
const awaitingCount = computed(() => props.tickets.filter((t) => AWAITING.includes(t.status)).length);
const resolvedCount = computed(() => props.tickets.filter((t) => RESOLVED.includes(t.status)).length);

const navCounts = computed(() => ({ support: openCount.value || undefined }));

// Active filter tab (client-side; tickets aren't paginated).
const filter = ref('open');
// "Open" = every active ticket (not resolved/closed), including ones
// awaiting your reply; "Awaiting you" is the subset where the ball is in
// the customer's court.
const isActive = (t) => OPEN.includes(t.status) || AWAITING.includes(t.status);
const filterTabs = computed(() => [
    { key: 'open', label: 'Open', count: props.tickets.filter(isActive).length },
    { key: 'awaiting', label: 'Awaiting you', count: awaitingCount.value },
    { key: 'resolved', label: 'Resolved', count: resolvedCount.value },
]);
const filteredTickets = computed(() => {
    if (filter.value === 'awaiting') return props.tickets.filter((t) => AWAITING.includes(t.status));
    if (filter.value === 'resolved') return props.tickets.filter((t) => RESOLVED.includes(t.status));
    return props.tickets.filter(isActive);
});

// status → dot tone + badge class/label.
function ticketUi(status) {
    const map = {
        open: { dot: 'open', cls: 'badge-awaiting', label: 'Open' },
        in_progress: { dot: 'awaiting', cls: 'badge-open', label: 'In progress' },
        awaiting_customer: { dot: 'awaiting', cls: 'badge-open', label: 'Awaiting you' },
        resolved: { dot: 'resolved', cls: 'badge-paid', label: 'Resolved' },
        closed: { dot: 'closed', cls: 'badge-neutral', label: 'Closed' },
    };
    return map[status] ?? map.open;
}

function priorityLabel(priority) {
    return priority ? priority.charAt(0).toUpperCase() + priority.slice(1) : 'Normal';
}

/* ── NEW TICKET DIALOG (kept) ── */
const showNew = ref(false);
const form = useForm({ subject: '', message: '', priority: 'medium' });

function openNew() {
    form.reset();
    form.priority = 'medium';
    showNew.value = true;
}
function closeNew() {
    showNew.value = false;
}
function submit() {
    form.post('/portal/support', { preserveScroll: true, onSuccess: () => { showNew.value = false; } });
}
</script>

<template>
    <Head title="Support · Whitedash" />
    <PortalLayout active-nav="support" :counts="navCounts">
        <!-- ═══════════ DESKTOP ═══════════ -->
        <template #desktop>
            <div class="content">
                <div class="page-head">
                    <div class="ph-l">
                        <h1>Support</h1>
                        <div class="ph-sub">{{ openCount }} open ticket{{ openCount === 1 ? '' : 's' }} · typical reply within a few hours</div>
                    </div>
                    <button class="btn btn-primary" @click="openNew"><i class="ti ti-plus" />New ticket</button>
                </div>

                <div style="display:flex;align-items:center;margin-bottom:18px">
                    <div class="filter-tabs">
                        <button
                            v-for="t in filterTabs"
                            :key="t.key"
                            class="filter-tab"
                            :class="{ active: filter === t.key }"
                            @click="filter = t.key"
                        >{{ t.label }}<span class="c">{{ t.count }}</span></button>
                    </div>
                </div>

                <div v-if="filteredTickets.length === 0" class="card">
                    <div class="empty">
                        <div class="ic"><i class="ti ti-lifebuoy" /></div>
                        <h4>Nothing here</h4>
                        <p>No tickets match this filter. Need a hand? Open a new ticket.</p>
                        <button class="btn btn-primary btn-sm" style="margin-top:8px" @click="openNew"><i class="ti ti-plus" />New ticket</button>
                    </div>
                </div>

                <div v-else class="tk-cards">
                    <Link
                        v-for="t in filteredTickets"
                        :key="t.id"
                        :href="`/portal/support/${t.id}`"
                        class="tk-card"
                        style="text-decoration:none;color:inherit;display:block"
                    >
                        <div class="tk-top">
                            <div class="tk-id"><span class="dot" :class="ticketUi(t.status).dot" /><span class="n">#TK-{{ t.id }}</span></div>
                            <span class="badge" :class="ticketUi(t.status).cls">{{ ticketUi(t.status).label }}</span>
                        </div>
                        <div class="tk-subj">{{ t.subject }}</div>
                        <div class="tk-meta">Updated {{ t.updated_at }}<span class="sep">·</span>Priority: {{ priorityLabel(t.priority) }}<span class="sep">·</span>{{ t.messages_count }} message{{ t.messages_count === 1 ? '' : 's' }}</div>
                        <div v-if="t.last_message" class="tk-preview">{{ t.last_message }}</div>
                        <div class="tk-card-foot"><span class="ghost-link accent">View ticket<i class="ti ti-arrow-right" /></span></div>
                    </Link>
                </div>
            </div>
        </template>

        <!-- ═══════════ MOBILE ═══════════ -->
        <template #mobile>
            <div class="m-appbar">
                <div class="title">Support</div>
                <div class="spacer" />
                <button class="btn btn-primary btn-sm" @click="openNew"><i class="ti ti-plus" />New</button>
            </div>
            <div class="m-body is-pb">
                <div style="font:400 13px/1.4 'Inter';color:var(--text-secondary);margin-top:-4px">{{ openCount }} open ticket{{ openCount === 1 ? '' : 's' }}</div>
                <div class="m-filters">
                    <div
                        v-for="t in filterTabs"
                        :key="t.key"
                        class="m-chip"
                        :class="{ active: filter === t.key }"
                        @click="filter = t.key"
                    >{{ t.label }}</div>
                </div>

                <div v-if="filteredTickets.length === 0" class="m-card">
                    <div class="empty"><div class="ic"><i class="ti ti-lifebuoy" /></div><h4>Nothing here</h4></div>
                </div>

                <div v-else class="tk-cards">
                    <Link
                        v-for="t in filteredTickets"
                        :key="t.id"
                        :href="`/portal/support/${t.id}`"
                        class="tk-card"
                        style="padding:16px;text-decoration:none;color:inherit;display:block"
                    >
                        <div class="tk-top"><div class="tk-id"><span class="dot" :class="ticketUi(t.status).dot" /><span class="n">#TK-{{ t.id }}</span></div><span class="badge badge-sm" :class="ticketUi(t.status).cls">{{ ticketUi(t.status).label }}</span></div>
                        <div class="tk-subj" style="font-size:14px;margin-top:12px">{{ t.subject }}</div>
                        <div class="tk-meta" style="margin-top:5px">{{ t.updated_at }}<span class="sep">·</span>{{ priorityLabel(t.priority) }}<span class="sep">·</span>{{ t.messages_count }} msg{{ t.messages_count === 1 ? '' : 's' }}</div>
                        <div v-if="t.last_message" class="tk-preview" style="margin-top:10px">{{ t.last_message }}</div>
                    </Link>
                </div>
            </div>
        </template>
    </PortalLayout>

    <!-- NEW TICKET DIALOG (kept; teleported outside .wd) -->
    <Teleport to="body">
        <div v-if="showNew" class="portal-modal-backdrop" @click="closeNew" />
        <div v-if="showNew" class="portal-modal" role="dialog" aria-modal="true">
            <form @submit.prevent="submit">
                <header class="portal-modal-header">
                    <div>
                        <div class="portal-modal-eyebrow">New ticket</div>
                        <h2>Open a support ticket</h2>
                    </div>
                    <button type="button" class="icon-btn" @click="closeNew"><IconX :size="18" stroke-width="1.75" /></button>
                </header>
                <div class="portal-modal-body">
                    <div class="form-field">
                        <label>Subject<span class="req">*</span></label>
                        <input v-model="form.subject" type="text" :class="{ 'has-err': form.errors.subject }" placeholder="What do you need help with?" required>
                        <div v-if="form.errors.subject" class="err">{{ form.errors.subject }}</div>
                    </div>
                    <div class="form-field">
                        <label>Priority<span class="req">*</span></label>
                        <select v-model="form.priority" required>
                            <option value="low">Low — general question</option>
                            <option value="medium">Medium — something's not right</option>
                            <option value="high">High — can't complete a task</option>
                            <option value="urgent">Urgent — production is down</option>
                        </select>
                        <div v-if="form.errors.priority" class="err">{{ form.errors.priority }}</div>
                    </div>
                    <div class="form-field">
                        <label>Describe the issue<span class="req">*</span></label>
                        <textarea v-model="form.message" rows="8" :class="{ 'has-err': form.errors.message }" placeholder="Include steps to reproduce, screenshots if helpful, and anything time-sensitive." required />
                        <div v-if="form.errors.message" class="err">{{ form.errors.message }}</div>
                    </div>
                    <div v-if="form.hasErrors && ! form.errors.subject && ! form.errors.message && ! form.errors.priority" class="portal-flash error">
                        <IconAlertCircle :size="16" stroke-width="2" />
                        Something went wrong — please try again.
                    </div>
                </div>
                <footer class="portal-modal-footer">
                    <button type="button" class="btn btn-secondary" @click="closeNew">Cancel</button>
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">{{ form.processing ? 'Sending…' : 'Submit ticket' }}</button>
                </footer>
            </form>
        </div>
    </Teleport>
</template>
