<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    IconArrowRight,
    IconReceipt,
    IconMessageCircle,
    IconExternalLink,
    IconAlertCircle,
} from '@tabler/icons-vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';

const props = defineProps({
    customer: { type: Object, required: true },
    active_products: { type: Array, default: () => [] },
    recent_invoices: { type: Array, default: () => [] },
    recent_tickets: { type: Array, default: () => [] },
    invoices_paid_count: { type: Number, default: 0 },
    outstanding_total: { type: Number, default: 0 },
    outstanding_count: { type: Number, default: 0 },
    overdue_count: { type: Number, default: 0 },
    open_tickets: { type: Number, default: 0 },
    awaiting_reply: { type: Number, default: 0 },
});

const greeting = computed(() => {
    const h = new Date().getHours();
    if (h < 12) return 'Good morning';
    if (h < 18) return 'Good afternoon';
    return 'Good evening';
});

const firstName = computed(() => {
    const contactName = props.customer?.contact_name;
    if (contactName) return contactName.split(/\s+/)[0];
    return props.customer?.name ?? 'there';
});

const counts = computed(() => ({
    subscriptions: props.active_products.length,
    invoices: props.overdue_count || undefined,
    support: props.open_tickets || undefined,
}));

function gbp(n) {
    return Number(n ?? 0).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function initials(name) {
    return (name || '?').charAt(0).toUpperCase();
}

/* ─── SSO launch (unchanged behaviour) ─── */
const launchingId = ref(null);

function launchProduct(product) {
    if (!product.sso_enabled) {
        if (product.sso_url) window.location.href = product.sso_url;
        return;
    }
    launchingId.value = product.id;
    router.post(`/portal/launch/${product.product_slug}`, {}, {
        preserveState: true,
        preserveScroll: true,
        onFinish: () => { launchingId.value = null; },
    });
}

function invoiceLabel(status) {
    if (status === 'paid') return 'Paid';
    if (status === 'overdue') return 'Overdue';
    if (status === 'sent') return 'Awaiting payment';
    if (status === 'draft') return 'Draft';
    return 'Upcoming';
}
</script>

<template>
    <Head title="Overview · Whitedash" />
    <PortalLayout title="Overview" active-nav="dashboard" :counts="counts">
        <div class="po-overview">
            <!-- Greeting -->
            <div class="po-greeting">
                <h1 class="po-name">{{ greeting }}, {{ firstName }}</h1>
                <p class="po-sub">
                    {{ customer.name }}
                    <template v-if="customer.member_since"> · Member since {{ customer.member_since }}</template>
                </p>
            </div>

            <!-- Attention banner -->
            <div v-if="outstanding_total > 0 || awaiting_reply > 0" class="po-attention-strip">
                <div v-if="outstanding_total > 0" class="po-attention-item po-attention--warning">
                    <IconReceipt :size="20" stroke-width="1.75" />
                    <div>
                        <strong>£{{ gbp(outstanding_total) }} outstanding</strong>
                        <span>{{ outstanding_count }} invoice{{ outstanding_count === 1 ? '' : 's' }} need{{ outstanding_count === 1 ? 's' : '' }} payment</span>
                    </div>
                    <Link href="/portal/invoices" class="po-attention-action">Pay now →</Link>
                </div>
                <div v-if="awaiting_reply > 0" class="po-attention-item po-attention--info">
                    <IconMessageCircle :size="20" stroke-width="1.75" />
                    <div>
                        <strong>{{ awaiting_reply }} ticket{{ awaiting_reply === 1 ? '' : 's' }}</strong>
                        <span>waiting for your reply</span>
                    </div>
                    <Link href="/portal/support" class="po-attention-action">View →</Link>
                </div>
            </div>

            <!-- Stats -->
            <div class="po-stats">
                <div class="po-stat">
                    <span class="po-stat-value">{{ active_products.length }}</span>
                    <span class="po-stat-label">Active products</span>
                </div>
                <div class="po-stat">
                    <span class="po-stat-value">{{ invoices_paid_count }}</span>
                    <span class="po-stat-label">Invoices paid</span>
                </div>
                <div class="po-stat" :class="{ 'po-stat--alert': outstanding_total > 0 }">
                    <span class="po-stat-value">£{{ gbp(outstanding_total) }}</span>
                    <span class="po-stat-label">Outstanding</span>
                </div>
                <div class="po-stat">
                    <span class="po-stat-value">{{ open_tickets }}</span>
                    <span class="po-stat-label">Open tickets</span>
                </div>
            </div>

            <!-- Two-column grid -->
            <div class="po-grid">
                <!-- LEFT: products -->
                <div class="po-col-main">
                    <div class="po-section-head"><h2>Your products</h2></div>

                    <div v-if="active_products.length === 0" class="po-card po-empty">
                        No active products yet.
                    </div>

                    <div v-else class="po-products">
                        <component
                            :is="p.sso_enabled ? 'button' : (p.sso_url ? 'a' : Link)"
                            v-for="p in active_products"
                            :key="p.id"
                            class="po-product-card"
                            :type="p.sso_enabled ? 'button' : undefined"
                            :href="!p.sso_enabled ? (p.sso_url || '/portal/subscriptions') : undefined"
                            :target="!p.sso_enabled && p.sso_url ? '_blank' : undefined"
                            :disabled="launchingId === p.id"
                            @click="p.sso_enabled ? launchProduct(p) : undefined"
                        >
                            <div class="po-product-icon" :style="{ background: p.icon_colour || 'var(--accent, #F5A623)' }">
                                {{ initials(p.product_name) }}
                            </div>
                            <div class="po-product-info">
                                <span class="po-product-name">{{ p.product_name }}</span>
                                <span class="po-product-plan">{{ p.plan_name }} · {{ p.interval_label }}</span>
                            </div>
                            <div class="po-product-right">
                                <span class="po-product-status" :class="`status-${p.status}`">{{ p.status }}</span>
                                <IconExternalLink :size="15" stroke-width="1.75" class="po-open-icon" />
                            </div>
                        </component>
                    </div>
                </div>

                <!-- RIGHT: recent activity -->
                <div class="po-col-side">
                    <!-- Recent invoices -->
                    <div class="po-card">
                        <div class="po-card-head">
                            <h3>Recent invoices</h3>
                            <Link href="/portal/invoices" class="po-card-link">View all →</Link>
                        </div>
                        <div v-if="recent_invoices.length === 0" class="po-row-empty">No invoices yet.</div>
                        <div v-else class="po-invoice-list">
                            <Link
                                v-for="inv in recent_invoices"
                                :key="inv.id"
                                :href="`/portal/invoices/${inv.id}`"
                                class="po-invoice-row"
                            >
                                <div class="po-invoice-info">
                                    <span class="po-invoice-ref">{{ inv.number }}</span>
                                    <span class="po-invoice-date">{{ inv.description }}</span>
                                </div>
                                <div class="po-invoice-right">
                                    <span class="po-invoice-status" :class="`inv-${inv.status}`">{{ invoiceLabel(inv.status) }}</span>
                                    <span class="po-invoice-amount">£{{ gbp(inv.total) }}</span>
                                </div>
                            </Link>
                        </div>
                    </div>

                    <!-- Support tickets -->
                    <div v-if="recent_tickets.length" class="po-card">
                        <div class="po-card-head">
                            <h3>Support tickets</h3>
                            <Link href="/portal/support" class="po-card-link">View all →</Link>
                        </div>
                        <div class="po-ticket-list">
                            <Link
                                v-for="ticket in recent_tickets"
                                :key="ticket.id"
                                :href="`/portal/support/${ticket.id}`"
                                class="po-ticket-row"
                            >
                                <div class="po-ticket-dot" :class="`status-${ticket.status}`" />
                                <div class="po-ticket-info">
                                    <span class="po-ticket-subject">{{ ticket.subject }}</span>
                                    <span class="po-ticket-meta">#{{ ticket.reference }} · {{ ticket.updated_at }}</span>
                                </div>
                                <span class="po-ticket-badge" :class="{ 'badge--blue': ticket.awaiting_reply }">
                                    {{ ticket.status_label }}
                                </span>
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </PortalLayout>
</template>

<style scoped>
.po-overview { padding-bottom: 8px; }

.po-greeting { padding: 8px 0 16px; }
.po-name { font: 700 26px/1.2 'Inter', sans-serif; color: #111827; margin: 0 0 4px; }
.po-sub { font: 400 14px/1.4 'Inter', sans-serif; color: #9ca3af; margin: 0; }

.po-attention-strip { display: flex; flex-direction: column; gap: 8px; margin-bottom: 20px; }
.po-attention-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    border-radius: 12px;
    font-size: 13px;
}
.po-attention--warning { background: #fffbeb; border: 1px solid #f59e0b; color: #92400e; }
.po-attention--info { background: #eff6ff; border: 1px solid #3b82f6; color: #1e40af; }
.po-attention-item > div { flex: 1; }
.po-attention-item strong { display: block; font-weight: 600; }
.po-attention-action { font: 600 13px/1 'Inter', sans-serif; white-space: nowrap; text-decoration: none; color: inherit; }

.po-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 24px; }
.po-stat { background: #fff; border-radius: 12px; padding: 16px; border: 1px solid #f3f4f6; text-align: center; }
.po-stat--alert .po-stat-value { color: #dc2626; }
.po-stat-value { display: block; font: 700 24px/1 'Inter', sans-serif; color: #111827; margin-bottom: 4px; }
.po-stat-label { display: block; font: 400 11px/1.2 'Inter', sans-serif; color: #9ca3af; text-transform: uppercase; letter-spacing: .05em; }

.po-grid { display: grid; grid-template-columns: 1fr 380px; gap: 20px; align-items: start; }
.po-section-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.po-section-head h2 { font: 600 16px/1 'Inter', sans-serif; color: #111827; margin: 0; }

.po-product-card {
    display: flex;
    align-items: center;
    gap: 14px;
    width: 100%;
    text-align: left;
    padding: 14px 16px;
    background: #fff;
    border: 1px solid #f3f4f6;
    border-radius: 12px;
    margin-bottom: 10px;
    text-decoration: none;
    cursor: pointer;
    transition: border-color .15s, box-shadow .15s;
    font-family: inherit;
}
.po-product-card:hover { border-color: var(--accent, #f5a623); box-shadow: 0 2px 12px rgba(0, 0, 0, .08); }
.po-product-card:disabled { opacity: .6; cursor: default; }
.po-product-icon {
    width: 42px; height: 42px;
    border-radius: 10px;
    display: grid; place-items: center;
    font: 700 16px/1 'Inter', sans-serif;
    color: #fff;
    flex-shrink: 0;
}
.po-product-info { flex: 1; min-width: 0; }
.po-product-name { display: block; font: 600 14px/1.2 'Inter', sans-serif; color: #111827; margin-bottom: 4px; }
.po-product-plan { display: block; font: 400 12px/1.2 'Inter', sans-serif; color: #9ca3af; }
.po-product-right { display: flex; align-items: center; gap: 10px; }
.po-product-status { font: 500 11px/1 'Inter', sans-serif; padding: 3px 8px; border-radius: 999px; text-transform: capitalize; }
.status-active { background: #ecfdf5; color: #059669; }
.status-trial { background: #eff6ff; color: #2563eb; }
.status-suspended { background: #fef2f2; color: #dc2626; }
.po-open-icon { color: #d1d5db; }

.po-card { background: #fff; border: 1px solid #f3f4f6; border-radius: 14px; margin-bottom: 16px; overflow: hidden; }
.po-card-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid #f3f4f6; }
.po-card-head h3 { font: 600 14px/1 'Inter', sans-serif; color: #111827; margin: 0; }
.po-card-link { font: 400 12px/1 'Inter', sans-serif; color: #9ca3af; text-decoration: none; }
.po-card-link:hover { color: var(--accent, #f5a623); }
.po-row-empty, .po-empty { padding: 18px 16px; font-size: 13px; color: #9ca3af; }

.po-invoice-row { display: flex; align-items: center; justify-content: space-between; padding: 10px 16px; border-bottom: 1px solid #f9fafb; text-decoration: none; }
.po-invoice-row:last-child { border-bottom: none; }
.po-invoice-ref { display: block; font: 500 13px/1.2 'Inter', sans-serif; color: #374151; }
.po-invoice-date { display: block; font: 400 11px/1.2 'Inter', sans-serif; color: #9ca3af; margin-top: 2px; }
.po-invoice-right { display: flex; align-items: center; gap: 10px; }
.po-invoice-amount { font: 600 13px/1 'Inter', sans-serif; color: #111827; }
.po-invoice-status { font: 500 11px/1 'Inter', sans-serif; padding: 2px 7px; border-radius: 999px; white-space: nowrap; }
.inv-paid { background: #ecfdf5; color: #059669; }
.inv-sent { background: #fffbeb; color: #d97706; }
.inv-overdue { background: #fef2f2; color: #dc2626; }
.inv-draft, .inv-void, .inv-partially_paid { background: #f3f4f6; color: #6b7280; }

.po-ticket-row { display: flex; align-items: center; gap: 12px; padding: 10px 16px; border-bottom: 1px solid #f9fafb; text-decoration: none; }
.po-ticket-row:last-child { border-bottom: none; }
.po-ticket-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.po-ticket-dot.status-open { background: #f59e0b; }
.po-ticket-dot.status-in_progress, .po-ticket-dot.status-awaiting_customer { background: #3b82f6; }
.po-ticket-dot.status-resolved { background: #10b981; }
.po-ticket-dot.status-closed { background: #9ca3af; }
.po-ticket-info { flex: 1; min-width: 0; }
.po-ticket-subject { display: block; font: 500 13px/1.3 'Inter', sans-serif; color: #374151; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.po-ticket-meta { display: block; font: 400 11px/1.2 'Inter', sans-serif; color: #9ca3af; margin-top: 2px; }
.po-ticket-badge { font: 500 11px/1 'Inter', sans-serif; padding: 2px 7px; border-radius: 999px; background: #f3f4f6; color: #6b7280; white-space: nowrap; }
.po-ticket-badge.badge--blue { background: #eff6ff; color: #2563eb; }

@media (max-width: 767px) {
    .po-grid { grid-template-columns: 1fr; }
    .po-stats { grid-template-columns: repeat(2, 1fr); }
}
</style>
