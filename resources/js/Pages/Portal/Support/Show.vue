<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    IconArrowLeft,
} from '@tabler/icons-vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';

const props = defineProps({
    ticket: { type: Object, required: true },
});

function statusBadge(status) {
    if (status === 'resolved' || status === 'closed') return { cls: 'badge-active', label: 'Resolved' };
    if (status === 'awaiting_customer') return { cls: 'badge-pending', label: 'Awaiting you' };
    if (status === 'in_progress') return { cls: 'badge-info', label: 'In progress' };
    return { cls: 'badge-pending', label: 'Open' };
}

const replyForm = useForm({
    message: '',
});

function submitReply() {
    replyForm.post(`/portal/support/${props.ticket.id}/reply`, {
        preserveScroll: true,
        onSuccess: () => replyForm.reset(),
    });
}

const canReply = computed(() => !['closed'].includes(props.ticket.status));
</script>

<template>
    <Head :title="`#TK-${ticket.id} · Support`" />
    <PortalLayout :title="`Ticket #TK-${ticket.id}`" active-nav="support">
        <div class="portal-ticket-back">
            <Link href="/portal/support" class="ghost-link">
                <IconArrowLeft :size="14" stroke-width="1.75" />
                Back to all tickets
            </Link>
        </div>

        <header class="portal-ticket-header">
            <div>
                <div class="portal-ticket-eyebrow">#TK-{{ ticket.id }} · Opened {{ ticket.created_at }}</div>
                <h1>{{ ticket.subject }}</h1>
            </div>
            <span class="badge" :class="statusBadge(ticket.status).cls">{{ statusBadge(ticket.status).label }}</span>
        </header>

        <div class="portal-thread">
            <div
                v-for="m in ticket.messages"
                :key="m.id"
                class="portal-msg"
                :class="{ 'is-customer': m.sender_type === 'customer', 'is-staff': m.sender_type !== 'customer' }"
            >
                <div class="portal-msg-meta">
                    <strong>{{ m.sender_name }}</strong>
                    <span class="sep">·</span>
                    <span>{{ m.created_at_human }}</span>
                </div>
                <div class="portal-msg-body">{{ m.body }}</div>
            </div>
        </div>

        <form v-if="canReply" class="portal-reply-form" @submit.prevent="submitReply">
            <label class="portal-reply-label">Reply to this ticket</label>
            <textarea
                v-model="replyForm.message"
                rows="5"
                placeholder="Type your reply here…"
                :class="{ 'has-err': replyForm.errors.message }"
                required
            />
            <div v-if="replyForm.errors.message" class="err">{{ replyForm.errors.message }}</div>
            <div class="portal-reply-actions">
                <button type="submit" class="btn btn-primary" :disabled="replyForm.processing">
                    {{ replyForm.processing ? 'Sending…' : 'Send reply' }}
                </button>
            </div>
        </form>

        <div v-else class="portal-empty-inline" style="margin-top: 16px;">
            This ticket is closed. Open a new one if you need further help.
        </div>
    </PortalLayout>
</template>
