<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import StripeCheckoutModal from '@/Components/Portal/StripeCheckoutModal.vue';

const props = defineProps({
    invoices: { type: Object, required: true },
    summary: { type: Object, default: () => ({ total_outstanding: 0, overdue_count: 0 }) },
    counts: { type: Object, default: () => ({ all: 0, outstanding: 0, paid: 0 }) },
    filter: { type: String, default: 'all' },
});

const navCounts = computed(() => ({
    invoices: props.summary.overdue_count || undefined,
}));

function gbp(n) {
    return Number(n ?? 0).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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

function openPdf(id) {
    window.open(`/portal/invoices/${id}/pdf`, '_blank', 'noopener');
}

function invBadge(status) {
    if (status === 'paid') return { cls: 'badge-paid', label: 'Paid' };
    if (status === 'overdue') return { cls: 'badge-overdue', label: 'Overdue' };
    if (status === 'sent') return { cls: 'badge-awaiting', label: 'Awaiting payment' };
    if (status === 'partially_paid') return { cls: 'badge-awaiting', label: 'Partially paid' };
    if (status === 'draft') return { cls: 'badge-neutral', label: 'Draft' };
    if (status === 'void') return { cls: 'badge-neutral', label: 'Void' };
    return { cls: 'badge-neutral', label: status };
}

const filterTabs = computed(() => [
    { key: 'all', label: 'All', count: props.counts.all },
    { key: 'outstanding', label: 'Outstanding', count: props.counts.outstanding },
    { key: 'paid', label: 'Paid', count: props.counts.paid },
]);

function setFilter(key) {
    router.get('/portal/invoices', key === 'all' ? {} : { filter: key }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}
</script>

<template>
    <Head title="Invoices · Whitedash" />
    <PortalLayout active-nav="invoices" :counts="navCounts">
        <!-- ═══════════ DESKTOP ═══════════ -->
        <template #desktop>
            <div class="content">
                <div class="page-head">
                    <div class="ph-l">
                        <h1>Invoices</h1>
                        <div class="ph-sub">All invoices for your account</div>
                    </div>
                    <div v-if="summary.total_outstanding > 0" class="amber-pill"><i class="ti ti-receipt" />£{{ gbp(summary.total_outstanding) }} outstanding</div>
                </div>

                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px">
                    <div class="filter-tabs">
                        <button
                            v-for="t in filterTabs"
                            :key="t.key"
                            class="filter-tab"
                            :class="{ active: filter === t.key }"
                            @click="setFilter(t.key)"
                        >{{ t.label }}<span class="c">{{ t.count }}</span></button>
                    </div>
                </div>

                <div v-if="invoices.data.length === 0" class="card">
                    <div class="empty">
                        <div class="ic"><i class="ti ti-receipt" /></div>
                        <h4>No invoices</h4>
                        <p>There are no invoices matching this filter.</p>
                    </div>
                </div>

                <div v-else class="inv-table">
                    <div class="inv-thead">
                        <div>Invoice</div><div>Description</div><div>Date</div><div>Due</div><div class="right">Amount</div><div>Status</div><div />
                    </div>
                    <div
                        v-for="inv in invoices.data"
                        :key="inv.id"
                        class="inv-row"
                        @click="router.visit(`/portal/invoices/${inv.id}`)"
                    >
                        <div class="id">{{ inv.number }}</div>
                        <div class="dt" style="color:var(--text-primary);font-weight:500">{{ inv.billing_entity ?? 'Invoice' }}</div>
                        <div class="dt">{{ inv.issue_date }}</div>
                        <div class="dt">{{ inv.due_date ?? '—' }}</div>
                        <div class="amt">£{{ gbp(inv.total) }}</div>
                        <div><span class="badge badge-sm" :class="invBadge(inv.status).cls">{{ invBadge(inv.status).label }}</span></div>
                        <div class="act">
                            <button v-if="payableStatuses.includes(inv.status)" class="btn btn-primary btn-sm" @click.stop="payInvoice(inv)">Pay</button>
                            <button class="icon-btn" @click.stop="openPdf(inv.id)"><i class="ti ti-download" /></button>
                        </div>
                    </div>
                </div>

                <!-- Pagination -->
                <div v-if="invoices.last_page > 1" class="action-row" style="justify-content:center">
                    <Link
                        v-for="link in invoices.links"
                        :key="link.label"
                        :href="link.url || ''"
                        class="btn btn-sm"
                        :class="link.active ? 'btn-primary' : 'btn-secondary'"
                        :style="!link.url ? 'opacity:.5;pointer-events:none' : ''"
                        v-html="link.label"
                    />
                </div>
            </div>
        </template>

        <!-- ═══════════ MOBILE ═══════════ -->
        <template #mobile>
            <div class="m-appbar">
                <div class="title">Invoices</div>
                <div class="spacer" />
                <div class="bell"><i class="ti ti-bell" /></div>
            </div>
            <div class="m-body is-pb">
                <div v-if="summary.total_outstanding > 0" class="amber-pill" style="align-self:flex-start"><i class="ti ti-receipt" />£{{ gbp(summary.total_outstanding) }} outstanding</div>

                <div class="m-filters">
                    <div
                        v-for="t in filterTabs"
                        :key="t.key"
                        class="m-chip"
                        :class="{ active: filter === t.key }"
                        @click="setFilter(t.key)"
                    >{{ t.label }}</div>
                </div>

                <div v-if="invoices.data.length === 0" class="m-card">
                    <div class="empty"><div class="ic"><i class="ti ti-receipt" /></div><h4>No invoices</h4></div>
                </div>

                <div v-else class="inv-cards">
                    <div v-for="inv in invoices.data" :key="inv.id" class="inv-mcard">
                        <div class="top">
                            <Link :href="`/portal/invoices/${inv.id}`" class="id" style="text-decoration:none;color:inherit">{{ inv.number }}</Link>
                            <span class="badge badge-sm" :class="invBadge(inv.status).cls">{{ invBadge(inv.status).label }}</span>
                        </div>
                        <div class="meta">{{ inv.billing_entity ?? 'Invoice' }}<br>Issued {{ inv.issue_date }}<template v-if="inv.due_date"> · Due {{ inv.due_date }}</template></div>
                        <div class="amt">£{{ gbp(inv.total) }}</div>
                        <div class="row-foot">
                            <button v-if="payableStatuses.includes(inv.status)" class="btn btn-primary btn-sm" style="flex:1;justify-content:center" @click="payInvoice(inv)">Pay now</button>
                            <button class="btn btn-secondary btn-sm" :style="payableStatuses.includes(inv.status) ? '' : 'flex:1;justify-content:center'" @click="openPdf(inv.id)"><i class="ti ti-download" /><template v-if="!payableStatuses.includes(inv.status)">Download PDF</template></button>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </PortalLayout>

    <StripeCheckoutModal
        v-model:show="checkoutOpen"
        :invoice-id="activeInvoice?.id"
        :invoice-number="activeInvoice?.number || ''"
        :invoice-total="activeInvoice?.total || 0"
        :stripe-key="stripeKey"
    />
</template>
