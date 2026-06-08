<script setup>
import { ref, watch, onBeforeUnmount, nextTick } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    invoiceId: { type: [Number, String], default: null },
    invoiceNumber: { type: String, default: '' },
    invoiceTotal: { type: [Number, String], default: 0 },
    stripeKey: { type: String, default: '' },
});

const emit = defineEmits(['update:show']);

const loading = ref(false);
const error = ref('');
const mountEl = ref(null);

// The mounted Stripe Embedded Checkout instance — kept so we can destroy
// it on close (Stripe forbids mounting a second instance while one lives).
let checkout = null;

// Cache the Stripe.js loader promise so the CDN script is only injected
// once across every modal open.
let stripeJsPromise = null;

function loadStripeJs() {
    if (window.Stripe) return Promise.resolve(window.Stripe);
    if (stripeJsPromise) return stripeJsPromise;

    stripeJsPromise = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = 'https://js.stripe.com/v3/';
        script.async = true;
        script.onload = () => resolve(window.Stripe);
        script.onerror = () => reject(new Error('Failed to load Stripe.js'));
        document.head.appendChild(script);
    });

    return stripeJsPromise;
}

async function openCheckout() {
    error.value = '';
    loading.value = true;

    try {
        if (!props.stripeKey) {
            throw new Error('Online payment is not available right now.');
        }

        // 1. Fetch a fresh client_secret for this invoice.
        const res = await fetch(`/portal/invoices/${props.invoiceId}/checkout`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!res.ok) {
            throw new Error(res.status === 422
                ? 'This invoice is not awaiting payment.'
                : 'Could not start the payment. Please try again.');
        }
        const { client_secret: clientSecret } = await res.json();

        // 2. Load Stripe.js and mount the embedded form.
        const Stripe = await loadStripeJs();
        const stripe = Stripe(props.stripeKey);

        await nextTick();
        checkout = await stripe.initEmbeddedCheckout({ clientSecret });
        if (mountEl.value) {
            checkout.mount(mountEl.value);
        }
        // On completion Stripe redirects the whole page to the session's
        // return_url (portal.invoices.paid), so there's no inline success
        // state to manage here.
    } catch (e) {
        error.value = e.message || 'Something went wrong.';
    } finally {
        loading.value = false;
    }
}

function teardown() {
    if (checkout) {
        try { checkout.destroy(); } catch { /* already gone */ }
        checkout = null;
    }
    error.value = '';
    loading.value = false;
}

function close() {
    emit('update:show', false);
}

watch(() => props.show, (open) => {
    if (open) {
        openCheckout();
    } else {
        teardown();
    }
});

onBeforeUnmount(teardown);

function gbp(n) {
    return `£${Number(n).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}
</script>

<template>
    <!-- The handoff shows a mock card form; we keep real Stripe Embedded
         Checkout for PCI compliance and only adopt the handoff chrome
         (.pay-modal / .pay-head / .stripe-amount / .stripe-secure). The
         .wd wrapper scopes the handoff design tokens to this overlay. -->
    <Teleport to="body">
        <div v-if="show" class="wd pay-overlay" @click.self="close">
            <div class="pay-modal" role="dialog" aria-modal="true">
                <div class="pay-head">
                    <div>
                        <div class="ttl">Pay invoice {{ invoiceNumber }}</div>
                        <div class="sub">{{ gbp(invoiceTotal) }}<span style="color:var(--text-tertiary)">·</span>Secure payment by <b style="color:#635BFF;font-weight:700">Stripe</b></div>
                    </div>
                    <button type="button" class="icon-btn" aria-label="Close" @click="close"><i class="ti ti-x" /></button>
                </div>
                <!-- Two columns on desktop (summary | payment) to cut height
                     and remove the scroll; collapses to one column on
                     narrow/mobile widths. -->
                <div class="pay-body pay-cols">
                    <aside class="pay-summary">
                        <div class="stripe-amount"><span class="lbl">Amount due</span><span class="v">{{ gbp(invoiceTotal) }}</span></div>
                        <dl class="pay-meta">
                            <div class="pay-meta-row"><dt>Invoice</dt><dd>{{ invoiceNumber }}</dd></div>
                            <div class="pay-meta-row"><dt>Total</dt><dd>{{ gbp(invoiceTotal) }}</dd></div>
                        </dl>
                        <div class="stripe-secure"><i class="ti ti-lock" />Secured by <b>Stripe</b> · your card details are encrypted</div>
                    </aside>

                    <div class="pay-payment">
                        <div v-if="error" class="pay-error"><i class="ti ti-alert-circle" /><span>{{ error }}</span></div>
                        <div v-else-if="loading" class="pay-loading"><i class="ti ti-loader-2 spin" /><span>Loading secure payment form…</span></div>

                        <!-- Stripe Embedded Checkout mounts here. -->
                        <div ref="mountEl" class="stripe-mount" />
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
/* Fixed full-screen scroll overlay. The handoff .pay-modal is absolutely
   centred for a fixed-height mock; real Stripe Embedded Checkout is taller
   than the viewport, so we top-align the modal and let the overlay scroll
   (overriding the handoff's absolute centring). */
.pay-overlay {
    position: fixed;
    inset: 0;
    z-index: 1000;
    background: rgba(15, 23, 42, .55);
    backdrop-filter: blur(3px);
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 32px 16px;
    overflow-y: auto;
}
.pay-overlay .pay-modal {
    position: relative;
    top: auto;
    left: auto;
    transform: none;
    margin: auto;
    /* Wider than the 480px handoff so the summary + payment form sit
       side by side instead of stacking into a tall scroller. */
    width: min(820px, 100%);
    max-width: 100%;
}

/* Two-column body: a fixed summary rail + the flexible payment column. */
.pay-overlay .pay-cols {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 28px;
    align-items: start;
}
.pay-overlay .pay-summary {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
/* In the rail the amount reads as a stacked panel (label over figure),
   and the secure note left-aligns under it. */
.pay-overlay .pay-summary .stripe-amount {
    flex-direction: column;
    align-items: flex-start;
    gap: 6px;
    margin-bottom: 0;
}
.pay-overlay .pay-summary .stripe-secure {
    margin-top: 0;
    justify-content: flex-start;
    text-align: left;
}
.pay-meta {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin: 0;
    padding: 14px 16px;
    border: 1px solid var(--border-soft);
    border-radius: var(--radius-lg);
}
.pay-meta-row {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
    font: 400 12.5px/1.3 'Inter', sans-serif;
}
.pay-meta-row dt { color: var(--text-secondary); }
.pay-meta-row dd { margin: 0; font-weight: 600; color: var(--text-primary); font-variant-numeric: tabular-nums; }
/* min-width:0 lets the Stripe iframe column shrink instead of forcing
   the grid wider. */
.pay-overlay .pay-payment { min-width: 0; }

/* Collapse to a single column on narrow/mobile widths. */
@media (max-width: 720px) {
    .pay-overlay .pay-cols {
        grid-template-columns: 1fr;
        gap: 18px;
    }
    .pay-overlay .pay-summary .stripe-amount {
        flex-direction: row;
        align-items: baseline;
        justify-content: space-between;
    }
}
.pay-error {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 14px;
    margin-bottom: 14px;
    background: var(--danger-bg);
    color: #b91c1c;
    border-radius: var(--radius-md);
    font: 500 13px/1.4 'Inter', sans-serif;
}
.pay-error .ti { font-size: 18px; }
.pay-loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    padding: 28px 0;
    color: var(--text-secondary);
    font: 400 13px/1.4 'Inter', sans-serif;
}
.pay-loading .ti { font-size: 26px; }
.stripe-mount:empty { min-height: 0; }
.spin { animation: stripe-spin 0.8s linear infinite; display: inline-block; }
@keyframes stripe-spin { to { transform: rotate(360deg); } }
</style>
