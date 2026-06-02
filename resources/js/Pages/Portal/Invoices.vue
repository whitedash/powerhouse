<script setup>
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    IconDownload,
    IconReceipt,
    IconAlertCircle,
    IconCreditCard,
} from '@tabler/icons-vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import StripeCheckoutModal from '@/Components/Portal/StripeCheckoutModal.vue';

const props = defineProps({
    invoices: { type: Object, required: true },
    summary: { type: Object, default: () => ({ total_outstanding: 0, overdue_count: 0 }) },
});

const counts = computed(() => ({
    invoices: props.summary.overdue_count || undefined,
}));

function gbp(n) {
    return `£${Number(n).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

const stripeKey = computed(() => usePage().props.stripe_key || '');

const payableStatuses = ['sent', 'overdue', 'partially_paid'];

// Embedded Stripe Checkout renders inline in a modal (no redirect off-site).
const checkoutOpen = ref(false);
const activeInvoice = ref(null);

function payInvoice(inv) {
    activeInvoice.value = inv;
    checkoutOpen.value = true;
}

function statusBadge(status) {
    if (status === 'paid') return { cls: 'badge-active', label: 'Paid' };
    if (status === 'overdue') return { cls: 'badge-overdue', label: 'Overdue' };
    if (status === 'sent') return { cls: 'badge-pending', label: 'Awaiting payment' };
    if (status === 'draft') return { cls: 'badge-soon', label: 'Draft' };
    if (status === 'void') return { cls: 'badge-soon', label: 'Void' };
    return { cls: 'badge-soon', label: status };
}
</script>

<template>
    <Head title="Invoices · Whitedash" />
    <PortalLayout title="Invoices" active-nav="invoices" :counts="counts">
        <div class="portal-section-head">
            <div class="col-l">
                <h2>Invoices</h2>
                <div class="desc">{{ invoices.total }} invoice{{ invoices.total === 1 ? '' : 's' }} on your account.</div>
            </div>
            <div v-if="summary.total_outstanding > 0" class="portal-outstanding-pill">
                <IconAlertCircle :size="14" stroke-width="2" />
                <strong>{{ gbp(summary.total_outstanding) }}</strong> outstanding
            </div>
        </div>

        <div class="card">
            <div v-if="invoices.data.length === 0" class="portal-empty-inline">
                <IconReceipt :size="24" stroke-width="1.5" />
                <span>No invoices yet.</span>
            </div>
            <template v-else>
                <!--
                  Rows are anchor-styled so the whole strip is clickable —
                  opens the PDF preview in a new tab, which is the most
                  useful action for a customer landing on this list. The
                  explicit Download button stops propagation so clicking
                  "PDF" doesn't double-fire both the preview and download.
                -->
                <a
                    v-for="inv in invoices.data"
                    :key="inv.id"
                    :href="`/portal/invoices/${inv.id}/preview-pdf`"
                    target="_blank"
                    rel="noopener"
                    class="inv-row inv-row-clickable"
                >
                    <div class="inv-ic" :class="inv.status === 'paid' ? 'green' : 'muted'">
                        <IconReceipt :size="18" stroke-width="1.75" />
                    </div>
                    <div class="inv-meta">
                        <div class="ttl">{{ inv.billing_entity ?? 'Invoice' }} · {{ inv.number }}</div>
                        <div class="sub">
                            Issued {{ inv.issue_date }}
                            <template v-if="inv.due_date"> · Due {{ inv.due_date }}</template>
                        </div>
                    </div>
                    <div class="inv-right">
                        <div class="inv-amt">{{ gbp(inv.total) }}</div>
                        <span class="badge badge-sm" :class="statusBadge(inv.status).cls">
                            {{ statusBadge(inv.status).label }}
                        </span>
                        <button
                            v-if="payableStatuses.includes(inv.status)"
                            type="button"
                            class="inv-pay-btn"
                            @click.stop.prevent="payInvoice(inv)"
                        >
                            <IconCreditCard :size="13" stroke-width="1.75" />
                            Pay {{ gbp(inv.total) }}
                        </button>
                        <a
                            :href="`/portal/invoices/${inv.id}/pdf`"
                            class="ghost-link muted"
                            @click.stop
                        >
                            <IconDownload :size="13" stroke-width="1.75" />
                            PDF
                        </a>
                    </div>
                </a>
            </template>
        </div>

        <!-- Pagination -->
        <div v-if="invoices.last_page > 1" class="portal-pagination">
            <Link
                v-for="link in invoices.links"
                :key="link.label"
                :href="link.url || ''"
                class="portal-page-link"
                :class="{ active: link.active, disabled: !link.url }"
                v-html="link.label"
            />
        </div>

        <StripeCheckoutModal
            v-model:show="checkoutOpen"
            :invoice-id="activeInvoice?.id"
            :invoice-number="activeInvoice?.number || ''"
            :invoice-total="activeInvoice?.total || 0"
            :stripe-key="stripeKey"
        />
    </PortalLayout>
</template>
