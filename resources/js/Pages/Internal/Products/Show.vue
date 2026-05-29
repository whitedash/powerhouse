<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    IconCurrencyPound,
    IconUsers,
    IconUserPlus,
    IconUserMinus,
    IconClock,
    IconPlayerPause,
    IconTag,
    IconChartBar,
    IconActivity,
    IconBolt,
    IconArrowRight,
    IconExternalLink,
    IconReceipt,
    IconCirclePlus,
    IconCircleMinus,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';

const props = defineProps({
    product: { type: Object, required: true },
    kpis: { type: Object, required: true },
    plan_distribution: { type: Array, default: () => [] },
    no_plan_count: { type: Number, default: 0 },
    recent_customers: { type: Array, default: () => [] },
    activity: { type: Array, default: () => [] },
    trend: { type: Array, default: () => [] },
});

const breadcrumbs = computed(() => [{ label: props.product.name }]);

/* ─── Money + dates ─── */
function gbp(n) {
    return new Intl.NumberFormat('en-GB', {
        style: 'currency', currency: 'GBP', minimumFractionDigits: 2,
    }).format(Number(n || 0));
}
function gbpRound(n) {
    return new Intl.NumberFormat('en-GB', {
        style: 'currency', currency: 'GBP', maximumFractionDigits: 0,
    }).format(Number(n || 0));
}
function formatDate(iso) {
    if (! iso) return '—';
    return new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}
function daysUntil(iso) {
    if (! iso) return null;
    return Math.ceil((new Date(iso) - new Date()) / 86400000);
}
function timeAgo(iso) {
    if (! iso) return '—';
    const diffMs = new Date() - new Date(iso);
    const sec = Math.floor(diffMs / 1000);
    if (sec < 60) return 'just now';
    const min = Math.floor(sec / 60);
    if (min < 60) return `${min}m ago`;
    const hr = Math.floor(min / 60);
    if (hr < 24) return `${hr}h ago`;
    const day = Math.floor(hr / 24);
    if (day < 30) return `${day}d ago`;
    const mo = Math.floor(day / 30);
    if (mo < 12) return `${mo}mo ago`;
    return `${Math.floor(day / 365)}y ago`;
}

/* ─── Avatar ─── */
function initials(name) {
    const parts = String(name || '').trim().split(/\s+/);
    return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || '?';
}
function avatarColour(id) {
    const palette = ['#0D9488', '#F59E0B', '#3B82F6', '#10B981', '#7C3AED', '#EF4444', '#06B6D4', '#6366F1'];
    return palette[Number(id) % palette.length];
}

/* ─── KPI trend tone ─── */
const trialBadgeTone = computed(() => props.kpis.trial_converting_soon > 0 ? 'warn' : 'up');

/* ─── Trend chart geometry ─── */
const trendMax = computed(() => {
    let max = 0;
    for (const m of props.trend) {
        if (m.new > max) max = m.new;
        if (m.churned > max) max = m.churned;
    }
    return max;
});
function barHeight(value) {
    if (trendMax.value === 0) return 0;
    return Math.max(2, Math.round((value / trendMax.value) * 60));
}
const trendIsEmpty = computed(() => trendMax.value === 0);

/* ─── Recent customer row trial-urgent flag ─── */
function trialUrgent(cust) {
    if (cust.status !== 'trial') return false;
    const d = daysUntil(cust.trial_ends_at);
    return d !== null && d <= 7;
}

/* ─── Activity copy ─── */
function activityIcon(action) {
    return action === 'product.enabled' ? IconCirclePlus : IconCircleMinus;
}
function activityIconClass(action) {
    return action === 'product.enabled' ? 'act-icon-teal' : 'act-icon-warn';
}
function activityLabel(action) {
    return action === 'product.enabled' ? 'Enabled for' : 'Suspended for';
}

/* ─── Nav helpers ─── */
function gotoPlans() {
    router.visit(`/settings/products/${props.product.id}/plans`);
}
function gotoCustomers() {
    router.visit(`/customers?product=${props.product.slug}`);
}
function gotoSubscriptions(status = null) {
    const q = status ? `?product=${props.product.slug}&status=${status}` : `?product=${props.product.slug}`;
    router.visit(`/subscriptions${q}`);
}
function gotoCustomer(id) {
    router.visit(`/customers/${id}`);
}
</script>

<template>
    <Head :title="product.name" />

    <InternalLayout :title="product.name" :breadcrumbs="breadcrumbs" :active-nav="product.slug">
        <template #topbar-actions>
            <button type="button" class="btn btn-secondary" @click="gotoPlans">
                <IconTag :size="14" stroke-width="1.75" />
                Manage plans
            </button>
            <button type="button" class="btn btn-ghost btn-sm" @click="gotoCustomers">
                <IconUsers :size="14" stroke-width="1.75" />
                View all customers
            </button>
        </template>

        <div class="product-overview">
            <!-- ─── Product header ─── -->
            <header class="product-header">
                <div class="product-header-left">
                    <div class="product-icon-lg" :style="{ background: product.icon_colour || '#0D9488' }">
                        {{ initials(product.name)[0] || '?' }}
                    </div>
                    <div>
                        <div class="product-title">{{ product.name }}</div>
                        <div v-if="product.description" class="product-desc">{{ product.description }}</div>
                    </div>
                </div>
                <button type="button" class="btn btn-ghost" disabled title="Coming soon — Control Panel will connect here" style="color: var(--text-tertiary);">
                    Control Panel
                    <IconExternalLink :size="14" stroke-width="1.75" />
                </button>
            </header>

            <!-- ─── KPI grid (3 × 2) ─── -->
            <div class="kpi-grid-2">
                <div class="kpi">
                    <div class="kpi-top"><div class="kpi-icon teal"><IconUsers :size="18" stroke-width="1.75" /></div></div>
                    <div class="kpi-mid">
                        <div class="kpi-value">{{ kpis.active_customers }}</div>
                        <div class="kpi-label">Active customers</div>
                    </div>
                    <div class="kpi-foot" :class="{ up: kpis.new_this_month > 0 }">
                        +{{ kpis.new_this_month }} this month
                    </div>
                </div>

                <div class="kpi">
                    <div class="kpi-top"><div class="kpi-icon gold"><IconCurrencyPound :size="18" stroke-width="1.75" /></div></div>
                    <div class="kpi-mid">
                        <div class="kpi-value">{{ gbp(kpis.mrr) }}</div>
                        <div class="kpi-label">Monthly recurring revenue</div>
                    </div>
                    <div class="kpi-foot">{{ gbpRound(kpis.arr) }} ARR</div>
                </div>

                <div class="kpi">
                    <div class="kpi-top"><div class="kpi-icon amber"><IconClock :size="18" stroke-width="1.75" /></div></div>
                    <div class="kpi-mid">
                        <div class="kpi-value">{{ kpis.trial_count }}</div>
                        <div class="kpi-label">On trial</div>
                    </div>
                    <div class="kpi-foot" :class="trialBadgeTone">
                        <template v-if="kpis.trial_converting_soon > 0">{{ kpis.trial_converting_soon }} converting in 7 days</template>
                        <template v-else>None expiring soon</template>
                    </div>
                </div>

                <div class="kpi">
                    <div class="kpi-top"><div class="kpi-icon blue"><IconUserPlus :size="18" stroke-width="1.75" /></div></div>
                    <div class="kpi-mid">
                        <div class="kpi-value">{{ kpis.new_this_month }}</div>
                        <div class="kpi-label">New this month</div>
                    </div>
                </div>

                <div class="kpi">
                    <div class="kpi-top">
                        <div class="kpi-icon" :class="kpis.churned_this_month > 0 ? 'red' : 'neutral'">
                            <IconUserMinus :size="18" stroke-width="1.75" />
                        </div>
                    </div>
                    <div class="kpi-mid">
                        <div class="kpi-value" :class="{ 'text-danger': kpis.churned_this_month > 0 }">{{ kpis.churned_this_month }}</div>
                        <div class="kpi-label">Churned this month</div>
                    </div>
                </div>

                <div class="kpi">
                    <div class="kpi-top"><div class="kpi-icon neutral"><IconPlayerPause :size="18" stroke-width="1.75" /></div></div>
                    <div class="kpi-mid">
                        <div class="kpi-value">{{ kpis.suspended_count }}</div>
                        <div class="kpi-label">Suspended</div>
                    </div>
                    <div class="kpi-foot">
                        <button
                            v-if="kpis.suspended_count > 0"
                            type="button"
                            style="background: none; border: 0; padding: 0; color: var(--accent); font: 500 12px/1 'Inter', sans-serif; cursor: pointer;"
                            @click="gotoSubscriptions('suspended')"
                        >
                            View →
                        </button>
                    </div>
                </div>
            </div>

            <!-- ─── Two-column layout ─── -->
            <div class="main-grid">
                <!-- LEFT 65% -->
                <div class="main-col-left">
                    <!-- Plan distribution -->
                    <section class="card" style="box-shadow: var(--shadow-md);">
                        <header class="card-header">
                            <div class="h-icon gold"><IconTag :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Plan distribution</h3>
                                <div class="sub">{{ kpis.active_customers }} active subscription{{ kpis.active_customers === 1 ? '' : 's' }}</div>
                            </div>
                        </header>
                        <div v-if="plan_distribution.length === 0 && no_plan_count === 0" style="padding: 32px 16px; text-align: center; color: var(--text-secondary);">
                            <div style="font: 600 14px/1.3 'Inter', sans-serif;">No active subscriptions yet</div>
                            <button type="button" style="margin-top: 8px; background: none; border: 0; padding: 0; color: var(--accent); font: 500 13px/1.3 'Inter', sans-serif; cursor: pointer;" @click="gotoCustomers">
                                Enable for a customer →
                            </button>
                        </div>
                        <template v-else>
                            <div v-for="plan in plan_distribution" :key="plan.id" class="plan-dist-row" @click="gotoPlans">
                                <div class="plan-dist-top">
                                    <div class="plan-dist-name">
                                        {{ plan.name }}
                                        <span v-if="plan.category_name" class="epc-category">{{ plan.category_name }}</span>
                                    </div>
                                    <div class="plan-dist-right">
                                        <span class="badge badge-active badge-sm">{{ plan.active_customers }} active</span>
                                        <span v-if="plan.trial_customers > 0" class="badge badge-pending badge-sm">{{ plan.trial_customers }} trial</span>
                                        <span v-if="plan.mrr > 0" class="plan-dist-mrr">{{ gbp(plan.mrr) }}/mo</span>
                                    </div>
                                </div>
                                <div v-if="plan.prices_summary.length" class="plan-dist-prices">
                                    <template v-for="(price, i) in plan.prices_summary" :key="i">
                                        <span v-if="i > 0"> · </span>
                                        {{ price.interval_label }} {{ gbp(price.price) }}
                                    </template>
                                </div>
                            </div>
                            <div v-if="no_plan_count > 0" class="plan-dist-row" style="background: var(--warning-bg);">
                                <div class="plan-dist-top">
                                    <div class="plan-dist-name" style="color: #B45309;">No plan assigned</div>
                                    <div class="plan-dist-right">
                                        <span class="badge badge-pending badge-sm">{{ no_plan_count }} customer{{ no_plan_count === 1 ? '' : 's' }}</span>
                                    </div>
                                </div>
                            </div>
                            <div style="padding: 10px 16px; border-top: 1px solid var(--border-soft); text-align: right;">
                                <button type="button" style="background: none; border: 0; padding: 0; color: var(--accent); font: 500 12px/1 'Inter', sans-serif; cursor: pointer;" @click="gotoPlans">
                                    Manage plans →
                                </button>
                            </div>
                        </template>
                    </section>

                    <!-- Recent customers -->
                    <section class="card" style="margin-top: 16px;">
                        <header class="card-header">
                            <div class="h-icon"><IconUsers :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Recent customers</h3>
                                <div class="sub">Most recently enrolled</div>
                            </div>
                            <div class="right">
                                <button type="button" style="background: none; border: 0; padding: 0; color: var(--accent); font: 500 12px/1 'Inter', sans-serif; cursor: pointer;" @click="gotoCustomers">
                                    View all →
                                </button>
                            </div>
                        </header>
                        <div v-if="! recent_customers.length" style="padding: 28px 16px; text-align: center; color: var(--text-secondary); font: 400 13px/1.4 'Inter', sans-serif;">
                            No customers yet
                        </div>
                        <template v-else>
                            <div v-for="cust in recent_customers" :key="`rc-${cust.customer_id}`" class="cust-row-product" @click="gotoCustomer(cust.customer_id)">
                                <div class="cust-row-left">
                                    <div class="avatar" :style="{ background: avatarColour(cust.customer_id), color: '#fff', width: '30px', height: '30px', fontSize: '11px' }">
                                        {{ initials(cust.customer_name) }}
                                    </div>
                                    <div class="cust-row-info">
                                        <div class="cust-row-name">{{ cust.customer_name }}</div>
                                        <div class="cust-row-meta">
                                            {{ cust.customer_city || '—' }}
                                            <template v-if="cust.started_at"> · Started {{ timeAgo(cust.started_at) }}</template>
                                            <template v-if="cust.status === 'trial' && cust.trial_ends_at">
                                                · <span :style="trialUrgent(cust) ? 'color: var(--warning);' : ''">Trial ends {{ formatDate(cust.trial_ends_at) }}</span>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                                <div class="cust-row-right">
                                    <span v-if="cust.plan_name" class="badge badge-info badge-sm">{{ cust.plan_name }}</span>
                                    <span v-if="cust.status === 'trial'" class="badge badge-pending badge-sm">Trial</span>
                                    <span v-if="cust.price > 0" style="font: 600 13px/1.2 'Inter', sans-serif; color: var(--accent);">{{ gbp(cust.price) }}</span>
                                </div>
                            </div>
                        </template>
                    </section>
                </div>

                <!-- RIGHT 35% -->
                <div class="main-col-right">
                    <!-- 6-month trend -->
                    <section class="card" style="box-shadow: var(--shadow-md);">
                        <header class="card-header">
                            <div class="h-icon"><IconChartBar :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>6-month trend</h3>
                                <div class="sub">New vs. churned subscriptions</div>
                            </div>
                        </header>
                        <div style="padding: 14px 16px;">
                            <div v-if="trendIsEmpty" style="padding: 14px 0; text-align: center; color: var(--text-tertiary); font: 400 13px/1.4 'Inter', sans-serif;">
                                No data yet for this product
                            </div>
                            <template v-else>
                                <div class="trend-bars">
                                    <div v-for="(m, i) in trend" :key="`tm-${i}`" class="trend-month">
                                        <div class="trend-bar-group">
                                            <div class="trend-bar new" :style="{ height: barHeight(m.new) + 'px' }" :title="`New: ${m.new}`" />
                                            <div class="trend-bar churned" :style="{ height: barHeight(m.churned) + 'px' }" :title="`Churned: ${m.churned}`" />
                                        </div>
                                        <div class="trend-label">{{ m.month }}</div>
                                    </div>
                                </div>
                            </template>
                            <div class="trend-legend">
                                <div class="trend-legend-item">
                                    <div class="trend-dot new" />
                                    New
                                </div>
                                <div class="trend-legend-item">
                                    <div class="trend-dot churned" />
                                    Churned
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Activity -->
                    <section class="card" style="margin-top: 16px;">
                        <header class="card-header">
                            <div class="h-icon"><IconActivity :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Activity</h3>
                                <div class="sub">Product enable / suspend events</div>
                            </div>
                        </header>
                        <div v-if="! activity.length" style="padding: 24px 16px; text-align: center; color: var(--text-secondary); font: 400 13px/1.4 'Inter', sans-serif;">
                            No activity yet
                        </div>
                        <template v-else>
                            <div v-for="a in activity" :key="`act-${a.id}`" class="act-row-product">
                                <div class="act-row-icon" :class="activityIconClass(a.action)">
                                    <component :is="activityIcon(a.action)" :size="14" stroke-width="1.75" />
                                </div>
                                <div style="flex: 1; min-width: 0;">
                                    <div style="font: 500 12.5px/1.3 'Inter', sans-serif;">
                                        {{ activityLabel(a.action) }}
                                        <Link :href="`/customers/${a.customer_id}`" style="color: var(--accent); text-decoration: none;">customer #{{ a.customer_id }}</Link>
                                    </div>
                                    <div style="font: 400 11px/1.3 'Inter', sans-serif; color: var(--text-tertiary); margin-top: 2px;">
                                        {{ a.time_ago }}
                                    </div>
                                </div>
                            </div>
                        </template>
                    </section>

                    <!-- Quick actions -->
                    <section class="card" style="margin-top: 16px;">
                        <header class="card-header">
                            <div class="h-icon"><IconBolt :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Quick actions</h3>
                            </div>
                        </header>
                        <div class="quick-action-row" @click="gotoCustomers">
                            <div class="quick-action-icon" style="color: var(--success);"><IconUserPlus :size="16" stroke-width="1.75" /></div>
                            <div class="quick-action-label">Enable for a new customer</div>
                            <IconArrowRight :size="14" stroke-width="1.75" style="color: var(--text-tertiary);" />
                        </div>
                        <div class="quick-action-row" @click="gotoPlans">
                            <div class="quick-action-icon" style="color: var(--accent);"><IconTag :size="16" stroke-width="1.75" /></div>
                            <div class="quick-action-label">Manage plans &amp; pricing</div>
                            <IconArrowRight :size="14" stroke-width="1.75" style="color: var(--text-tertiary);" />
                        </div>
                        <div class="quick-action-row" @click="gotoSubscriptions()">
                            <div class="quick-action-icon" style="color: var(--info);"><IconReceipt :size="16" stroke-width="1.75" /></div>
                            <div class="quick-action-label">View all subscriptions</div>
                            <IconArrowRight :size="14" stroke-width="1.75" style="color: var(--text-tertiary);" />
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </InternalLayout>
</template>
