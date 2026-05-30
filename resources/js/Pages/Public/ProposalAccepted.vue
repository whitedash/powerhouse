<script setup>
/**
 * Public success page shown after the visitor accepts (or revisits
 * an already-accepted proposal). Same shell as ProposalView — no
 * InternalLayout, branded but minimal.
 */
import { Head, Link } from '@inertiajs/vue3';
import { IconCircleCheck } from '@tabler/icons-vue';

defineProps({
    reference: { type: String, required: true },
    accepted_at: { type: String, default: null },
    customer_name: { type: String, default: null },
    /**
     * true → operator revisited an already-accepted link, so the
     * "deposit invoice will be sent" line is omitted (it's already
     * gone). false → just-accepted, surface that follow-up.
     */
    already: { type: Boolean, default: false },
});
</script>

<template>
    <Head title="Proposal accepted" />

    <div class="public-proposal pp-accepted">
        <main class="pp-accepted-card">
            <div class="pp-checkmark">
                <IconCircleCheck :size="64" stroke-width="1.6" />
            </div>

            <h1>Proposal Accepted</h1>
            <p class="pp-accepted-lead">
                {{ reference }} has been successfully accepted.
            </p>

            <div class="pp-accepted-meta">
                <div v-if="customer_name">
                    <span class="muted small">Customer</span>
                    <strong>{{ customer_name }}</strong>
                </div>
                <div v-if="accepted_at">
                    <span class="muted small">Date</span>
                    <strong>{{ accepted_at }}</strong>
                </div>
            </div>

            <p v-if="! already" class="pp-accepted-note">
                A copy of the signed proposal has been recorded for your reference,
                and your deposit invoice will be sent shortly.
            </p>
            <p v-else class="pp-accepted-note">
                You have already accepted this proposal. If you need a fresh
                copy of the signed document, please contact us.
            </p>
        </main>
    </div>
</template>
