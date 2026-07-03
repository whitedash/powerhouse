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
    IconHeadset,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';

const props = defineProps({
    headline: { type: Object, required: true },
    mrr_trend: { type: Array, default: () => [] },
    // 'subscriptions' = the trend excludes hosting/domains (no historical
    // status timeline to reconstruct them honestly). The headline MRR is whole.
    mrr_trend_basis: { type: String, default: 'subscriptions' },
    by_product: { type: Array, default: () => [] },
    customer_growth: { type: Array, default: () => [] },
    top_referrers: { type: Array, default: () => [] },
    plan_popularity: { type: Array, default: () => [] },
    support: { type: Object, default: () => ({}) },
    range: { type: Number, default: 90 },
});

const STATUS_LABELS = {
    open: 'Open', in_progress: 'In progress', awaiting_customer: 'Awaiting customer',
    resolved: 'Resolved', closed: 'Closed',
};
const PRIORITY_LABELS = { urgent: 'Urgent', high: 'High', medium: 'Medium', low: 'Low' };

// No-data guards: an absent average reads as "—", never "0h"/"0%".
const fmtHours = (v) => (v === null || v === undefined ? '—' : `${v}h`);
const fmtPct = (v) => (v === null || v === undefined ? '—' : `${v}%`);

// Turn a {key: count} map into sorted rows with a proportion (bar scaled to
// the largest count in the group, mirroring the Revenue-by-product bars).
function distRows(map, labels = null) {
    const entries = Object.entries(map || {});
    const max = Math.max(1, ...entries.map(([, n]) => Number(n)));

    return entries
        .map(([key, n]) => ({
            key,
            label: labels ? (labels[key] ?? key) : key,
            n: Number(n),
            pct: Math.round((Number(n) / max) * 100),
        }))
        .sort((a, b) => b.n - a.n);
}

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
                    <div class="kpi-foot">{{ headline.total_customers }} total companies on file</div>
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
                                <div class="sub">
                                    Last 12 months · point-in-time at month-end<template v-if="mrr_trend_basis === 'subscriptions'"> · subscriptions only</template>
                                </div>
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
                                <h3>Company growth</h3>
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

                    <!-- ─── Support — first-response SLA & volume ─── -->
                    <section v-if="support && support.total !== undefined" class="card" style="margin-top: 16px;">
                        <header class="card-header">
                            <div class="h-icon gold"><IconHeadset :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Support — first-response SLA &amp; volume</h3>
                                <div class="sub">{{ support.total }} ticket{{ support.total === 1 ? '' : 's' }} · resolution time is raw (no pause)</div>
                            </div>
                        </header>

                        <!-- KPI row: equal label heights, "Within SLA" featured -->
                        <div class="sla-kpis">
                            <div class="sla-kpi sla-kpi--feature">
                                <div class="sla-kpi-label">Within first-response SLA</div>
                                <div class="sla-kpi-value">{{ fmtPct(support.pct_within_sla) }}</div>
                            </div>
                            <div class="sla-kpi">
                                <div class="sla-kpi-label">Avg first response</div>
                                <div class="sla-kpi-value">{{ fmtHours(support.avg_first_response_hours) }}</div>
                            </div>
                            <div class="sla-kpi">
                                <div class="sla-kpi-label">Breaches</div>
                                <div class="sla-kpi-value" :class="{ 'is-danger': support.breached > 0 }">
                                    {{ support.breached }}<span v-if="support.breach_rate !== null" class="sla-kpi-foot">{{ support.breach_rate }}%</span>
                                </div>
                            </div>
                            <div class="sla-kpi">
                                <div class="sla-kpi-label">Avg resolution</div>
                                <div class="sla-kpi-value">{{ fmtHours(support.avg_resolution_hours) }}</div>
                            </div>
                            <div class="sla-kpi">
                                <div class="sla-kpi-label">Reopen rate</div>
                                <div class="sla-kpi-value">{{ fmtPct(support.reopen_rate) }}</div>
                            </div>
                        </div>

                        <div class="sla-divider" />

                        <!-- Breakdown: three mini bar-lists (proportion bar + aligned count) -->
                        <div class="sla-dists">
                            <div class="sla-dist">
                                <div class="sla-dist-title">By status</div>
                                <div v-for="row in distRows(support.by_status, STATUS_LABELS)" :key="`st-${row.key}`" class="sla-dist-row">
                                    <span class="sla-dist-label">{{ row.label }}</span>
                                    <span class="sla-dist-track"><span class="sla-dist-fill" :style="{ width: row.pct + '%' }" /></span>
                                    <span class="sla-dist-count">{{ row.n }}</span>
                                </div>
                            </div>
                            <div class="sla-dist">
                                <div class="sla-dist-title">By priority</div>
                                <div v-for="row in distRows(support.by_priority, PRIORITY_LABELS)" :key="`pr-${row.key}`" class="sla-dist-row">
                                    <span class="sla-dist-label">{{ row.label }}</span>
                                    <span class="sla-dist-track"><span class="sla-dist-fill" :style="{ width: row.pct + '%' }" /></span>
                                    <span class="sla-dist-count">{{ row.n }}</span>
                                </div>
                            </div>
                            <div class="sla-dist">
                                <div class="sla-dist-title">By product</div>
                                <div v-for="row in distRows(support.by_product)" :key="`pd-${row.key}`" class="sla-dist-row">
                                    <span class="sla-dist-label">{{ row.label }}</span>
                                    <span class="sla-dist-track"><span class="sla-dist-fill" :style="{ width: row.pct + '%' }" /></span>
                                    <span class="sla-dist-count">{{ row.n }}</span>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </InternalLayout>
</template>

<style scoped>
/* KPI row — tiles wrap; every label reserves the same height so all value
   baselines align regardless of 1-, 2- or 3-line labels. */
.sla-kpis {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 10px;
    padding: 16px 18px;
}
.sla-kpi {
    background: var(--neutral-bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
}
.sla-kpi-label {
    font: 500 11px/1.3 'Inter', sans-serif;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--text-tertiary);
    min-height: 2.6em; /* equalises 1–2 line labels → aligned value baselines */
}
.sla-kpi-value {
    margin-top: auto;
    padding-top: 8px;
    font: 700 22px/1.1 'Inter', sans-serif;
    color: var(--text-primary);
    font-variant-numeric: tabular-nums;
    display: flex;
    align-items: baseline;
    gap: 6px;
}
.sla-kpi-value.is-danger { color: var(--danger); }
.sla-kpi-foot { font: 600 12px/1 'Inter', sans-serif; color: var(--text-tertiary); }

/* "Within SLA" is the headline metric — subtle accent emphasis. */
.sla-kpi--feature {
    background: rgba(245, 158, 11, .06);
    border-color: rgba(245, 158, 11, .35);
}
.sla-kpi--feature .sla-kpi-label { color: #B45309; }
.sla-kpi--feature .sla-kpi-value { color: var(--accent); }

.sla-divider {
    height: 1px;
    background: var(--border);
    margin: 0 18px;
}

/* Three mini bar-lists — proportion bar + right-aligned count, even rows. */
.sla-dists {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 20px;
    padding: 16px 18px 18px;
}
.sla-dist-title {
    font: 600 11px/1 'Inter', sans-serif;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--text-secondary);
    margin-bottom: 10px;
}
.sla-dist-row {
    display: grid;
    grid-template-columns: 84px 1fr 28px;
    align-items: center;
    gap: 10px;
    height: 26px;
}
.sla-dist-label {
    font: 400 12.5px/1 'Inter', sans-serif;
    color: var(--text-secondary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.sla-dist-track {
    height: 6px;
    border-radius: 999px;
    background: var(--neutral-bg);
    overflow: hidden;
}
.sla-dist-fill {
    display: block;
    height: 100%;
    border-radius: 999px;
    background: var(--accent);
}
.sla-dist-count {
    font: 600 12.5px/1 'Inter', sans-serif;
    color: var(--text-primary);
    font-variant-numeric: tabular-nums;
    text-align: right;
}

@media (max-width: 920px) {
    .sla-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .sla-dists { grid-template-columns: 1fr; }
}
</style>
