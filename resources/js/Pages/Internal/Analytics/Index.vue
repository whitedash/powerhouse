<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    IconCurrencyPound,
    IconUsers,
    IconClock,
    IconUserMinus,
    IconChartBar,
    IconChartLine,
    IconAward,
    IconTag,
    IconArrowRight,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';

const props = defineProps({
    headline: { type: Object, required: true },
    mrr_trend: { type: Array, default: () => [] },
    by_product: { type: Array, default: () => [] },
    customer_growth: { type: Array, default: () => [] },
    top_referrers: { type: Array, default: () => [] },
    plan_popularity: { type: Array, default: () => [] },
    range: { type: Number, default: 90 },
});

const breadcrumbs = [{ label: 'Analytics' }];

/* ─── Money ─── */
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

/* ─── Avatar ─── */
function initials(name) {
    const parts = String(name || '').trim().split(/\s+/);
    return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || '?';
}
function avatarColour(id) {
    const palette = ['#0D9488', '#F59E0B', '#3B82F6', '#10B981', '#7C3AED', '#EF4444', '#06B6D4', '#6366F1'];
    return palette[Number(id) % palette.length];
}

/* ─── Range selector ─── */
const RANGE_OPTIONS = [
    { value: 30, label: '30d' },
    { value: 90, label: '90d' },
    { value: 180, label: '180d' },
    { value: 365, label: '365d' },
];
function setRange(value) {
    router.get('/analytics', { range: value }, { preserveScroll: true, preserveState: true, replace: true });
}

/* ─── Headline derived ─── */
const churnTone = computed(() => {
    const r = props.headline.churn_rate;
    if (r > 5) return 'down';
    if (r > 2) return 'warn';
    return 'up';
});

/* ─── By-product max for proportional bars ─── */
const maxProductMrr = computed(() => {
    let m = 0;
    for (const p of props.by_product) if (p.mrr > m) m = p.mrr;
    return m;
});
function productBarWidth(p) {
    if (maxProductMrr.value <= 0) return 0;
    return Math.max(2, Math.round((p.mrr / maxProductMrr.value) * 100));
}

/* ─── MRR trend chart geometry ─── */
const mrrMax = computed(() => {
    let m = 0;
    for (const r of props.mrr_trend) if (r.mrr > m) m = r.mrr;
    return m;
});
function mrrBarHeight(value) {
    if (mrrMax.value <= 0) return 0;
    return Math.max(2, Math.round((value / mrrMax.value) * 120));
}
const mrrIsEmpty = computed(() => mrrMax.value === 0);

/* ─── Customer growth chart geometry ─── */
const growthMax = computed(() => {
    let m = 0;
    for (const r of props.customer_growth) {
        if (r.new > m) m = r.new;
        if (r.archived > m) m = r.archived;
    }
    return m;
});
function growthBarHeight(value) {
    if (growthMax.value <= 0) return 0;
    return Math.max(2, Math.round((value / growthMax.value) * 80));
}
const growthIsEmpty = computed(() => growthMax.value === 0);

/* ─── Plan popularity max for proportional bars ─── */
const maxPlanActive = computed(() => {
    let m = 0;
    for (const p of props.plan_popularity) if (p.active_count > m) m = p.active_count;
    return m;
});
function planBarWidth(p) {
    if (maxPlanActive.value <= 0) return 0;
    return Math.max(4, Math.round((p.active_count / maxPlanActive.value) * 100));
}
</script>

<template>
    <Head title="Analytics" />

    <InternalLayout title="Analytics" :breadcrumbs="breadcrumbs" active-nav="analytics">
        <template #topbar-actions>
            <div class="range-pills">
                <button
                    v-for="r in RANGE_OPTIONS"
                    :key="r.value"
                    type="button"
                    class="range-pill"
                    :class="{ active: range === r.value }"
                    @click="setRange(r.value)"
                >
                    {{ r.label }}
                </button>
            </div>
        </template>

        <div class="analytics">
            <!-- ─── Headline (2 × 2) ─── -->
            <div class="headline-grid">
                <div class="kpi">
                    <div class="kpi-top"><div class="kpi-icon gold"><IconCurrencyPound :size="18" stroke-width="1.75" /></div></div>
                    <div class="kpi-mid">
                        <div class="kpi-value">{{ gbp(headline.total_mrr) }}</div>
                        <div class="kpi-label">Monthly recurring revenue</div>
                    </div>
                    <div class="kpi-foot">{{ gbpRound(headline.total_arr) }} ARR</div>
                </div>

                <div class="kpi">
                    <div class="kpi-top"><div class="kpi-icon teal"><IconUsers :size="18" stroke-width="1.75" /></div></div>
                    <div class="kpi-mid">
                        <div class="kpi-value">{{ headline.paying_customers }}</div>
                        <div class="kpi-label">Paying customers</div>
                    </div>
                    <div class="kpi-foot">
                        Avg {{ gbp(headline.avg_revenue_per_customer) }}/mo per customer
                    </div>
                </div>

                <div class="kpi">
                    <div class="kpi-top"><div class="kpi-icon amber"><IconClock :size="18" stroke-width="1.75" /></div></div>
                    <div class="kpi-mid">
                        <div class="kpi-value">{{ headline.trial_customers }}</div>
                        <div class="kpi-label">Trial customers</div>
                    </div>
                    <div class="kpi-foot">{{ headline.total_customers }} total customers on file</div>
                </div>

                <div class="kpi">
                    <div class="kpi-top">
                        <div class="kpi-icon" :class="churnTone === 'down' ? 'red' : (churnTone === 'warn' ? 'amber' : 'teal')">
                            <IconUserMinus :size="18" stroke-width="1.75" />
                        </div>
                    </div>
                    <div class="kpi-mid">
                        <div class="kpi-value" :class="{ 'text-danger': churnTone === 'down' }">
                            {{ headline.churn_rate }}%
                        </div>
                        <div class="kpi-label">Churn rate</div>
                    </div>
                    <div class="kpi-foot" :class="churnTone">
                        <template v-if="churnTone === 'down'">High — {{ headline.churn_rate }}% this month</template>
                        <template v-else-if="churnTone === 'warn'">Elevated — {{ headline.churn_rate }}% this month</template>
                        <template v-else>Healthy — {{ headline.churn_rate }}% this month</template>
                    </div>
                </div>
            </div>

            <!-- ─── Revenue by product (horizontal bars) ─── -->
            <section class="card" style="margin-top: 16px;">
                <header class="card-header">
                    <div class="h-icon gold"><IconChartBar :size="16" stroke-width="1.75" /></div>
                    <div>
                        <h3>Revenue by product</h3>
                        <div class="sub">Active subscription MRR · sorted by contribution</div>
                    </div>
                </header>
                <div v-if="by_product.length === 0" style="padding: 32px 16px; text-align: center; color: var(--text-secondary); font: 400 13px/1.4 'Inter', sans-serif;">
                    No active products
                </div>
                <div v-else style="padding: 6px 0;">
                    <div v-for="p in by_product" :key="`bp-${p.slug}`" class="prod-bar-row">
                        <div class="prod-bar-icon" :style="{ background: p.icon_colour || '#0D9488' }">
                            {{ (p.name?.[0] || '?').toUpperCase() }}
                        </div>
                        <div class="prod-bar-main">
                            <div class="prod-bar-name-row">
                                <Link :href="`/products/${p.slug}`" class="prod-bar-name">{{ p.name }}</Link>
                                <span class="prod-bar-mrr">{{ gbpRound(p.mrr) }}/mo</span>
                            </div>
                            <div class="prod-bar-track">
                                <div
                                    class="prod-bar-fill"
                                    :style="{
                                        width: productBarWidth(p) + '%',
                                        background: p.icon_colour || 'var(--accent)',
                                    }"
                                />
                            </div>
                            <div class="prod-bar-meta">
                                {{ p.active }} active
                                <template v-if="p.trial > 0"> · {{ p.trial }} trial</template>
                                <template v-if="p.churned_this_month > 0"> · {{ p.churned_this_month }} churned this month</template>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ─── Two-column: 60/40 ─── -->
            <div class="analytics-grid">
                <!-- LEFT 60% — trend + growth -->
                <div class="ana-col-left">
                    <!-- MRR trend (12 months) -->
                    <section class="card">
                        <header class="card-header">
                            <div class="h-icon"><IconChartLine :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>MRR trend</h3>
                                <div class="sub">Last 12 months · point-in-time at month-end</div>
                            </div>
                        </header>
                        <div style="padding: 14px 18px 18px;">
                            <div v-if="mrrIsEmpty" style="padding: 14px 0; text-align: center; color: var(--text-tertiary); font: 400 13px/1.4 'Inter', sans-serif;">
                                No revenue data yet
                            </div>
                            <template v-else>
                                <div class="ana-trend-bars">
                                    <div v-for="(m, i) in mrr_trend" :key="`mt-${i}`" class="ana-trend-month">
                                        <div class="ana-trend-tooltip">{{ gbp(m.mrr) }}</div>
                                        <div
                                            class="ana-trend-bar mrr"
                                            :style="{ height: mrrBarHeight(m.mrr) + 'px' }"
                                        />
                                        <div class="ana-trend-label">{{ m.month_short }}</div>
                                    </div>
                                </div>
                                <div class="ana-trend-legend">
                                    <div class="ana-trend-legend-item">
                                        <div class="ana-trend-dot mrr" />
                                        MRR at month end
                                    </div>
                                </div>
                            </template>
                        </div>
                    </section>

                    <!-- Customer growth -->
                    <section class="card" style="margin-top: 16px;">
                        <header class="card-header">
                            <div class="h-icon"><IconUsers :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Customer growth</h3>
                                <div class="sub">New vs. archived per month</div>
                            </div>
                            <div class="right">
                                <span style="font: 400 12px/1.3 'Inter', sans-serif; color: var(--text-tertiary);">
                                    Total: {{ headline.total_customers }}
                                </span>
                            </div>
                        </header>
                        <div style="padding: 14px 18px 18px;">
                            <div v-if="growthIsEmpty" style="padding: 14px 0; text-align: center; color: var(--text-tertiary); font: 400 13px/1.4 'Inter', sans-serif;">
                                No customer movement yet
                            </div>
                            <template v-else>
                                <div class="ana-trend-bars">
                                    <div v-for="(m, i) in customer_growth" :key="`cg-${i}`" class="ana-trend-month">
                                        <div class="ana-trend-tooltip">
                                            +{{ m.new }} new<br>
                                            <template v-if="m.archived > 0">−{{ m.archived }} archived<br></template>
                                            {{ m.cumulative }} total
                                        </div>
                                        <div class="ana-trend-bar-group">
                                            <div class="ana-trend-bar new" :style="{ height: growthBarHeight(m.new) + 'px' }" />
                                            <div class="ana-trend-bar churned" :style="{ height: growthBarHeight(m.archived) + 'px' }" />
                                        </div>
                                        <div class="ana-trend-label">{{ m.month_short }}</div>
                                    </div>
                                </div>
                                <div class="ana-trend-legend">
                                    <div class="ana-trend-legend-item">
                                        <div class="ana-trend-dot new" />
                                        New
                                    </div>
                                    <div class="ana-trend-legend-item">
                                        <div class="ana-trend-dot churned" />
                                        Archived
                                    </div>
                                </div>
                            </template>
                        </div>
                    </section>
                </div>

                <!-- RIGHT 40% — referrers + plan popularity -->
                <div class="ana-col-right">
                    <!-- Top referrers -->
                    <section class="card">
                        <header class="card-header">
                            <div class="h-icon"><IconAward :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Top referrers</h3>
                                <div class="sub">By customer count</div>
                            </div>
                            <div class="right">
                                <Link href="/referrers" style="font: 500 12px/1 'Inter', sans-serif; color: var(--accent); text-decoration: none;">
                                    View all →
                                </Link>
                            </div>
                        </header>
                        <div v-if="top_referrers.length === 0" style="padding: 28px 16px; text-align: center; color: var(--text-secondary); font: 400 13px/1.4 'Inter', sans-serif;">
                            No referrers yet
                        </div>
                        <template v-else>
                            <div v-for="(r, idx) in top_referrers" :key="`ref-${idx}`" class="ana-ref-row">
                                <div class="avatar" :style="{ background: avatarColour(idx), color: '#fff', width: '30px', height: '30px', fontSize: '11px' }">
                                    {{ initials(r.name) }}
                                </div>
                                <div style="flex: 1; min-width: 0;">
                                    <div style="font: 500 13px/1.3 'Inter', sans-serif;">{{ r.name }}</div>
                                    <div style="font: 400 11.5px/1.3 'Inter', sans-serif; color: var(--text-tertiary); margin-top: 2px;">
                                        {{ r.customer_count }} customer{{ r.customer_count === 1 ? '' : 's' }} referred
                                    </div>
                                </div>
                                <div style="text-align: right;">
                                    <div v-if="r.pending_commission > 0" style="font: 600 12px/1 'Inter', sans-serif; color: var(--warning);">
                                        {{ gbpRound(r.pending_commission) }} pending
                                    </div>
                                    <div style="font: 400 11px/1.3 'Inter', sans-serif; color: var(--text-tertiary); margin-top: 2px;">
                                        {{ gbpRound(r.paid_commission) }} paid
                                    </div>
                                </div>
                            </div>
                        </template>
                    </section>

                    <!-- Plan popularity -->
                    <section class="card" style="margin-top: 16px;">
                        <header class="card-header">
                            <div class="h-icon gold"><IconTag :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Plan popularity</h3>
                                <div class="sub">Top 10 by active subscriptions</div>
                            </div>
                        </header>
                        <div v-if="plan_popularity.length === 0" style="padding: 28px 16px; text-align: center; color: var(--text-secondary); font: 400 13px/1.4 'Inter', sans-serif;">
                            No active plans yet
                        </div>
                        <template v-else>
                            <div v-for="(p, idx) in plan_popularity" :key="`pp-${idx}`" class="ana-plan-row">
                                <div class="ana-plan-icon" :style="{ background: p.icon_colour || '#0D9488' }">
                                    {{ (p.product_name?.[0] || '?').toUpperCase() }}
                                </div>
                                <div class="ana-plan-main">
                                    <div class="ana-plan-name-row">
                                        <span class="ana-plan-name">{{ p.plan_name }}</span>
                                        <span class="ana-plan-product">· {{ p.product_name }}</span>
                                    </div>
                                    <div class="ana-plan-track">
                                        <div
                                            class="ana-plan-fill"
                                            :style="{ width: planBarWidth(p) + '%', background: p.icon_colour || 'var(--accent)' }"
                                        />
                                    </div>
                                </div>
                                <div class="ana-plan-stats">
                                    <div class="ana-plan-count">{{ p.active_count }}</div>
                                    <div class="ana-plan-mrr">{{ gbpRound(p.mrr) }}/mo</div>
                                </div>
                            </div>
                        </template>
                    </section>
                </div>
            </div>
        </div>
    </InternalLayout>
</template>
