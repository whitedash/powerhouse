<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import {
    IconArrowRight,
    IconCoin,
    IconHourglass,
    IconCircleCheck,
    IconUsers,
    IconCopy,
    IconCheck,
    IconClick,
} from '@tabler/icons-vue';
import ReferrerLayout from '@/Layouts/ReferrerLayout.vue';

const props = defineProps({
    referrer: { type: Object, required: true },
    summary: { type: Object, required: true },
    recent_commissions: { type: Array, default: () => [] },
    recent_customers: { type: Array, default: () => [] },
});

const firstName = computed(() => (props.referrer.name || '').split(/\s+/)[0] || 'partner');

function gbp(n) {
    return `£${Number(n).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function commissionBadge(status) {
    if (status === 'paid') return { cls: 'badge-active', label: 'Paid' };
    if (status === 'approved') return { cls: 'badge-info', label: 'Approved' };
    if (status === 'pending') return { cls: 'badge-pending', label: 'Pending' };
    if (status === 'voided') return { cls: 'badge-inactive', label: 'Voided' };
    return { cls: 'badge-inactive', label: status };
}

function triggerLabel(trigger) {
    switch (trigger) {
        case 'onboarding': return 'Onboarding bonus';
        case 'invoice_paid': return 'Invoice commission';
        case 'monthly_recurring': return 'Monthly recurring';
        default: return trigger;
    }
}

function customerInitials(name) {
    const parts = (name || '').trim().split(/\s+/);
    return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || '?';
}

const copied = ref(false);
function copyLink() {
    if (! props.referrer.referral_link) return;
    navigator.clipboard?.writeText(props.referrer.referral_link).then(() => {
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 1800);
    });
}
</script>

<template>
    <Head title="Dashboard · Whitedash Partners" />
    <ReferrerLayout title="Dashboard" active-nav="dashboard">
        <!-- Welcome card -->
        <section class="portal-welcome">
            <div class="portal-welcome-label">Welcome back</div>
            <div class="portal-welcome-name">{{ firstName }}</div>
            <div class="portal-welcome-sub">
                {{ summary.customer_count }} referred compan{{ summary.customer_count === 1 ? 'y' : 'ies' }}
                <template v-if="summary.all_time_paid > 0">
                    · {{ gbp(summary.all_time_paid) }} paid all-time
                </template>
            </div>
        </section>

        <!-- Referral link -->
        <section v-if="referrer.referral_link" class="referral-link-card">
            <div class="rlc-main">
                <div class="rlc-label"><IconClick :size="14" stroke-width="1.75" /> Your referral link</div>
                <div class="rlc-link">{{ referrer.referral_link }}</div>
            </div>
            <div class="rlc-side">
                <div class="rlc-clicks">
                    <span class="rlc-clicks-value">{{ referrer.click_count }}</span>
                    <span class="rlc-clicks-label">click{{ referrer.click_count === 1 ? '' : 's' }}</span>
                </div>
                <button type="button" class="btn btn-primary btn-sm" @click="copyLink">
                    <component :is="copied ? IconCheck : IconCopy" :size="14" stroke-width="1.75" />
                    {{ copied ? 'Copied' : 'Copy' }}
                </button>
            </div>
        </section>

        <!-- 4 KPI cards -->
        <div class="referrer-kpi-grid">
            <div class="metric-card">
                <div class="metric-ic" style="background: rgba(13, 148, 136, .12); color: var(--teal);">
                    <IconUsers :size="20" stroke-width="1.75" />
                </div>
                <div>
                    <div class="metric-value">{{ summary.customer_count }}</div>
                    <div class="metric-label">Companies referred</div>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-ic warning">
                    <IconHourglass :size="20" stroke-width="1.75" />
                </div>
                <div>
                    <div class="metric-value">{{ gbp(summary.pending_commission) }}</div>
                    <div class="metric-label">Pending payout</div>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-ic" style="background: var(--info-bg); color: var(--info);">
                    <IconCoin :size="20" stroke-width="1.75" />
                </div>
                <div>
                    <div class="metric-value">{{ gbp(summary.approved_commission) }}</div>
                    <div class="metric-label">Approved</div>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-ic success">
                    <IconCircleCheck :size="20" stroke-width="1.75" />
                </div>
                <div>
                    <div class="metric-value">{{ gbp(summary.paid_this_year) }}</div>
                    <div class="metric-label">Paid this year</div>
                </div>
            </div>
        </div>

        <div class="referrer-split">
            <!-- Recent commissions -->
            <section>
                <div class="portal-section-head">
                    <div class="col-l">
                        <h2>Recent commissions</h2>
                        <div class="desc">Latest 5 entries on your ledger.</div>
                    </div>
                    <Link href="/referrer/commissions" class="ghost-link">
                        View all
                        <IconArrowRight :size="14" stroke-width="1.75" />
                    </Link>
                </div>

                <div class="card">
                    <div v-if="recent_commissions.length === 0" class="portal-empty-inline">
                        <span>No commission entries yet.</span>
                    </div>
                    <template v-else>
                        <div
                            v-for="c in recent_commissions"
                            :key="c.id"
                            class="referrer-com-row"
                            :class="c.status"
                        >
                            <div class="referrer-com-main">
                                <div class="referrer-com-title">{{ c.customer_name }}</div>
                                <div class="referrer-com-sub">
                                    {{ c.product_name }} · {{ triggerLabel(c.trigger_type) }} · {{ c.created_at }}
                                </div>
                            </div>
                            <div class="referrer-com-right">
                                <div class="referrer-com-amt">{{ gbp(c.amount) }}</div>
                                <span class="badge badge-sm" :class="commissionBadge(c.status).cls">
                                    {{ commissionBadge(c.status).label }}
                                </span>
                            </div>
                        </div>
                    </template>
                </div>
            </section>

            <!-- Recent customers -->
            <section>
                <div class="portal-section-head">
                    <div class="col-l">
                        <h2>Recently referred</h2>
                        <div class="desc">Your newest 5 customers.</div>
                    </div>
                    <Link href="/referrer/companies" class="ghost-link">
                        View all
                        <IconArrowRight :size="14" stroke-width="1.75" />
                    </Link>
                </div>

                <div class="card">
                    <div v-if="recent_customers.length === 0" class="portal-empty-inline">
                        <span>No referred companies yet.</span>
                    </div>
                    <template v-else>
                        <div v-for="(r, i) in recent_customers" :key="i" class="referrer-cust-row">
                            <div class="portal-avatar av-teal" style="width: 32px; height: 32px;">{{ customerInitials(r.name) }}</div>
                            <div class="referrer-cust-main">
                                <div class="referrer-cust-name">{{ r.name }}</div>
                                <div class="referrer-cust-sub">
                                    <template v-if="r.city">{{ r.city }} · </template>
                                    Joined {{ r.joined }}
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </section>
        </div>
    </ReferrerLayout>
</template>

<style scoped>
.referral-link-card {
    display: flex; align-items: center; justify-content: space-between; gap: 16px;
    padding: 16px 18px; margin-bottom: 16px;
    background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg, 12px);
}
.rlc-main { min-width: 0; flex: 1; }
.rlc-label { display: flex; align-items: center; gap: 6px; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); margin-bottom: 4px; }
.rlc-link { font-family: var(--font-mono, monospace); font-weight: 600; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rlc-side { display: flex; align-items: center; gap: 16px; flex-shrink: 0; }
.rlc-clicks { text-align: right; line-height: 1.1; }
.rlc-clicks-value { display: block; font-size: 1.25rem; font-weight: 700; color: var(--text); }
.rlc-clicks-label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); }
</style>
