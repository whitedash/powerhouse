<script setup>
/**
 * Customer "Payment methods" page (Billing P1). Add a card via Stripe Elements
 * + a server SetupIntent, list, set-default, remove. Everything is scoped to
 * the portal user's own customer server-side. NO charge happens here.
 *
 * The card list renders in both the #desktop and #mobile shells; the add-card
 * Stripe Element lives in a single Teleport modal so it's only mounted once.
 */
import { ref, nextTick } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PaymentMethodList from '@/Components/Portal/PaymentMethodList.vue';

defineProps({
    payment_methods: { type: Array, default: () => [] },
});

const counts = { invoices: undefined, support: undefined };
const stripeKey = usePage().props.stripe_key || '';

const addOpen = ref(false);
const mountEl = ref(null);
const error = ref('');
const saving = ref(false);

let stripeJsPromise = null;
let stripe = null;
let elements = null;
let cardEl = null;

function loadStripeJs() {
    if (window.Stripe) return Promise.resolve(window.Stripe);
    if (stripeJsPromise) return stripeJsPromise;
    stripeJsPromise = new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = 'https://js.stripe.com/v3/';
        s.async = true;
        s.onload = () => resolve(window.Stripe);
        s.onerror = () => reject(new Error('Failed to load Stripe.js'));
        document.head.appendChild(s);
    });
    return stripeJsPromise;
}

async function openAdd() {
    error.value = '';
    if (! stripeKey) {
        error.value = 'Card payments are not available right now.';
        return;
    }
    addOpen.value = true;
    try {
        const Stripe = await loadStripeJs();
        stripe = Stripe(stripeKey);
        elements = stripe.elements();
        cardEl = elements.create('card', { hidePostalCode: false });
        await nextTick();
        if (mountEl.value) cardEl.mount(mountEl.value);
    } catch (e) {
        error.value = e.message || 'Could not load the card form.';
    }
}

function closeAdd() {
    addOpen.value = false;
    error.value = '';
    if (cardEl) { cardEl.destroy(); cardEl = null; }
    elements = null;
    stripe = null;
}

async function saveCard() {
    if (! stripe || ! cardEl) return;
    saving.value = true;
    error.value = '';
    try {
        // 1. Mint a SetupIntent on the server (scoped to our customer).
        const res = await fetch('/portal/payment-methods/setup-intent', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            credentials: 'same-origin',
        });
        if (! res.ok) throw new Error('Could not start adding a card.');
        const { client_secret: clientSecret } = await res.json();

        // 2. Confirm the card client-side — it's vaulted against our Stripe customer.
        const result = await stripe.confirmCardSetup(clientSecret, { payment_method: { card: cardEl } });
        if (result.error) throw new Error(result.error.message || 'Card could not be saved.');

        const pmId = result.setupIntent?.payment_method;
        if (! pmId) throw new Error('Card could not be saved.');

        // 3. Persist the safe metadata server-side, then refresh the list.
        router.post('/portal/payment-methods', { payment_method_id: pmId }, {
            preserveScroll: true,
            onSuccess: () => { closeAdd(); router.reload({ only: ['payment_methods'] }); },
            onError: () => { error.value = 'We could not save that card. Please try again.'; },
            onFinish: () => { saving.value = false; },
        });
    } catch (e) {
        error.value = e.message || 'Something went wrong.';
        saving.value = false;
    }
}

function setDefault(id) {
    router.post(`/portal/payment-methods/${id}/default`, {}, { preserveScroll: true });
}
function remove(id) {
    router.delete(`/portal/payment-methods/${id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Payment methods" />

    <PortalLayout active-nav="payment-methods" :counts="counts">
        <template #desktop>
            <div class="content">
                <PaymentMethodList :payment-methods="payment_methods" @add="openAdd" @set-default="setDefault" @remove="remove" />
            </div>
        </template>

        <template #mobile>
            <div class="m-appbar"><div class="title">Payment methods</div><div class="spacer" /></div>
            <div class="m-body is-pb">
                <PaymentMethodList :payment-methods="payment_methods" @add="openAdd" @set-default="setDefault" @remove="remove" />
            </div>
        </template>
    </PortalLayout>

    <!-- Single add-card modal (Stripe Element mounted once). -->
    <Teleport to="body">
        <div v-if="addOpen" class="portal-modal-backdrop" @click="closeAdd" />
        <div v-if="addOpen" class="portal-modal pm-modal" role="dialog" aria-modal="true">
            <header class="portal-modal-header">
                <div>
                    <div class="portal-modal-eyebrow">Add a card</div>
                    <h2>New payment method</h2>
                </div>
                <button type="button" class="icon-btn" @click="closeAdd">×</button>
            </header>
            <div class="portal-modal-body">
                <div ref="mountEl" class="pm-card-element" />
                <p class="pm-secure"><i class="ti ti-lock" /> Card details are handled by Stripe — we never see your full card number.</p>
                <div v-if="error" class="portal-flash error">{{ error }}</div>
            </div>
            <footer class="portal-modal-footer">
                <button type="button" class="btn btn-secondary" @click="closeAdd">Cancel</button>
                <button type="button" class="btn btn-primary" :disabled="saving" @click="saveCard">
                    {{ saving ? 'Saving…' : 'Save card' }}
                </button>
            </footer>
        </div>
    </Teleport>
</template>

<style scoped>
.pm-card-element { padding: 14px; border: 1px solid var(--border, #e2e8f0); border-radius: 8px; background: #fff; }
.pm-secure { font: 400 12px/1.45 'Inter', sans-serif; color: var(--text-tertiary, #94a3b8); margin: 10px 0 0; display: flex; align-items: center; gap: 6px; }
</style>
