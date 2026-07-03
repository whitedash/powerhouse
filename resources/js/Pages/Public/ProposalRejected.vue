<script setup>
/**
 * Public confirmation page shown after the visitor declines (or revisits
 * an already-declined proposal). Same shell as ProposalView / ProposalAccepted
 * — no InternalLayout, branded but minimal. Mirror of ProposalAccepted.
 */
import { Head } from '@inertiajs/vue3';
import { IconCircleX } from '@tabler/icons-vue';

defineProps({
    reference: { type: String, required: true },
    customer_name: { type: String, default: null },
    /**
     * true → visitor revisited an already-declined link. false → just
     * declined now.
     */
    already: { type: Boolean, default: false },
});
</script>

<template>
    <Head title="Proposal declined" />

    <div class="public-proposal pp-accepted">
        <main class="pp-accepted-card">
            <div class="pp-checkmark" style="color: var(--danger, #EF4444);">
                <IconCircleX :size="64" stroke-width="1.6" />
            </div>

            <h1>Proposal Declined</h1>
            <p class="pp-accepted-lead">
                {{ reference }} has been marked as declined.
            </p>

            <div class="pp-accepted-meta">
                <div v-if="customer_name">
                    <span class="muted small">Customer</span>
                    <strong>{{ customer_name }}</strong>
                </div>
            </div>

            <p v-if="! already" class="pp-accepted-note">
                Thank you for letting us know. If this was a mistake or you'd like
                to discuss an alternative, please contact us.
            </p>
            <p v-else class="pp-accepted-note">
                You have already declined this proposal. If you need to revisit
                this, please contact us.
            </p>
        </main>
    </div>
</template>
