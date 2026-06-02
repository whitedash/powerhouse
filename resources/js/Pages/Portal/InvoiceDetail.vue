<script setup>
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    IconReceipt,
    IconCreditCard,
    IconDownload,
    IconChevronLeft,
    IconExternalLink,
} from '@tabler/icons-vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import StripeCheckoutModal from '@/Components/Portal/StripeCheckoutModal.vue';

const props = defineProps({
    invoice: { type: Object, required: true },
});

const stripeKey = computed(() => usePage().props.stripe_key || '');

function gbp(n) {
    return `£${Number(n).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function statusBadge(status) {
    if (status === 'paid') return { cls: 'badge-active', label: 'Paid' };
    if (status === 'overdue') return { cls: 'badge-overdue', label: 'Overdue' };
    if (status === 'sent') return { cls: 'badge-pending', label: 'Awaiting payment' };
    if (status === 'partially_paid') return { cls: 'badge-pending', label: 'Partially paid' };
    if (status === 'draft') return { cls: 'badge-soon', label: 'Draft' };
    if (status === 'void') return { cls: 'badge-soon', label: 'Void' };
    return { cls: 'badge-soon', label: status };
}

const checkoutOpen = ref(false);
function openPay() {
    checkoutOpen.value = true;
}
</script>

<template>
    <Head :title="`Invoice ${invoice.number} · Whitedash`" />
    <PortalLayout title="Invoices" active-nav="invoices">
        <Link href="/portal/invoices" class="inv-back">
            <IconChevronLeft :size="15" stroke-width="2" />
            Back to invoices
        </Link>

        <div class="card inv-detail-card">
            <div class="inv-detail-head">
                <div class="inv-detail-title">
                    <div class="inv-ic" :class="invoice.status === 'paid' ? 'green' : 'muted'">
                        <IconReceipt :size="20" stroke-width="1.75" />
                    </div>
                    <div>
                        <h2>{{ invoice.number }}</h2>
                        <div class="inv-detail-sub">{{ invoice.billing_entity ?? 'Invoice' }}</div>
                    </div>
                </div>
                <span class="badge" :class="statusBadge(invoice.status).cls">{{ statusBadge(invoice.status).label }}</span>
            </div>

            <div class="inv-detail-meta">
                <div><span class="k">Issued</span><span class="v">{{ invoice.issue_date ?? '—' }}</span></div>
                <div><span class="k">Due</span><span class="v">{{ invoice.due_date ?? '—' }}</span></div>
                <div v-if="invoice.paid_at"><span class="k">Paid</span><span class="v">{{ invoice.paid_at }}</span></div>
            </div>

            <!-- Line items -->
            <table class="inv-lines">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th class="num">Qty</th>
                        <th class="num">Unit price</th>
                        <th class="num">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="line in invoice.lines" :key="line.id">
                        <td>
                            <div class="desc">{{ line.description }}</div>
                            <div v-if="line.note" class="desc-note">{{ line.note }}</div>
                        </td>
                        <td class="num">{{ line.quantity }}</td>
                        <td class="num">{{ gbp(line.unit_price) }}</td>
                        <td class="num">{{ gbp(line.amount) }}</td>
                    </tr>
                    <tr v-if="!invoice.lines.length">
                        <td colspan="4" class="lines-empty">No line items.</td>
                    </tr>
                </tbody>
            </table>

            <!-- Totals -->
            <div class="inv-totals">
                <div><span>Subtotal</span><span>{{ gbp(invoice.subtotal) }}</span></div>
                <div><span>VAT ({{ Math.round(invoice.vat_rate) }}%)</span><span>{{ gbp(invoice.vat_amount) }}</span></div>
                <div class="inv-total-final"><span>Total</span><span>{{ gbp(invoice.total) }}</span></div>
                <div v-if="invoice.amount_paid > 0 && invoice.status !== 'paid'" class="inv-total-due">
                    <span>Amount due</span><span>{{ gbp(invoice.amount_due) }}</span>
                </div>
            </div>

            <!-- Actions -->
            <div class="inv-detail-actions">
                <button
                    v-if="invoice.is_payable"
                    type="button"
                    class="btn-primary"
                    @click="openPay"
                >
                    <IconCreditCard :size="16" stroke-width="1.75" />
                    Pay {{ gbp(invoice.amount_due) }}
                </button>
                <a :href="`/portal/invoices/${invoice.id}/preview-pdf`" target="_blank" rel="noopener" class="btn-ghost">
                    <IconExternalLink :size="15" stroke-width="1.75" />
                    View PDF
                </a>
                <a :href="`/portal/invoices/${invoice.id}/pdf`" class="btn-ghost">
                    <IconDownload :size="15" stroke-width="1.75" />
                    Download
                </a>
            </div>
        </div>

        <StripeCheckoutModal
            v-model:show="checkoutOpen"
            :invoice-id="invoice.id"
            :invoice-number="invoice.number"
            :invoice-total="invoice.amount_due"
            :stripe-key="stripeKey"
        />
    </PortalLayout>
</template>

<style scoped>
.inv-back {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 13px;
    color: var(--text-muted, #64748b);
    text-decoration: none;
    margin-bottom: 14px;
}
.inv-back:hover { color: var(--accent); }
.inv-detail-card { max-width: 720px; padding: 24px; }
.inv-detail-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 18px;
}
.inv-detail-title { display: flex; align-items: center; gap: 12px; }
.inv-ic {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border-radius: 10px;
}
.inv-ic.green { background: var(--success-bg, #e6f6ec); color: var(--success, #1a8245); }
.inv-ic.muted { background: var(--neutral-bg, #f1f5f9); color: var(--text-muted, #64748b); }
.inv-detail-title h2 { margin: 0; font-size: 18px; }
.inv-detail-sub { font-size: 13px; color: var(--text-muted, #64748b); margin-top: 2px; }
.inv-detail-meta {
    display: flex;
    gap: 28px;
    padding: 14px 0;
    border-top: 1px solid var(--border-soft, #eef2f7);
    border-bottom: 1px solid var(--border-soft, #eef2f7);
    margin-bottom: 18px;
}
.inv-detail-meta .k {
    display: block;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--text-tertiary, #94a3b8);
    margin-bottom: 2px;
}
.inv-detail-meta .v { font-size: 14px; color: var(--text-primary, #0f172a); font-weight: 500; }
.inv-lines { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
.inv-lines th {
    text-align: left;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--text-tertiary, #94a3b8);
    padding: 8px 10px;
    border-bottom: 1px solid var(--border-soft, #eef2f7);
}
.inv-lines th.num, .inv-lines td.num { text-align: right; white-space: nowrap; }
.inv-lines td { padding: 10px; border-bottom: 1px solid var(--border-soft, #eef2f7); font-size: 14px; vertical-align: top; }
.inv-lines .desc { color: var(--text-primary, #0f172a); }
.inv-lines .desc-note { font-size: 12px; color: var(--text-muted, #64748b); margin-top: 2px; }
.lines-empty { text-align: center; color: var(--text-muted, #64748b); font-style: italic; }
.inv-totals { margin-left: auto; width: 280px; }
.inv-totals > div {
    display: flex;
    justify-content: space-between;
    font-size: 14px;
    color: var(--text-secondary, #475569);
    padding: 5px 10px;
}
.inv-total-final {
    font-weight: 700;
    font-size: 16px !important;
    color: var(--text-primary, #0f172a) !important;
    border-top: 1px solid var(--border, #e2e8f0);
    margin-top: 4px;
    padding-top: 10px !important;
}
.inv-total-due { color: var(--accent) !important; font-weight: 600; }
.inv-detail-actions {
    display: flex;
    gap: 10px;
    margin-top: 24px;
    padding-top: 18px;
    border-top: 1px solid var(--border-soft, #eef2f7);
}
</style>
