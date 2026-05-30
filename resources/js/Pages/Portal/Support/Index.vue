<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    IconArrowRight,
    IconHeadset,
    IconMessageCircle,
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

function statusBadge(status) {
    if (status === 'resolved' || status === 'closed') return { cls: 'badge-active', label: 'Resolved' };
    if (status === 'awaiting_customer') return { cls: 'badge-pending', label: 'Awaiting you' };
    if (status === 'in_progress') return { cls: 'badge-info', label: 'In progress' };
    return { cls: 'badge-pending', label: 'Open' };
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

        <div v-if="tickets.length === 0" class="portal-empty">
            <IconHeadset :size="32" stroke-width="1.5" />
            <div class="portal-empty-title">No tickets yet</div>
            <div class="portal-empty-sub">If you ever need a hand, open a ticket and we'll get back to you fast.</div>
            <button type="button" class="btn btn-primary" @click="openNew">
                <IconPlus :size="14" stroke-width="1.75" />
                Start a ticket
            </button>
        </div>

        <div v-else class="card">
            <Link
                v-for="t in tickets"
                :key="t.id"
                :href="`/portal/support/${t.id}`"
                class="sup-row"
            >
                <div class="sup-ic">
                    <IconMessageCircle :size="18" stroke-width="1.75" />
                </div>
                <div class="sup-meta">
                    <div class="ttl">
                        <strong>#TK-{{ t.id }}</strong>
                        · {{ t.subject }}
                        <span class="badge badge-sm" :class="statusBadge(t.status).cls">{{ statusBadge(t.status).label }}</span>
                    </div>
                    <div class="sub">
                        Updated {{ t.updated_at }} · Priority: {{ priorityLabel(t.priority) }} · {{ t.messages_count }} message{{ t.messages_count === 1 ? '' : 's' }}
                    </div>
                </div>
                <span class="ghost-link accent">
                    View ticket
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
