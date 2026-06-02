<script setup>
import { ref, watch, onBeforeUnmount, nextTick } from 'vue';
import { IconX, IconAlertCircle, IconLoader2 } from '@tabler/icons-vue';

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
    <Teleport to="body">
        <div v-if="show" class="stripe-modal-overlay" @click.self="close">
            <div class="stripe-modal" role="dialog" aria-modal="true">
                <header class="stripe-modal-head">
                    <div>
                        <h3>Pay invoice {{ invoiceNumber }}</h3>
                        <div class="stripe-modal-sub">{{ gbp(invoiceTotal) }} · Secure payment by Stripe</div>
                    </div>
                    <button type="button" class="stripe-modal-close" aria-label="Close" @click="close">
                        <IconX :size="18" stroke-width="2" />
                    </button>
                </header>

                <div class="stripe-modal-body">
                    <div v-if="error" class="stripe-modal-error">
                        <IconAlertCircle :size="18" stroke-width="2" />
                        <span>{{ error }}</span>
                    </div>
                    <div v-else-if="loading" class="stripe-modal-loading">
                        <IconLoader2 :size="28" stroke-width="2" class="spin" />
                        <span>Loading secure payment form…</span>
                    </div>

                    <!-- Stripe Embedded Checkout mounts here. -->
                    <div ref="mountEl" class="stripe-mount" />
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.stripe-modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 1000;
    background: rgba(15, 23, 42, .55);
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 40px 16px;
    overflow-y: auto;
}
.stripe-modal {
    background: #fff;
    border-radius: 14px;
    width: 100%;
    max-width: 560px;
    box-shadow: 0 24px 60px rgba(15, 23, 42, .3);
    overflow: hidden;
}
.stripe-modal-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    padding: 18px 20px;
    border-bottom: 1px solid var(--border-soft, #eef2f7);
}
.stripe-modal-head h3 { margin: 0; font-size: 16px; }
.stripe-modal-sub { font-size: 12px; color: var(--text-muted, #64748b); margin-top: 3px; }
.stripe-modal-close {
    background: none;
    border: none;
    cursor: pointer;
    color: var(--text-muted, #64748b);
    padding: 4px;
    border-radius: 6px;
    line-height: 0;
}
.stripe-modal-close:hover { background: var(--neutral-bg, #f1f5f9); }
.stripe-modal-body { padding: 16px 20px 22px; min-height: 120px; }
.stripe-modal-loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    padding: 32px 0;
    color: var(--text-muted, #64748b);
    font-size: 13px;
}
.stripe-modal-error {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 14px;
    background: var(--danger-bg, #fee2e2);
    color: var(--danger, #b91c1c);
    border-radius: 8px;
    font-size: 13px;
}
.spin { animation: stripe-spin 0.8s linear infinite; }
@keyframes stripe-spin { to { transform: rotate(360deg); } }
</style>
