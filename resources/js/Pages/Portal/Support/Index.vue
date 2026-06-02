<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    IconArrowRight,
    IconHeadset,
    IconPlus,
    IconX,
    IconAlertCircle,
} from '@tabler/icons-vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';

const props = defineProps({
    tickets: { type: Array, default: () => [] },
});

const openCount = computed(() =>
    props.tickets.filter((t) => ['open', 'in_progress', 'awaiting_customer'].includes(t.status)).length,
);

const counts = computed(() => ({
    support: openCount.value || undefined,
}));

// Maps the real ticket statuses (open, in_progress, awaiting_customer,
// resolved, closed) to a dot tone + pill label. awaiting_customer means the
// ball is in the customer's court, so it reads "Reply needed".
function statusInfo(status) {
    const map = {
        open: { tone: 'amber', label: 'Open' },
        in_progress: { tone: 'blue', label: 'In progress' },
        awaiting_customer: { tone: 'blue', label: 'Reply needed' },
        resolved: { tone: 'green', label: 'Resolved' },
        closed: { tone: 'grey', label: 'Closed' },
    };
    return map[status] ?? map.open;
}

// Only surface a priority chip when it's worth the customer's attention.
function showPriority(priority) {
    return priority === 'high' || priority === 'urgent';
}

function priorityLabel(priority) {
    return priority.charAt(0).toUpperCase() + priority.slice(1);
}

/* ── NEW TICKET DIALOG ─────────────────────────────────────── */
const showNew = ref(false);

const form = useForm({
    subject: '',
    message: '',
    priority: 'medium',
});

function openNew() {
    form.reset();
    form.priority = 'medium';
    showNew.value = true;
}

function closeNew() {
    showNew.value = false;
}

function submit() {
    form.post('/portal/support', {
        preserveScroll: true,
        onSuccess: () => {
            showNew.value = false;
        },
    });
}
</script>

<template>
    <Head title="Support · Whitedash" />
    <PortalLayout title="Support" active-nav="support" :counts="counts">
        <div class="portal-section-head">
            <div class="col-l">
                <h2>Support tickets</h2>
                <div class="desc">{{ openCount }} open · {{ tickets.length - openCount }} resolved</div>
            </div>
            <button type="button" class="btn btn-primary" @click="openNew">
                <IconPlus :size="14" stroke-width="1.75" />
                New ticket
            </button>
        </div>

        <div class="portal-support">
            <div v-if="tickets.length === 0" class="support-empty">
                <IconHeadset :size="40" stroke-width="1.5" />
                <h3>No open tickets</h3>
                <p>All good! Need help with something?</p>
                <button type="button" class="btn btn-primary" @click="openNew">
                    <IconPlus :size="14" stroke-width="1.75" />
                    Open a support ticket
                </button>
            </div>

            <Link
                v-for="t in tickets"
                v-else
                :key="t.id"
                :href="`/portal/support/${t.id}`"
                class="ticket-card"
            >
                <div class="ticket-status-dot" :class="`dot-${statusInfo(t.status).tone}`" />

                <div class="ticket-card-body">
                    <div class="ticket-card-header">
                        <span class="ticket-ref">#TK-{{ t.id }}</span>
                        <span class="ticket-status-badge" :class="`badge-${statusInfo(t.status).tone}`">
                            {{ statusInfo(t.status).label }}
                        </span>
                        <span v-if="showPriority(t.priority)" class="ticket-priority" :class="{ urgent: t.priority === 'urgent' }">
                            <IconAlertCircle :size="13" stroke-width="2" />
                            {{ priorityLabel(t.priority) }}
                        </span>
                    </div>

                    <div class="ticket-title">{{ t.subject }}</div>

                    <div class="ticket-meta">
                        <span>{{ t.messages_count }} {{ t.messages_count === 1 ? 'message' : 'messages' }}</span>
                        <span>·</span>
                        <span>Updated {{ t.updated_at }}</span>
                    </div>

                    <div v-if="t.last_message" class="ticket-preview">{{ t.last_message }}</div>
                </div>

                <span class="ticket-card-view">
                    View
                    <IconArrowRight :size="14" stroke-width="1.75" />
                </span>
            </Link>
        </div>

        <!-- NEW TICKET DIALOG -->
        <Teleport to="body">
            <div v-if="showNew" class="portal-modal-backdrop" @click="closeNew" />
            <div v-if="showNew" class="portal-modal" role="dialog" aria-modal="true">
                <form @submit.prevent="submit">
                    <header class="portal-modal-header">
                        <div>
                            <div class="portal-modal-eyebrow">New ticket</div>
                            <h2>Open a support ticket</h2>
                        </div>
                        <button type="button" class="icon-btn" @click="closeNew">
                            <IconX :size="18" stroke-width="1.75" />
                        </button>
                    </header>
                    <div class="portal-modal-body">
                        <div class="form-field">
                            <label>Subject<span class="req">*</span></label>
                            <input
                                v-model="form.subject"
                                type="text"
                                :class="{ 'has-err': form.errors.subject }"
                                placeholder="What do you need help with?"
                                required
                            >
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
                            <textarea
                                v-model="form.message"
                                rows="8"
                                :class="{ 'has-err': form.errors.message }"
                                placeholder="Include steps to reproduce, screenshots if helpful, and anything time-sensitive."
                                required
                            />
                            <div v-if="form.errors.message" class="err">{{ form.errors.message }}</div>
                        </div>

                        <div
                            v-if="form.hasErrors && ! form.errors.subject && ! form.errors.message && ! form.errors.priority"
                            class="portal-flash error"
                        >
                            <IconAlertCircle :size="16" stroke-width="2" />
                            Something went wrong — please try again.
                        </div>
                    </div>
                    <footer class="portal-modal-footer">
                        <button type="button" class="btn btn-secondary" @click="closeNew">Cancel</button>
                        <button type="submit" class="btn btn-primary" :disabled="form.processing">
                            {{ form.processing ? 'Sending…' : 'Submit ticket' }}
                        </button>
                    </footer>
                </form>
            </div>
        </Teleport>
    </PortalLayout>
</template>

<style scoped>
.ticket-card {
    display: flex;
    gap: 14px;
    padding: 16px;
    background: #fff;
    border: 1px solid var(--border, #e5e7eb);
    border-radius: 12px;
    margin-bottom: 10px;
    text-decoration: none;
    transition: box-shadow .15s, border-color .15s;
}
.ticket-card:hover {
    box-shadow: 0 2px 12px rgba(0, 0, 0, .08);
    border-color: var(--border, #d1d5db);
}
.ticket-status-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    margin-top: 6px;
    flex-shrink: 0;
}
.dot-amber { background: #f59e0b; }
.dot-blue { background: #3b82f6; }
.dot-green { background: #10b981; }
.dot-grey { background: #9ca3af; }

.ticket-card-body { flex: 1; min-width: 0; }
.ticket-card-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 4px;
}
.ticket-ref {
    font: 500 12px/1 'Inter', sans-serif;
    color: #9ca3af;
}
.ticket-status-badge {
    font: 500 11px/1 'Inter', sans-serif;
    padding: 3px 8px;
    border-radius: 999px;
}
.badge-amber { background: #fef3c7; color: #d97706; }
.badge-blue { background: #eff6ff; color: #2563eb; }
.badge-green { background: #ecfdf5; color: #059669; }
.badge-grey { background: #f3f4f6; color: #6b7280; }
.ticket-priority {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font: 500 11px/1 'Inter', sans-serif;
    color: #d97706;
}
.ticket-priority.urgent { color: #dc2626; }
.ticket-title {
    font: 600 15px/1.3 'Inter', sans-serif;
    color: #111827;
    margin-bottom: 6px;
}
.ticket-meta {
    display: flex;
    gap: 6px;
    font: 400 12px/1 'Inter', sans-serif;
    color: #9ca3af;
    margin-bottom: 6px;
}
.ticket-preview {
    font: 400 13px/1.4 'Inter', sans-serif;
    color: #6b7280;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.ticket-card-view {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    align-self: center;
    font: 500 13px/1 'Inter', sans-serif;
    color: var(--accent, #f5a623);
    white-space: nowrap;
}
.support-empty {
    text-align: center;
    padding: 60px 24px;
    color: #9ca3af;
}
.support-empty h3 {
    font: 600 18px/1 'Inter', sans-serif;
    color: #374151;
    margin: 12px 0 8px;
}
.support-empty p { margin: 0 0 18px; font-size: 14px; }
</style>
