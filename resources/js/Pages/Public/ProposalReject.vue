<script setup>
/**
 * Public proposal-decline confirmation — NO InternalLayout, no auth.
 * Reached from the acceptance page via the same one-time token URL; the
 * visitor confirms the decline and may leave an optional reason. POSTs to
 * /proposals/reject/{token}; on success the controller renders
 * Public/ProposalRejected. Counterpart to the accept flow on ProposalView.
 */
import { Head, Link, useForm } from '@inertiajs/vue3';
import { IconArrowLeft } from '@tabler/icons-vue';

const props = defineProps({
    proposal: { type: Object, required: true },
    token: { type: String, required: true },
});

const form = useForm({
    rejection_reason: '',
});

function money(v) {
    return '£' + Number(v || 0).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function submit() {
    form.post(`/proposals/reject/${props.token}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Decline proposal" />

    <div class="public-proposal">
        <main class="pp-accepted-card">
            <h1>Decline proposal</h1>
            <p class="pp-accepted-lead">
                {{ proposal.reference }}<span v-if="proposal.title"> · {{ proposal.title }}</span>
                <span class="muted"> · </span><strong>{{ money(proposal.total) }}</strong>
            </p>

            <p class="pp-accepted-note">
                You're about to decline this proposal. This can't be undone online —
                you'd need a fresh link from us to accept it afterwards. If you'd
                like, tell us why (optional):
            </p>

            <form @submit.prevent="submit">
                <textarea
                    v-model="form.rejection_reason"
                    rows="4"
                    maxlength="2000"
                    class="form-input"
                    placeholder="Reason for declining (optional)"
                    style="width: 100%; resize: vertical;"
                />
                <p v-if="form.errors.rejection_reason" class="form-error">{{ form.errors.rejection_reason }}</p>

                <div class="pp-reject-actions" style="display: flex; gap: 12px; align-items: center; margin-top: 20px;">
                    <Link :href="`/proposals/accept/${token}`" class="btn btn-ghost">
                        <IconArrowLeft :size="16" stroke-width="2" />
                        Back to proposal
                    </Link>
                    <button type="submit" class="btn btn-danger" :disabled="form.processing">
                        {{ form.processing ? 'Declining…' : 'Confirm decline' }}
                    </button>
                </div>
            </form>
        </main>
    </div>
</template>
