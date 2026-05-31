<script setup>
/**
 * Public "Account suspended" page — NO layout, no auth chrome.
 *
 * Rendered by the OAuth authorize-route middleware when a customer
 * tries to reach a product whose subscription is suspended, or via the
 * direct /oauth/suspended deep-link. Branded, minimal; mirrors the
 * self-contained style of ProposalView.vue.
 *
 * Payment is intentionally a placeholder — the pay button is wired up
 * once the Stripe sprint ships (stripe_enabled flips to true then).
 */
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { IconAlertTriangle, IconMail } from '@tabler/icons-vue';

const props = defineProps({
    product: { type: Object, required: true },
    plan: { type: String, default: null },
    suspension_reason: { type: String, default: 'non_payment' },
    invoices: { type: Array, default: () => [] },
    total_outstanding: { type: Number, default: 0 },
    support_email: { type: String, default: null },
    stripe_enabled: { type: Boolean, default: false },
});

function money(n) {
    return `£${Number(n || 0).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function initial(name) {
    return (name?.[0] ?? '?').toUpperCase();
}

const accent = computed(() => props.product?.icon_colour || '#0F172A');
const productName = computed(() => props.product?.name || 'your account');
</script>

<template>
    <Head :title="`Account Suspended · ${productName}`" />

    <div class="suspension-page">
        <div class="sp-card">
            <!-- Header -->
            <div class="sp-head">
                <div class="sp-logo" :style="{ background: accent }">{{ initial(product?.name) }}</div>
                <div class="sp-product">{{ productName }}</div>
                <div class="sp-status">
                    <IconAlertTriangle :size="15" stroke-width="2" />
                    Account Suspended
                </div>
            </div>

            <!-- NON-PAYMENT -->
            <template v-if="suspension_reason === 'non_payment'">
                <p class="sp-reason">
                    Your {{ productName }} account has been suspended due to an
                    outstanding balance on your account.
                </p>

                <div v-if="invoices.length" class="sp-invoices">
                    <div
                        v-for="inv in invoices"
                        :key="inv.id"
                        class="sp-inv-row"
                        :class="inv.days_overdue > 30 ? 'overdue-bad' : (inv.days_overdue > 0 ? 'overdue' : '')"
                    >
                        <span class="sp-inv-num">{{ inv.number }}</span>
                        <span class="sp-inv-due">Due {{ inv.due_date }}</span>
                        <span class="sp-inv-amt">{{ money(inv.outstanding) }}</span>
                    </div>
                </div>

                <div class="sp-total">
                    <span class="sp-total-label">Total outstanding</span>
                    <span class="sp-total-amt">{{ money(total_outstanding) }}</span>
                </div>

                <div class="sp-payment">
                    <template v-if="stripe_enabled">
                        <!-- Wired up by the Stripe sprint. -->
                        <button type="button" class="sp-pay-btn">Pay {{ money(total_outstanding) }} now →</button>
                    </template>
                    <div v-else class="sp-payment-placeholder">
                        <p>To restore access, please pay the outstanding balance.</p>
                        <p>You can pay by bank transfer using the details on your
                            invoice, or contact us to arrange payment.</p>
                    </div>
                </div>
            </template>

            <!-- MANUAL -->
            <template v-else-if="suspension_reason === 'manual'">
                <p class="sp-reason">
                    Your account has been suspended. Please contact us to restore access.
                </p>
            </template>

            <!-- TRIAL ENDED -->
            <template v-else-if="suspension_reason === 'trial_ended'">
                <p class="sp-reason">
                    Your free trial has ended. Choose a plan to continue using
                    {{ productName }}.
                </p>
                <a href="/portal/dashboard" class="sp-pay-btn as-link">View plans →</a>
            </template>

            <!-- OTHER / FRAUD / fallback -->
            <template v-else>
                <p class="sp-reason">
                    Your account has been suspended. Please contact us for more
                    information.
                </p>
            </template>

            <!-- Contact (always) -->
            <div class="sp-contact">
                <p>Need help? Get in touch:</p>
                <a v-if="support_email" :href="`mailto:${support_email}`" class="sp-contact-link">
                    <IconMail :size="15" stroke-width="2" />
                    Email us
                </a>
            </div>

            <div class="sp-footer">Powered by Powerhouse · Whitedash Holdings</div>
        </div>
    </div>
</template>
