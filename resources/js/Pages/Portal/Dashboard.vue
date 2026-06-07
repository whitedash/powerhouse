<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';

const props = defineProps({
    customer: { type: Object, required: true },
    active_products: { type: Array, default: () => [] },
    // { websites, domains, services } — the fuller asset picture (Stage 4).
    asset_counts: { type: Object, default: () => ({ websites: 0, domains: 0, services: 0 }) },
    recent_invoices: { type: Array, default: () => [] },
    recent_tickets: { type: Array, default: () => [] },
    invoices_paid_count: { type: Number, default: 0 },
    invoices_paid_total: { type: Number, default: 0 },
    outstanding_total: { type: Number, default: 0 },
    outstanding_count: { type: Number, default: 0 },
    overdue_count: { type: Number, default: 0 },
    next_due_invoice: { type: Object, default: null },
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
    invoices: props.overdue_count || undefined,
    support: props.open_tickets || undefined,
}));

function gbp(n) {
    return Number(n ?? 0).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function initial(name) {
    return (name || '?').charAt(0).toUpperCase();
}

// Dismissable attention banner (session-local — reappears on reload, by
// design: an unpaid invoice should keep nagging).
const attnDismissed = ref(false);
const showAttn = computed(() => ! attnDismissed.value && props.outstanding_count > 0);

/* ─── SSO launch (unchanged behaviour) ─── */
const launchingId = ref(null);
function launchProduct(product) {
    if (! product.sso_enabled) {
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

// Invoice status → handoff badge class + label.
function invBadge(status) {
    if (status === 'paid') return { cls: 'badge-paid', label: 'Paid' };
    if (status === 'overdue') return { cls: 'badge-overdue', label: 'Overdue' };
    if (status === 'sent') return { cls: 'badge-awaiting', label: 'Awaiting' };
    if (status === 'partially_paid') return { cls: 'badge-awaiting', label: 'Part-paid' };
    return { cls: 'badge-neutral', label: status };
}
</script>

<template>
    <Head title="Overview · Whitedash" />
    <PortalLayout active-nav="overview" :counts="counts">
        <!-- ═══════════ DESKTOP ═══════════ -->
        <template #desktop>
            <div class="content">
                <section class="welcome">
                    <div class="greet">{{ greeting }}</div>
                    <div class="wname">{{ firstName }}</div>
                    <div class="wsub">
                        {{ customer.name }}
                        <template v-if="customer.city"><span class="sep">·</span>{{ customer.city }}</template>
                        <template v-if="customer.member_since"><span class="sep">·</span>Member since {{ customer.member_since }}</template>
                    </div>
                    <div class="welcome-actions">
                        <Link href="/portal/support" class="btn btn-outline-light"><i class="ti ti-headset" />Get support</Link>
                        <Link href="/portal/invoices" class="btn btn-primary"><i class="ti ti-credit-card" />Pay invoice</Link>
                    </div>
                </section>

                <div v-if="showAttn" class="attn">
                    <div class="ic"><i class="ti ti-alert-triangle" /></div>
                    <div class="body">
                        <div class="t">{{ outstanding_count }} invoice{{ outstanding_count === 1 ? '' : 's' }} outstanding — £{{ gbp(outstanding_total) }}</div>
                        <div v-if="next_due_invoice" class="s">{{ next_due_invoice.number }} is awaiting payment, due {{ next_due_invoice.due_date }}.</div>
                    </div>
                    <Link
                        :href="next_due_invoice ? `/portal/invoices/${next_due_invoice.id}` : '/portal/invoices'"
                        class="btn btn-primary btn-sm"
                    >Pay now<i class="ti ti-arrow-right" /></Link>
                    <button class="icon-btn dismiss" @click="attnDismissed = true"><i class="ti ti-x" /></button>
                </div>

                <div class="stat-row">
                    <Link href="/portal/assets" class="stat" style="text-decoration:none;color:inherit">
                        <div class="stat-top"><div class="k">My assets</div><div class="ic blue"><i class="ti ti-box" /></div></div>
                        <div class="v">{{ (asset_counts.websites || 0) + (asset_counts.domains || 0) + (asset_counts.services || 0) }}</div>
                        <div class="foot">{{ asset_counts.websites || 0 }} websites · {{ asset_counts.domains || 0 }} domains · {{ asset_counts.services || 0 }} services</div>
                    </Link>
                    <div class="stat">
                        <div class="stat-top"><div class="k">Invoices paid</div><div class="ic green"><i class="ti ti-circle-check" /></div></div>
                        <div class="v">{{ invoices_paid_count }}</div>
                        <div class="foot">£{{ gbp(invoices_paid_total) }} lifetime</div>
                    </div>
                    <div class="stat">
                        <div class="stat-top"><div class="k">Outstanding</div><div class="ic red"><i class="ti ti-receipt" /></div></div>
                        <div class="v" :class="{ red: outstanding_total > 0 }">£{{ gbp(outstanding_total) }}</div>
                        <div class="foot">
                            <template v-if="next_due_invoice">{{ outstanding_count }} invoice due {{ next_due_invoice.due_date }}</template>
                            <template v-else>Nothing outstanding</template>
                        </div>
                    </div>
                    <div class="stat">
                        <div class="stat-top"><div class="k">Open tickets</div><div class="ic gold"><i class="ti ti-message-2" /></div></div>
                        <div class="v">{{ open_tickets }}</div>
                        <div class="foot">{{ awaiting_reply }} awaiting your reply</div>
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1.42fr 1fr;gap:24px;margin-top:32px;align-items:start">
                    <!-- LEFT: products -->
                    <div>
                        <div class="section-head" style="margin-top:0">
                            <div class="col-l">
                                <h2>Your products</h2>
                                <div class="desc">Click any product to open it — you're signed in automatically.</div>
                            </div>
                        </div>

                        <div v-if="active_products.length === 0" class="card card-pad" style="color:var(--text-secondary)">
                            No active products yet.
                        </div>

                        <div v-else style="display:flex;flex-direction:column;gap:14px">
                            <div
                                v-for="p in active_products"
                                :key="p.id"
                                class="prod-card"
                                style="flex-direction:row;align-items:center;gap:18px;padding:18px 20px"
                            >
                                <div class="pc-logo" :style="{ background: p.icon_colour || 'var(--accent)' }">{{ initial(p.product_name) }}</div>
                                <div style="flex:1">
                                    <div style="display:flex;align-items:center;gap:10px">
                                        <div class="pc-name" style="margin:0">{{ p.product_name }}</div>
                                        <span class="badge badge-active">Active</span>
                                    </div>
                                    <div class="pc-plan">{{ p.plan_name }}<span style="color:var(--text-tertiary)"> · </span>£{{ gbp(p.price) }} / month</div>
                                </div>
                                <button class="btn btn-secondary btn-sm" :disabled="launchingId === p.id" @click="launchProduct(p)">
                                    {{ launchingId === p.id ? 'Opening…' : 'Open' }}<i class="ti ti-external-link" />
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT: recent activity -->
                    <div style="display:flex;flex-direction:column;gap:20px">
                        <div class="card">
                            <div class="card-head">
                                <div><h3>Recent invoices</h3></div>
                                <span v-if="outstanding_count > 0" class="badge badge-overdue badge-sm">{{ outstanding_count }} due</span>
                            </div>
                            <div v-if="recent_invoices.length === 0" class="card-pad" style="color:var(--text-secondary)">No invoices yet.</div>
                            <template v-else>
                                <Link
                                    v-for="inv in recent_invoices"
                                    :key="inv.id"
                                    :href="`/portal/invoices/${inv.id}`"
                                    class="inv-list-row"
                                    style="text-decoration:none;color:inherit"
                                >
                                    <div class="ic" :class="inv.status === 'paid' ? 'green' : 'amber'"><i class="ti ti-receipt" /></div>
                                    <div><div class="ttl">{{ inv.number }}</div><div class="sub">{{ inv.description }}</div></div>
                                    <div class="r">
                                        <div class="amt">£{{ gbp(inv.total) }}</div>
                                        <span class="badge badge-sm" :class="invBadge(inv.status).cls">{{ invBadge(inv.status).label }}</span>
                                    </div>
                                </Link>
                            </template>
                            <div class="list-foot">
                                <Link href="/portal/invoices" class="ghost-link accent" style="padding-left:0">View all invoices<i class="ti ti-arrow-right" /></Link>
                            </div>
                        </div>

                        <div v-if="recent_tickets.length" class="card">
                            <div class="card-head">
                                <div><h3>Open tickets</h3></div>
                                <span class="badge badge-open badge-sm">{{ open_tickets }} open</span>
                            </div>
                            <Link
                                v-for="t in recent_tickets"
                                :key="t.id"
                                :href="`/portal/support/${t.id}`"
                                class="inv-list-row"
                                style="text-decoration:none;color:inherit"
                            >
                                <div class="ic" style="background:var(--info-bg);color:#1D4ED8"><i class="ti ti-message-2" /></div>
                                <div><div class="ttl">#{{ t.reference }} · {{ t.subject }}</div><div class="sub">Updated {{ t.updated_at }}</div></div>
                                <div class="r">
                                    <span class="badge badge-sm" :class="t.awaiting_reply ? 'badge-open' : 'badge-awaiting'">
                                        {{ t.awaiting_reply ? 'Awaiting you' : t.status_label }}
                                    </span>
                                </div>
                            </Link>
                            <div class="list-foot">
                                <Link href="/portal/support" class="ghost-link accent" style="padding-left:0">View all tickets<i class="ti ti-arrow-right" /></Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- ═══════════ MOBILE ═══════════ -->
        <template #mobile>
            <div class="m-appbar">
                <div class="brand-mark">W</div>
                <div class="title">Overview</div>
                <div class="spacer" />
                <div class="bell"><i class="ti ti-bell" /></div>
                <div class="avatar av-amber av">{{ initial(customer.contact_name || customer.name) }}</div>
            </div>
            <div class="m-body is-pb">
                <div class="m-welcome">
                    <div class="greet">{{ greeting }}</div>
                    <div class="wname">{{ firstName }}</div>
                    <div class="wsub">
                        {{ customer.name }}
                        <template v-if="customer.city"> · {{ customer.city }}</template>
                        <template v-if="customer.member_since"> · Member since {{ customer.member_since }}</template>
                    </div>
                </div>

                <div v-if="showAttn" class="m-attn">
                    <div class="ic"><i class="ti ti-alert-triangle" /></div>
                    <div style="flex:1">
                        <div class="t">{{ outstanding_count }} invoice{{ outstanding_count === 1 ? '' : 's' }} outstanding — £{{ gbp(outstanding_total) }}</div>
                        <div v-if="next_due_invoice" class="s">{{ next_due_invoice.number }} · due {{ next_due_invoice.due_date }}</div>
                    </div>
                    <Link :href="next_due_invoice ? `/portal/invoices/${next_due_invoice.id}` : '/portal/invoices'" class="btn btn-primary btn-sm">Pay</Link>
                </div>

                <div class="m-stats">
                    <div class="m-stat"><div class="top"><div class="k">Active products</div><div class="ic blue"><i class="ti ti-stack-2" /></div></div><div class="v">{{ active_products.length }}</div></div>
                    <div class="m-stat"><div class="top"><div class="k">Invoices paid</div><div class="ic green"><i class="ti ti-circle-check" /></div></div><div class="v">{{ invoices_paid_count }}</div></div>
                    <div class="m-stat"><div class="top"><div class="k">Outstanding</div><div class="ic red"><i class="ti ti-receipt" /></div></div><div class="v" :class="{ red: outstanding_total > 0 }">£{{ gbp(outstanding_total) }}</div></div>
                    <div class="m-stat"><div class="top"><div class="k">Open tickets</div><div class="ic gold"><i class="ti ti-message-2" /></div></div><div class="v">{{ open_tickets }}</div></div>
                </div>

                <div class="m-section-title">Your products</div>
                <div style="display:flex;flex-direction:column;gap:12px">
                    <button
                        v-for="p in active_products"
                        :key="p.id"
                        class="m-prod"
                        style="text-align:left;border-width:1px;cursor:pointer"
                        :disabled="launchingId === p.id"
                        @click="launchProduct(p)"
                    >
                        <div class="top">
                            <div class="logo" :style="{ background: p.icon_colour || 'var(--accent)' }">{{ initial(p.product_name) }}</div>
                            <div style="flex:1"><div class="nm">{{ p.product_name }}</div><div class="pl">{{ p.plan_name }} · £{{ gbp(p.price) }}/mo</div></div>
                            <span class="badge badge-active badge-sm">Active</span>
                        </div>
                    </button>
                </div>

                <div class="m-section-title">
                    Recent invoices
                    <Link href="/portal/invoices">View all<i class="ti ti-chevron-right" style="font-size:14px" /></Link>
                </div>
                <div v-if="recent_invoices.length" class="m-card">
                    <Link
                        v-for="inv in recent_invoices.slice(0, 3)"
                        :key="inv.id"
                        :href="`/portal/invoices/${inv.id}`"
                        class="inv-list-row"
                        style="text-decoration:none;color:inherit"
                    >
                        <div class="ic" :class="inv.status === 'paid' ? 'green' : 'amber'"><i class="ti ti-receipt" /></div>
                        <div><div class="ttl">{{ inv.number }}</div><div class="sub">{{ inv.description }}</div></div>
                        <div class="r"><div class="amt">£{{ gbp(inv.total) }}</div></div>
                    </Link>
                </div>
            </div>
        </template>
    </PortalLayout>
</template>
