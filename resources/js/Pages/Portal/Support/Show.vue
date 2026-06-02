<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { useBackNavigation } from '@/Composables/useBackNavigation';

const props = defineProps({
    ticket: { type: Object, required: true },
});

const { goBack } = useBackNavigation('/portal/support');

function statusBadge(status) {
    if (status === 'resolved' || status === 'closed') return { cls: 'badge-paid', label: 'Resolved' };
    if (status === 'awaiting_customer') return { cls: 'badge-open', label: 'Awaiting you' };
    if (status === 'in_progress') return { cls: 'badge-open', label: 'In progress' };
    return { cls: 'badge-awaiting', label: 'Open' };
}

function priorityLabel(priority) {
    return priority ? priority.charAt(0).toUpperCase() + priority.slice(1) : 'Normal';
}

function initials(name) {
    const parts = (name || '?').trim().split(/\s+/);
    return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || '?';
}

// "them" = Whitedash staff/assistant, "you" = the customer.
function isYou(m) {
    return m.sender_type === 'customer';
}

const replyForm = useForm({ message: '' });
function submitReply() {
    if (! replyForm.message.trim()) return;
    replyForm.post(`/portal/support/${props.ticket.id}/reply`, {
        preserveScroll: true,
        onSuccess: () => replyForm.reset(),
    });
}

const canReply = computed(() => props.ticket.status !== 'closed');
</script>

<template>
    <Head :title="`#TK-${ticket.id} · Support`" />
    <PortalLayout active-nav="support" :mobile-tabbar="false">
        <!-- ═══════════ DESKTOP ═══════════ -->
        <template #desktop>
            <div class="content narrow">
                <a class="back-link" href="#" @click.prevent="goBack"><i class="ti ti-arrow-left" />Back to support</a>

                <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:8px">
                    <div>
                        <div style="display:flex;align-items:center;gap:10px">
                            <span class="mono" style="color:var(--text-secondary);font-weight:700;font-size:14px">#TK-{{ ticket.id }}</span>
                            <h1 style="font:700 24px/1.1 'Inter';letter-spacing:-.02em;margin:0">{{ ticket.subject }}</h1>
                        </div>
                        <div class="tk-meta" style="margin-top:8px">
                            <span class="badge badge-sm" :class="statusBadge(ticket.status).cls">{{ statusBadge(ticket.status).label }}</span>
                            <span class="sep">·</span>Priority: {{ priorityLabel(ticket.priority) }}<span class="sep">·</span>{{ ticket.messages.length }} message{{ ticket.messages.length === 1 ? '' : 's' }}
                        </div>
                    </div>
                </div>

                <div class="thread" style="margin-top:24px">
                    <div v-for="m in ticket.messages" :key="m.id" class="msg" :class="isYou(m) ? 'you' : 'them'">
                        <div v-if="isYou(m)" class="av avatar av-amber">{{ initials(m.sender_name) }}</div>
                        <div v-else class="av staff">WD</div>
                        <div class="bubble">
                            <div class="who">
                                <template v-if="isYou(m)"><span class="time">{{ m.created_at }}</span>You</template>
                                <template v-else>{{ m.sender_name }}<span class="time">{{ m.created_at }}</span></template>
                            </div>
                            <div class="txt">{{ m.body }}</div>
                        </div>
                    </div>
                </div>

                <form v-if="canReply" class="reply-box" @submit.prevent="submitReply">
                    <textarea v-model="replyForm.message" placeholder="Write your reply…" />
                    <div class="reply-foot">
                        <span v-if="replyForm.errors.message" class="err" style="color:var(--danger);font-size:12.5px">{{ replyForm.errors.message }}</span>
                        <span v-else />
                        <button type="submit" class="btn btn-primary" :disabled="replyForm.processing"><i class="ti ti-send" />{{ replyForm.processing ? 'Sending…' : 'Send reply' }}</button>
                    </div>
                </form>
                <div v-else class="card card-pad" style="margin-top:24px;color:var(--text-secondary)">
                    This ticket is closed. Open a new one if you need further help.
                </div>
            </div>
        </template>

        <!-- ═══════════ MOBILE ═══════════ -->
        <template #mobile>
            <div class="m-appbar">
                <span class="m-back" @click="goBack"><i class="ti ti-chevron-left" />Support</span>
                <div class="spacer" />
                <div class="title" style="font-size:16px">#TK-{{ ticket.id }}</div>
                <div class="spacer" />
                <div class="bell" style="width:32px" />
            </div>
            <div style="padding:12px 16px;background:#fff;border-bottom:1px solid var(--border);flex-shrink:0">
                <div style="font:600 15px/1.3 'Inter';letter-spacing:-.01em">{{ ticket.subject }}</div>
                <div class="tk-meta" style="margin-top:6px">
                    <span class="badge badge-sm" :class="statusBadge(ticket.status).cls">{{ statusBadge(ticket.status).label }}</span>
                    <span class="sep">·</span>{{ priorityLabel(ticket.priority) }}<span class="sep">·</span>{{ ticket.messages.length }} msg{{ ticket.messages.length === 1 ? '' : 's' }}
                </div>
            </div>
            <div class="m-body" style="gap:0">
                <div class="m-thread">
                    <div v-for="m in ticket.messages" :key="m.id" class="m-msg" :class="isYou(m) ? 'you' : 'them'">
                        <div v-if="isYou(m)" class="av avatar av-amber">{{ initials(m.sender_name) }}</div>
                        <div v-else class="av staff">WD</div>
                        <div class="m-bubble">
                            <div class="who">
                                <template v-if="isYou(m)"><span class="time">{{ m.created_at_human }}</span>You</template>
                                <template v-else>{{ m.sender_name }}<span class="time">{{ m.created_at_human }}</span></template>
                            </div>
                            <div class="txt">{{ m.body }}</div>
                        </div>
                    </div>
                </div>
            </div>
            <form v-if="canReply" class="m-reply" @submit.prevent="submitReply">
                <input
                    v-model="replyForm.message"
                    class="field-fake"
                    style="border:1px solid var(--border);outline:0;color:var(--text-primary)"
                    placeholder="Your reply…"
                >
                <button type="submit" class="send" :disabled="replyForm.processing" style="border:0;cursor:pointer"><i class="ti ti-arrow-up" /></button>
            </form>
        </template>
    </PortalLayout>
</template>
