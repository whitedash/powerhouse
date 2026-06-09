<script setup>
import { computed, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import StripeCheckoutModal from '@/Components/Portal/StripeCheckoutModal.vue';
import { useBackNavigation } from '@/Composables/useBackNavigation';

const props = defineProps({
    invoice: { type: Object, required: true },
});

const { goBack } = useBackNavigation('/portal/invoices');

const stripeKey = computed(() => usePage().props.stripe_key || '');

function gbp(n) {
    return Number(n ?? 0).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function statusBadge(status) {
    if (status === 'paid') return { cls: 'badge-paid', label: 'Paid' };
    if (status === 'overdue') return { cls: 'badge-overdue', label: 'Overdue' };
    if (status === 'sent') return { cls: 'badge-awaiting', label: 'Awaiting payment' };
    if (status === 'partially_paid') return { cls: 'badge-awaiting', label: 'Partially paid' };
    if (status === 'draft') return { cls: 'badge-neutral', label: 'Draft' };
    if (status === 'void') return { cls: 'badge-neutral', label: 'Void' };
    return { cls: 'badge-neutral', label: status };
}

const isPaid = computed(() => props.invoice.status === 'paid');

const checkoutOpen = ref(false);
function openPay() {
    checkoutOpen.value = true;
}
</script>

<template>
    <Head :title="`Invoice ${invoice.number} · Whitedash`" />
    <PortalLayout active-nav="invoices">
        <!-- ═══════════ DESKTOP ═══════════ -->
        <template #desktop>
            <div class="content narrow">
                <a class="back-link" href="#" @click.prevent="goBack"><i class="ti ti-arrow-left" />Back to invoices</a>

                <div class="inv-doc">
                    <div class="inv-doc-head">
                        <div class="l">
                            <div class="brand-mark" style="width:44px;height:44px;border-radius:11px;font-size:20px">W</div>
                            <div>
                                <div class="num-word">{{ invoice.number }}</div>
                                <div class="biller"><template v-if="invoice.billing_entity">{{ invoice.billing_entity }} · </template>billed via Whitedash</div>
                            </div>
                        </div>
                        <span class="badge badge-lg" :class="statusBadge(invoice.status).cls">{{ statusBadge(invoice.status).label }}</span>
                    </div>

                    <div class="inv-doc-meta">
                        <div><div class="k">Issued</div><div class="v">{{ invoice.issue_date ?? '—' }}</div></div>
                        <div><div class="k">{{ isPaid ? 'Paid' : 'Due' }}</div><div class="v">{{ (isPaid ? invoice.paid_at : invoice.due_date) ?? '—' }}</div></div>
                        <div>
                            <div class="k">Billed to</div>
                            <div class="v">
                                {{ invoice.billed_to_name ?? '—' }}
                                <div v-if="invoice.billed_to_address" style="font-weight:400;color:var(--text-secondary);font-size:13px;margin-top:2px">{{ invoice.billed_to_address }}</div>
                            </div>
                        </div>
                        <div><div class="k">Payment reference</div><div class="v mono">{{ invoice.number }}</div></div>
                    </div>

                    <table class="li-table">
                        <thead><tr><th>Description</th><th class="r">Qty</th><th class="r">Amount</th></tr></thead>
                        <tbody>
                            <tr v-for="line in invoice.lines" :key="line.id">
                                <td class="desc">{{ line.description }}<div v-if="line.note" class="note">{{ line.note }}</div></td>
                                <td class="r n">{{ line.quantity }}</td>
                                <td class="r amt">£{{ gbp(line.amount) }}</td>
                            </tr>
                            <tr v-if="!invoice.lines.length"><td colspan="3" style="text-align:center;color:var(--text-secondary)">No line items.</td></tr>
                        </tbody>
                    </table>

                    <div class="inv-totals">
                        <div class="box">
                            <div class="ln">Subtotal<span class="v">£{{ gbp(invoice.subtotal) }}</span></div>
                            <div v-if="invoice.vat_amount > 0" class="ln">VAT ({{ Math.round(invoice.vat_rate) }}%)<span class="v">£{{ gbp(invoice.vat_amount) }}</span></div>
                            <div class="rule" />
                            <div class="total"><span class="lbl">Total</span><span class="v">£{{ gbp(invoice.total) }}</span></div>
                            <div v-if="invoice.amount_paid > 0 && !isPaid" class="ln" style="margin-top:6px;color:var(--accent);font-weight:600">Amount due<span class="v" style="color:var(--accent)">£{{ gbp(invoice.amount_due) }}</span></div>
                        </div>
                    </div>
                </div>

                <div v-if="isPaid" class="paid-banner" style="margin-top:20px">
                    <div class="ic"><i class="ti ti-check" /></div>
                    <div><div class="t">Paid in full</div><div class="s">This invoice was paid<template v-if="invoice.paid_at"> on {{ invoice.paid_at }}</template>.</div></div>
                </div>

                <div v-if="invoice.bank" class="inv-totals" style="margin-top:16px">
                    <div class="box">
                        <div class="ln" style="font-weight:600;color:var(--text-primary)">Bank transfer</div>
                        <div v-if="invoice.bank.bank_name" class="ln">Bank<span class="v">{{ invoice.bank.bank_name }}</span></div>
                        <div v-if="invoice.bank.account_name" class="ln">Account name<span class="v">{{ invoice.bank.account_name }}</span></div>
                        <div v-if="invoice.bank.sort_code" class="ln">Sort code<span class="v">{{ invoice.bank.sort_code }}</span></div>
                        <div v-if="invoice.bank.account_number" class="ln">Account no<span class="v">{{ invoice.bank.account_number }}</span></div>
                        <div v-if="invoice.bank.iban" class="ln">IBAN<span class="v">{{ invoice.bank.iban }}</span></div>
                        <div v-if="invoice.bank.bic" class="ln">BIC / SWIFT<span class="v">{{ invoice.bank.bic }}</span></div>
                        <div class="ln">Reference<span class="v">{{ invoice.number }}</span></div>
                    </div>
                </div>

                <div class="action-row">
                    <button v-if="invoice.is_payable" class="btn btn-primary btn-lg" @click="openPay"><i class="ti ti-credit-card" />Pay £{{ gbp(invoice.amount_due) }}</button>
                    <a :href="`/portal/invoices/${invoice.id}/preview-pdf`" target="_blank" rel="noopener" class="btn btn-secondary btn-lg"><i class="ti ti-external-link" />View PDF</a>
                    <a :href="`/portal/invoices/${invoice.id}/pdf`" class="btn btn-secondary btn-lg"><i class="ti ti-download" />Download</a>
                </div>
            </div>
        </template>

        <!-- ═══════════ MOBILE ═══════════ -->
        <template #mobile>
            <div class="m-appbar">
                <span class="m-back" @click="goBack"><i class="ti ti-chevron-left" />Invoices</span>
                <div class="spacer" />
                <div class="title" style="font-size:16px">{{ invoice.number }}</div>
                <div class="spacer" />
                <div class="bell" style="width:32px"><i class="ti ti-dots" style="font-size:20px" /></div>
            </div>
            <div class="m-body is-pb">
                <div class="m-card">
                    <div class="cb" style="display:flex;align-items:center;justify-content:space-between">
                        <div style="display:flex;align-items:center;gap:12px">
                            <div class="brand-mark" style="width:38px;height:38px;border-radius:10px;font-size:17px">W</div>
                            <div><div style="font:700 18px/1 'Inter'">{{ invoice.number }}</div><div style="font:400 12px/1.3 'Inter';color:var(--text-secondary);margin-top:4px">{{ invoice.billing_entity ?? 'Whitedash' }}</div></div>
                        </div>
                        <span class="badge" :class="statusBadge(invoice.status).cls">{{ statusBadge(invoice.status).label }}</span>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;padding:16px;background:#FBFCFE;border-top:1px solid var(--border-soft);border-bottom:1px solid var(--border-soft)">
                        <div><div style="font:500 10.5px/1 'Inter';text-transform:uppercase;letter-spacing:.1em;color:var(--text-tertiary)">Issued</div><div style="font:600 13.5px/1.3 'Inter';margin-top:5px">{{ invoice.issue_date ?? '—' }}</div></div>
                        <div><div style="font:500 10.5px/1 'Inter';text-transform:uppercase;letter-spacing:.1em;color:var(--text-tertiary)">{{ isPaid ? 'Paid' : 'Due' }}</div><div style="font:600 13.5px/1.3 'Inter';margin-top:5px">{{ (isPaid ? invoice.paid_at : invoice.due_date) ?? '—' }}</div></div>
                    </div>
                    <div class="cb">
                        <div v-for="line in invoice.lines" :key="line.id" style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:12px">
                            <div><div style="font:600 14px/1.4 'Inter'">{{ line.description }}</div><div v-if="line.note" style="font:400 12px/1.4 'Inter';color:var(--text-secondary);margin-top:3px">{{ line.note }} · Qty {{ line.quantity }}</div></div>
                            <div style="font:600 14px/1 'Inter';font-variant-numeric:tabular-nums">£{{ gbp(line.amount) }}</div>
                        </div>
                        <div style="height:1px;background:var(--border-soft);margin:16px 0" />
                        <div style="display:flex;flex-direction:column;gap:9px">
                            <div style="display:flex;justify-content:space-between;font:400 13.5px/1 'Inter';color:var(--text-secondary)">Subtotal<span style="color:var(--text-primary);font-weight:500">£{{ gbp(invoice.subtotal) }}</span></div>
                            <div v-if="invoice.vat_amount > 0" style="display:flex;justify-content:space-between;font:400 13.5px/1 'Inter';color:var(--text-secondary)">VAT ({{ Math.round(invoice.vat_rate) }}%)<span style="color:var(--text-primary);font-weight:500">£{{ gbp(invoice.vat_amount) }}</span></div>
                            <div style="height:1px;background:var(--border);margin:3px 0" />
                            <div style="display:flex;justify-content:space-between;align-items:baseline"><span style="font:700 15px/1 'Inter'">Total</span><span style="font:700 20px/1 'Inter';letter-spacing:-.02em">£{{ gbp(invoice.total) }}</span></div>
                        </div>
                    </div>
                </div>

                <div v-if="invoice.bank" class="m-card">
                    <div class="cb">
                        <div style="font:600 12px/1 'Inter';text-transform:uppercase;letter-spacing:.08em;color:var(--text-tertiary);margin-bottom:10px">Bank transfer</div>
                        <div style="display:flex;flex-direction:column;gap:8px">
                            <div v-if="invoice.bank.bank_name" style="display:flex;justify-content:space-between;gap:12px;font:400 13px/1.3 'Inter';color:var(--text-secondary)">Bank<span style="color:var(--text-primary);text-align:right">{{ invoice.bank.bank_name }}</span></div>
                            <div v-if="invoice.bank.account_name" style="display:flex;justify-content:space-between;gap:12px;font:400 13px/1.3 'Inter';color:var(--text-secondary)">Account name<span style="color:var(--text-primary);text-align:right">{{ invoice.bank.account_name }}</span></div>
                            <div v-if="invoice.bank.sort_code" style="display:flex;justify-content:space-between;gap:12px;font:400 13px/1.3 'Inter';color:var(--text-secondary)">Sort code<span style="color:var(--text-primary);font-variant-numeric:tabular-nums">{{ invoice.bank.sort_code }}</span></div>
                            <div v-if="invoice.bank.account_number" style="display:flex;justify-content:space-between;gap:12px;font:400 13px/1.3 'Inter';color:var(--text-secondary)">Account no<span style="color:var(--text-primary);font-variant-numeric:tabular-nums">{{ invoice.bank.account_number }}</span></div>
                            <div v-if="invoice.bank.iban" style="display:flex;justify-content:space-between;gap:12px;font:400 13px/1.3 'Inter';color:var(--text-secondary)">IBAN<span style="color:var(--text-primary);text-align:right;word-break:break-all">{{ invoice.bank.iban }}</span></div>
                            <div v-if="invoice.bank.bic" style="display:flex;justify-content:space-between;gap:12px;font:400 13px/1.3 'Inter';color:var(--text-secondary)">BIC / SWIFT<span style="color:var(--text-primary);text-align:right">{{ invoice.bank.bic }}</span></div>
                            <div style="display:flex;justify-content:space-between;gap:12px;font:400 13px/1.3 'Inter';color:var(--text-secondary)">Reference<span style="color:var(--text-primary);font-weight:600;font-variant-numeric:tabular-nums">{{ invoice.number }}</span></div>
                        </div>
                    </div>
                </div>

                <button v-if="invoice.is_payable" class="btn btn-primary btn-block btn-lg" @click="openPay"><i class="ti ti-credit-card" />Pay £{{ gbp(invoice.amount_due) }}</button>
                <div style="display:flex;gap:10px">
                    <a :href="`/portal/invoices/${invoice.id}/preview-pdf`" target="_blank" rel="noopener" class="btn btn-secondary btn-block btn-sm"><i class="ti ti-external-link" />View PDF</a>
                    <a :href="`/portal/invoices/${invoice.id}/pdf`" class="btn btn-secondary btn-block btn-sm"><i class="ti ti-download" />Download</a>
                </div>
            </div>
        </template>
    </PortalLayout>

    <StripeCheckoutModal
        v-model:show="checkoutOpen"
        :invoice-id="invoice.id"
        :invoice-number="invoice.number"
        :invoice-total="invoice.amount_due"
        :stripe-key="stripeKey"
    />
</template>
