<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    IconArrowLeft,
    IconSend,
    IconCheck,
    IconAlertCircle,
    IconUser,
    IconClock,
    IconAlertTriangle,
    IconExternalLink,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';

const props = defineProps({
    ticket: { type: Object, required: true },
    customer_products: { type: Array, default: () => [] },
    staff: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
});

const page = usePage();

const breadcrumbs = computed(() => [
    { label: 'Support', href: '/support' },
    { label: `#${props.ticket.id}` },
]);

/* ─── Labels ─── */
const STATUS_LABELS = {
    open: 'Open',
    in_progress: 'In progress',
    awaiting_customer: 'Awaiting customer',
    resolved: 'Resolved',
    closed: 'Closed',
};
const PRIORITY_LABELS = { urgent: 'Urgent', high: 'High', medium: 'Medium', low: 'Low' };

function statusBadgeClass(s) {
    return s === 'open' ? 'badge-overdue'
        : s === 'in_progress' ? 'badge-pending'
        : s === 'awaiting_customer' ? 'badge-info'
        : s === 'resolved' ? 'badge-active'
        : 'badge-inactive';
}
function priorityBadgeClass(p) {
    return p === 'urgent' ? 'badge-overdue'
        : p === 'high' ? 'badge-pending'
        : p === 'medium' ? 'badge-info'
        : 'badge-inactive';
}

/* ─── Avatar ─── */
function initials(name) {
    const parts = String(name || '').trim().split(/\s+/);
    return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || '?';
}
function avatarColour(id) {
    const palette = ['#0D9488', '#F59E0B', '#3B82F6', '#10B981', '#7C3AED', '#EF4444', '#06B6D4', '#6366F1'];
    return palette[Number(id) % palette.length];
}

/* ─── Dates ─── */
function formatDateTime(iso) {
    if (! iso) return '—';
    return new Date(iso).toLocaleString('en-GB', {
        day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
}
function formatDate(iso) {
    if (! iso) return '—';
    return new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}
function slaLabel() {
    const iso = props.ticket.sla_breach_at;
    if (! iso) return { label: 'No SLA', cls: '' };
    const d = new Date(iso);
    const now = new Date();
    if (d < now) {
        const hours = Math.floor((now - d) / 3600000);
        return { label: `Breached ${hours}h ago`, cls: 'breached' };
    }
    const hoursLeft = Math.ceil((d - now) / 3600000);
    if (hoursLeft <= 4) return { label: `${hoursLeft}h left`, cls: 'urgent' };
    return { label: `${hoursLeft}h left`, cls: 'normal' };
}

/* ─── Reply form ─── */
const replyForm = useForm({
    message: '',
    status: 'awaiting_customer',
});
function submitReply() {
    replyForm.post(`/support/${props.ticket.id}/reply`, {
        preserveScroll: true,
        onSuccess: () => replyForm.reset(),
    });
}

/* ─── Status form ─── */
const statusForm = useForm({
    status: props.ticket.status,
    assigned_to: props.ticket.assigned_to_id ?? null,
});
function submitStatus() {
    statusForm.post(`/support/${props.ticket.id}/status`, { preserveScroll: true });
}

function back() {
    router.visit('/support');
}
</script>

<template>
    <Head :title="`#${ticket.id} · ${ticket.subject}`" />

    <InternalLayout :title="ticket.subject" :breadcrumbs="breadcrumbs" active-nav="support">
        <template #topbar-actions>
            <button type="button" class="btn btn-ghost btn-sm" @click="back">
                <IconArrowLeft :size="14" stroke-width="1.75" />
                Back to support
            </button>
        </template>

        <div class="support-show">
            <!-- Flash banners -->
            <div
                v-if="page.props.flash?.success"
                style="margin-bottom: 14px; padding: 10px 14px; background: var(--success-bg); color: #047857; border: 1px solid #A7F3D0; border-radius: var(--radius-md); font: 500 13px/1.4 'Inter', sans-serif; display: flex; align-items: center; gap: 8px;"
            >
                <IconCheck :size="16" stroke-width="2" />{{ page.props.flash.success }}
            </div>
            <div
                v-if="page.props.flash?.error"
                style="margin-bottom: 14px; padding: 10px 14px; background: var(--danger-bg); color: var(--danger); border: 1px solid #FECACA; border-radius: var(--radius-md); font: 500 13px/1.4 'Inter', sans-serif; display: flex; align-items: center; gap: 8px;"
            >
                <IconAlertCircle :size="16" stroke-width="2" />{{ page.props.flash.error }}
            </div>

            <div class="support-grid">
                <!-- LEFT 65% — conversation -->
                <div class="support-conversation">
                    <!-- Subject header -->
                    <div class="conversation-header">
                        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                            <span style="font: 600 14px/1 'JetBrains Mono', monospace; color: var(--text-tertiary);">#{{ ticket.id }}</span>
                            <h2 style="margin: 0; font: 600 18px/1.3 'Inter', sans-serif;">{{ ticket.subject }}</h2>
                            <span :class="['badge', 'badge-sm', statusBadgeClass(ticket.status)]" style="margin-left: auto;">{{ STATUS_LABELS[ticket.status] }}</span>
                            <span :class="['badge', 'badge-sm', priorityBadgeClass(ticket.priority)]">{{ PRIORITY_LABELS[ticket.priority] }}</span>
                        </div>
                        <div v-if="ticket.customer" style="display: flex; align-items: center; gap: 10px; margin-top: 12px; padding: 10px 12px; background: var(--neutral-bg); border-radius: var(--radius-md);">
                            <div class="avatar" :style="{ background: avatarColour(ticket.customer.id), color: '#fff', width: '32px', height: '32px', fontSize: '11px' }">{{ initials(ticket.customer.name) }}</div>
                            <div>
                                <div style="font: 600 13px/1.3 'Inter', sans-serif;">{{ ticket.customer.name }}</div>
                                <div v-if="ticket.customer.city" style="font: 400 11.5px/1.3 'Inter', sans-serif; color: var(--text-secondary);">{{ ticket.customer.city }}</div>
                            </div>
                            <Link :href="`/customers/${ticket.customer.id}`" style="margin-left: auto; font: 500 12px/1 'Inter', sans-serif; color: var(--accent); text-decoration: none;">
                                View customer →
                            </Link>
                        </div>
                    </div>

                    <!-- Messages thread -->
                    <div class="messages-thread">
                        <div v-if="! ticket.messages.length" style="padding: 32px 16px; text-align: center; color: var(--text-secondary); font: 400 13px/1.4 'Inter', sans-serif;">
                            No messages yet
                        </div>
                        <div
                            v-for="m in ticket.messages"
                            :key="`msg-${m.id}`"
                            class="msg-block"
                            :class="{ 'msg-staff': m.is_staff }"
                        >
                            <div class="msg-head-row">
                                <div class="avatar" :style="{ background: m.is_staff ? '#0F172A' : avatarColour(ticket.customer?.id ?? 0), color: '#fff', width: '32px', height: '32px', fontSize: '11px' }">
                                    {{ initials(m.sender_name) }}
                                </div>
                                <div style="flex: 1; min-width: 0;">
                                    <div style="font: 600 13px/1.2 'Inter', sans-serif;">{{ m.sender_name }}</div>
                                    <div style="font: 400 11.5px/1.2 'Inter', sans-serif; color: var(--text-tertiary); margin-top: 2px;">
                                        <template v-if="m.sender_role">{{ m.sender_role === 'super_admin' ? 'Super Admin' : (m.sender_role === 'staff' ? 'Staff' : m.sender_role) }} · </template>
                                        {{ m.time_ago }}
                                    </div>
                                </div>
                            </div>
                            <div class="msg-bubble" :class="{ 'msg-bubble-staff': m.is_staff }">
                                {{ m.body }}
                            </div>
                        </div>
                    </div>

                    <!-- Reply box -->
                    <form class="reply-box" @submit.prevent="submitReply">
                        <textarea
                            v-model="replyForm.message"
                            rows="4"
                            placeholder="Write a reply…"
                            :class="{ 'has-err': replyForm.errors.message }"
                            required
                        />
                        <div v-if="replyForm.errors.message" class="err" style="margin-top: 4px;">{{ replyForm.errors.message }}</div>
                        <div class="reply-foot">
                            <div class="form-field" style="flex: 1; max-width: 240px; margin: 0;">
                                <label style="font: 500 11px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary); margin-bottom: 4px;">Status after reply</label>
                                <select v-model="replyForm.status">
                                    <option value="awaiting_customer">Awaiting customer</option>
                                    <option value="in_progress">In progress</option>
                                    <option value="resolved">Resolved</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary" :disabled="replyForm.processing || ! replyForm.message.trim()">
                                <IconSend :size="14" stroke-width="1.75" />
                                {{ replyForm.processing ? 'Sending…' : 'Send reply' }}
                            </button>
                        </div>
                    </form>
                </div>

                <!-- RIGHT 35% — sidebar -->
                <div class="support-sidebar">
                    <!-- Status card -->
                    <section class="card">
                        <header class="card-header">
                            <h3>Ticket details</h3>
                        </header>
                        <form @submit.prevent="submitStatus" style="padding: 14px 16px;">
                            <div class="form-field" style="margin: 0;">
                                <label>Status</label>
                                <select v-model="statusForm.status">
                                    <option v-for="s in statuses" :key="s" :value="s">{{ STATUS_LABELS[s] }}</option>
                                </select>
                            </div>
                            <div class="form-field" style="margin-top: 10px;">
                                <label>Assigned to</label>
                                <select v-model="statusForm.assigned_to">
                                    <option :value="null">— Unassigned —</option>
                                    <option v-for="u in staff" :key="u.id" :value="u.id">{{ u.name }}</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-secondary" style="margin-top: 12px; width: 100%; justify-content: center;" :disabled="statusForm.processing">
                                <IconCheck :size="14" stroke-width="1.75" />
                                Update
                            </button>
                        </form>

                        <div style="padding: 14px 16px; border-top: 1px solid var(--border-soft); display: flex; flex-direction: column; gap: 10px;">
                            <div class="ticket-meta-row">
                                <span class="ticket-meta-label">SLA</span>
                                <span :class="['sla-cell', slaLabel().cls]">
                                    <IconClock v-if="slaLabel().cls === 'normal'" :size="13" stroke-width="1.75" />
                                    <IconAlertTriangle v-else-if="slaLabel().cls === 'urgent'" :size="13" stroke-width="1.75" />
                                    <IconAlertTriangle v-else-if="slaLabel().cls === 'breached'" :size="13" stroke-width="1.75" />
                                    {{ slaLabel().label }}
                                </span>
                            </div>
                            <div class="ticket-meta-row">
                                <span class="ticket-meta-label">Opened</span>
                                <span class="ticket-meta-value">{{ formatDateTime(ticket.created_at) }}</span>
                            </div>
                            <div v-if="ticket.resolved_at" class="ticket-meta-row">
                                <span class="ticket-meta-label">Resolved</span>
                                <span class="ticket-meta-value">{{ formatDateTime(ticket.resolved_at) }}</span>
                            </div>
                            <div v-if="ticket.closed_at" class="ticket-meta-row">
                                <span class="ticket-meta-label">Closed</span>
                                <span class="ticket-meta-value">{{ formatDateTime(ticket.closed_at) }}</span>
                            </div>
                            <div v-if="ticket.assigned_to_name" class="ticket-meta-row">
                                <span class="ticket-meta-label">Assignee</span>
                                <span class="ticket-meta-value">{{ ticket.assigned_to_name }}</span>
                            </div>
                        </div>
                    </section>

                    <!-- Customer card -->
                    <section v-if="ticket.customer" class="card" style="margin-top: 16px;">
                        <header class="card-header">
                            <h3>Customer</h3>
                        </header>
                        <div style="padding: 14px 16px;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div class="avatar" :style="{ background: avatarColour(ticket.customer.id), color: '#fff', width: '36px', height: '36px', fontSize: '12px' }">
                                    {{ initials(ticket.customer.name) }}
                                </div>
                                <div>
                                    <div style="font: 600 13px/1.3 'Inter', sans-serif;">{{ ticket.customer.name }}</div>
                                    <div v-if="ticket.customer.city" style="font: 400 11.5px/1.3 'Inter', sans-serif; color: var(--text-secondary);">{{ ticket.customer.city }}</div>
                                </div>
                            </div>

                            <div v-if="customer_products.length" style="margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border-soft);">
                                <div style="font: 500 10px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary); margin-bottom: 8px;">Active products</div>
                                <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                                    <span
                                        v-for="cp in customer_products"
                                        :key="`cp-${cp.id}`"
                                        class="badge badge-sm"
                                        :class="cp.status === 'trial' ? 'badge-pending' : 'badge-active'"
                                    >
                                        {{ cp.product_name }}
                                    </span>
                                </div>
                            </div>

                            <Link :href="`/customers/${ticket.customer.id}`" style="display: inline-flex; align-items: center; gap: 4px; margin-top: 12px; font: 500 12px/1 'Inter', sans-serif; color: var(--accent); text-decoration: none;">
                                View customer
                                <IconExternalLink :size="12" stroke-width="1.75" />
                            </Link>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </InternalLayout>
</template>
