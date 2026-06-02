<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';

const props = defineProps({
    invoice: { type: Object, required: true },
});

function gbp(n) {
    return Number(n ?? 0).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// The webhook is authoritative and this page also confirms the session
// with Stripe on load, so "paid" is the normal case. Until it lands we
// show a softer "processing" state rather than claiming payment.
const isPaid = computed(() => props.invoice.status === 'paid');

const methodLabel = computed(() => {
    const m = props.invoice.paid_via;
    if (! m) return 'Card';
    return { stripe: 'Card (Stripe)', manual: 'Recorded by Whitedash', bank: 'Bank transfer' }[m] ?? m;
});
const reference = computed(() => props.invoice.payment_ref || props.invoice.number);
</script>

<template>
    <Head title="Payment · Whitedash" />
    <PortalLayout active-nav="invoices" :mobile-tabbar="false">
        <!-- ═══════════ DESKTOP ═══════════ -->
        <template #desktop>
            <div class="content" style="max-width:520px;display:flex;align-items:center;justify-content:center;padding-top:56px">
                <div class="card card-pad" style="width:100%;padding:40px 36px">
                    <div class="confirm">
                        <div class="check" :style="!isPaid ? 'background:var(--warning-bg)' : ''">
                            <i class="ti" :class="isPaid ? 'ti-check' : 'ti-clock'" :style="!isPaid ? 'color:var(--accent)' : ''" />
                        </div>
                        <h2>{{ isPaid ? 'Payment successful' : 'Payment processing' }}</h2>
                        <div class="amt-line">{{ invoice.number }}<span style="color:var(--text-tertiary);margin:0 8px">·</span><b>£{{ gbp(invoice.total) }}</b></div>

                        <div v-if="isPaid" class="confirm-detail">
                            <div v-if="invoice.paid_at" class="row">Paid on<span class="v">{{ invoice.paid_at }}</span></div>
                            <div class="row">Payment method<span class="v">{{ methodLabel }}</span></div>
                            <div class="row">Transaction ref<span class="v mono">{{ reference }}</span></div>
                        </div>
                        <p v-else class="amt-line" style="max-width:360px">
                            This usually takes a few moments — the invoice status updates automatically once it clears.
                        </p>

                        <div v-if="isPaid && invoice.receipt_email" class="emailed"><i class="ti ti-mail" />A receipt has been emailed to {{ invoice.receipt_email }}</div>

                        <div class="btns">
                            <Link :href="`/portal/invoices/${invoice.id}`" class="btn btn-secondary btn-lg">View invoice</Link>
                            <Link href="/portal/invoices" class="btn btn-primary btn-lg">Back to invoices</Link>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- ═══════════ MOBILE ═══════════ -->
        <template #mobile>
            <div class="m-body" style="justify-content:center;padding:24px 20px 40px">
                <div class="confirm">
                    <div class="check" :style="!isPaid ? 'background:var(--warning-bg)' : ''">
                        <i class="ti" :class="isPaid ? 'ti-check' : 'ti-clock'" :style="!isPaid ? 'color:var(--accent)' : ''" />
                    </div>
                    <h2>{{ isPaid ? 'Payment successful' : 'Payment processing' }}</h2>
                    <div class="amt-line">{{ invoice.number }}<span style="color:var(--text-tertiary);margin:0 8px">·</span><b>£{{ gbp(invoice.total) }}</b></div>

                    <div v-if="isPaid" class="confirm-detail">
                        <div v-if="invoice.paid_at" class="row">Paid on<span class="v">{{ invoice.paid_at }}</span></div>
                        <div class="row">Method<span class="v">{{ methodLabel }}</span></div>
                        <div class="row">Ref<span class="v mono">{{ reference }}</span></div>
                    </div>

                    <div v-if="isPaid && invoice.receipt_email" class="emailed"><i class="ti ti-mail" />Receipt emailed to {{ invoice.receipt_email }}</div>

                    <div class="btns" style="flex-direction:column;width:100%">
                        <Link href="/portal/invoices" class="btn btn-primary btn-block btn-lg">Back to invoices</Link>
                        <Link :href="`/portal/invoices/${invoice.id}`" class="btn btn-secondary btn-block btn-lg">View invoice</Link>
                    </div>
                </div>
            </div>
        </template>
    </PortalLayout>
</template>
