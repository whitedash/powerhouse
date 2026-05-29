<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    IconArrowRight,
    IconCalendar,
    IconCreditCard,
    IconDownload,
    IconHeadset,
    IconReceipt,
    IconExternalLink,
    IconCircleCheck,
} from '@tabler/icons-vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';

const props = defineProps({
    customer: { type: Object, required: true },
    active_products: { type: Array, default: () => [] },
    recent_invoices: { type: Array, default: () => [] },
    invoices_paid_count: { type: Number, default: 0 },
    outstanding_total: { type: Number, default: 0 },
    open_tickets: { type: Number, default: 0 },
});

const greeting = computed(() => {
    const h = new Date().getHours();
    if (h < 12) return 'Good morning';
    if (h < 18) return 'Good afternoon';
    return 'Good evening';
});

const displayName = computed(() => {
    // Greet by first name when we have a primary contact, fall back to
    // the company name so corporate customers still see a personal greeting.
    const contactName = props.customer?.contact_name;
    if (contactName) return contactName.split(/\s+/)[0];
    return props.customer?.name ?? 'there';
});

const accountHealth = computed(() => {
    if (props.outstanding_total > 0) return { state: 'attention', label: 'Action needed' };
    return { state: 'good', label: 'All good' };
});

const counts = computed(() => ({
    subscriptions: props.active_products.length,
    invoices: props.recent_invoices.filter((i) => i.is_overdue).length || undefined,
    support: props.open_tickets || undefined,
}));

function gbp(n) {
    return `£${Number(n).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function badgeClassForInvoice(status) {
    if (status === 'paid') return 'badge-active';
    if (status === 'overdue') return 'badge-overdue';
    if (status === 'sent') return 'badge-pending';
    return 'badge-soon';
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
        <!-- Welcome card (navy gradient w/ stats) -->
        <section class="portal-welcome">
            <div class="portal-welcome-label">{{ greeting }}</div>
            <div class="portal-welcome-name">{{ displayName }}</div>
            <div class="portal-welcome-sub">
                {{ customer.name }}
                <template v-if="customer.city"> · {{ customer.city }}</template>
                <template v-if="customer.member_since"> · Member since {{ customer.member_since }}</template>
            </div>

            <div class="portal-welcome-stats">
                <div class="portal-w-stat">
                    <div class="k">Active products</div>
                    <div class="v">{{ active_products.length }}</div>
                </div>
                <div class="portal-w-divider" />
                <div class="portal-w-stat">
                    <div class="k">Invoices paid</div>
                    <div class="v">{{ invoices_paid_count }}</div>
                </div>
                <div class="portal-w-divider" />
                <div class="portal-w-stat">
                    <div class="k">Account health</div>
                    <div class="v">
                        <span class="dot" :class="accountHealth.state" />
                        {{ accountHealth.label }}
                    </div>
                </div>
            </div>

            <div class="portal-welcome-actions">
                <Link href="/portal/support" class="btn btn-outline-light">
                    <IconHeadset :size="15" stroke-width="1.75" />
                    Get support
                </Link>
                <Link href="/portal/invoices" class="btn btn-primary">
                    <IconReceipt :size="15" stroke-width="1.75" />
                    View invoices
                </Link>
            </div>
        </section>

        <!-- Your products -->
        <div class="portal-section-head">
            <div class="col-l">
                <h2>Your products</h2>
                <div class="desc">Click to open any product — you're automatically signed in.</div>
            </div>
        </div>

        <div v-if="active_products.length === 0" class="portal-empty">
            <IconCreditCard :size="32" stroke-width="1.5" />
            <div class="portal-empty-title">No active subscriptions</div>
            <Link href="/portal/subscriptions" class="btn btn-primary">Browse products</Link>
        </div>

        <div v-else class="portal-product-grid">
            <article v-for="p in active_products" :key="p.id" class="portal-product-card">
                <div class="pc-top">
                    <div
                        class="pc-logo"
                        :style="{ background: p.icon_colour || 'var(--accent)' }"
                    >{{ (p.product_name || '?').charAt(0).toUpperCase() }}</div>
                    <span class="badge" :class="p.status === 'trial' ? 'badge-info' : 'badge-active'">
                        {{ p.status === 'trial' ? 'Trial' : 'Active' }}
                    </span>
                </div>
                <div class="pc-name">{{ p.product_name }}</div>
                <div class="pc-desc">{{ p.plan_name }} plan</div>
                <div class="pc-divider" />
                <div class="pc-facts">
                    <span class="pc-fact">
                        <IconCreditCard :size="13" stroke-width="1.75" />
                        <strong>{{ gbp(p.price) }}</strong> · {{ p.interval_label }}
                    </span>
                    <span v-if="p.next_billing_date" class="pc-fact">
                        <IconCalendar :size="13" stroke-width="1.75" />
                        Renews <strong>{{ p.next_billing_date }}</strong>
                    </span>
                </div>
                <div class="pc-card-foot">
                    <Link href="/portal/subscriptions" class="btn btn-secondary btn-block">
                        Manage {{ p.product_name }}
                        <IconExternalLink :size="14" stroke-width="1.75" />
                    </Link>
                </div>
            </article>
        </div>

        <!-- Invoices preview -->
        <div class="portal-section-head">
            <h2>Recent invoices</h2>
            <Link href="/portal/invoices" class="ghost-link">
                View all invoices
                <IconArrowRight :size="14" stroke-width="1.75" />
            </Link>
        </div>

        <div class="card">
            <div v-if="recent_invoices.length === 0" class="portal-empty-inline">
                <IconReceipt :size="24" stroke-width="1.5" />
                <span>No invoices yet.</span>
            </div>
            <template v-else>
                <div v-for="inv in recent_invoices" :key="inv.id" class="inv-row">
                    <div class="inv-ic" :class="inv.status === 'paid' ? 'green' : 'muted'">
                        <IconReceipt :size="18" stroke-width="1.75" />
                    </div>
                    <div class="inv-meta">
                        <div class="ttl">{{ inv.description }}</div>
                        <div class="sub">
                            {{ inv.number }}
                            <template v-if="inv.due_date"> · Due {{ inv.due_date }}</template>
                        </div>
                    </div>
                    <div class="inv-right">
                        <div class="inv-amt">{{ gbp(inv.total) }}</div>
                        <span class="badge badge-sm" :class="badgeClassForInvoice(inv.status)">
                            {{ invoiceLabel(inv.status) }}
                        </span>
                        <a :href="`/portal/invoices/${inv.id}/pdf`" class="ghost-link muted" target="_blank">
                            <IconDownload :size="13" stroke-width="1.75" />
                            PDF
                        </a>
                    </div>
                </div>
                <div class="inv-summary">
                    <div class="total">
                        Outstanding ·
                        <strong>{{ gbp(outstanding_total) }}</strong>
                    </div>
                    <div v-if="outstanding_total === 0" class="clear">
                        <IconCircleCheck :size="14" stroke-width="2" />
                        No outstanding balance
                    </div>
                </div>
            </template>
        </div>

        <!-- Support summary -->
        <div class="portal-section-head">
            <h2>Support</h2>
        </div>

        <div class="card">
            <div class="portal-sup-summary">
                <div class="l">
                    <strong>{{ open_tickets }}</strong>
                    open ticket{{ open_tickets === 1 ? '' : 's' }}
                </div>
                <Link href="/portal/support" class="ghost-link accent">
                    Open support
                    <IconArrowRight :size="14" stroke-width="1.75" />
                </Link>
            </div>
        </div>
    </PortalLayout>
</template>
