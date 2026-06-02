<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { IconCircleCheck, IconClock, IconReceipt } from '@tabler/icons-vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';

const props = defineProps({
    invoice: { type: Object, required: true },
});

function gbp(n) {
    return `£${Number(n).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

// The webhook is authoritative, and the success page also confirms the
// session with Stripe on load — so "paid" is the normal case. If the
// confirmation hasn't landed yet we show a softer "processing" state
// rather than claiming payment we can't yet prove.
const isPaid = computed(() => props.invoice.status === 'paid');
</script>

<template>
    <Head title="Payment · Whitedash" />
    <PortalLayout title="Invoices" active-nav="invoices">
        <div class="card invoice-paid-card">
            <div class="invoice-paid-icon" :class="isPaid ? 'green' : 'amber'">
                <IconCircleCheck v-if="isPaid" :size="34" stroke-width="1.75" />
                <IconClock v-else :size="34" stroke-width="1.75" />
            </div>

            <h2 v-if="isPaid">Payment received</h2>
            <h2 v-else>Payment processing</h2>

            <p v-if="isPaid" class="invoice-paid-lead">
                Thank you — we've received your payment of
                <strong>{{ gbp(invoice.total) }}</strong> for invoice
                <strong>{{ invoice.number }}</strong>.
                <template v-if="invoice.paid_at"> Paid on {{ invoice.paid_at }}.</template>
            </p>
            <p v-else class="invoice-paid-lead">
                Your payment for invoice <strong>{{ invoice.number }}</strong>
                is being confirmed. This usually takes a few moments — the
                invoice status will update automatically once it clears.
            </p>

            <div class="invoice-paid-actions">
                <Link :href="`/portal/invoices/${invoice.id}/preview-pdf`" class="btn-ghost btn-sm" target="_blank" rel="noopener">
                    <IconReceipt :size="15" stroke-width="1.75" />
                    View invoice
                </Link>
                <Link href="/portal/invoices" class="btn-primary btn-sm">
                    Back to invoices
                </Link>
            </div>
        </div>
    </PortalLayout>
</template>

<style scoped>
.invoice-paid-card {
    max-width: 520px;
    margin: 32px auto;
    padding: 40px 32px;
    text-align: center;
}
.invoice-paid-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 64px;
    height: 64px;
    border-radius: 50%;
    margin-bottom: 18px;
}
.invoice-paid-icon.green { background: var(--success-bg, #e6f6ec); color: var(--success, #1a8245); }
.invoice-paid-icon.amber { background: var(--warning-bg, #fdf3e2); color: var(--warning, #b7791f); }
.invoice-paid-card h2 { margin: 0 0 10px; font-size: 20px; }
.invoice-paid-lead {
    margin: 0 auto 24px;
    max-width: 380px;
    color: var(--text-muted, #5b6472);
    line-height: 1.55;
}
.invoice-paid-actions {
    display: flex;
    gap: 10px;
    justify-content: center;
}
</style>
