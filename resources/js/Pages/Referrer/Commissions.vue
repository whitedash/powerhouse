<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { IconCoin } from '@tabler/icons-vue';
import ReferrerLayout from '@/Layouts/ReferrerLayout.vue';

defineProps({
    commissions: { type: Object, required: true },
    totals: { type: Object, required: true },
});

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

function formatPeriod(c) {
    if (! c.period_start && ! c.period_end) return '—';
    if (c.period_start && c.period_end) return `${c.period_start} → ${c.period_end}`;
    return c.period_start ?? c.period_end;
}
</script>

<template>
    <Head title="Commissions · Whitedash Partners" />
    <ReferrerLayout title="Commissions" active-nav="commissions">
        <div class="portal-section-head">
            <div class="col-l">
                <h2>Commission ledger</h2>
                <div class="desc">Every credit on your account, oldest first to newest.</div>
            </div>
        </div>

        <!-- Totals strip -->
        <div class="referrer-totals">
            <div class="referrer-total-pill pending">
                <span class="k">Pending</span>
                <span class="v">{{ gbp(totals.pending) }}</span>
            </div>
            <div class="referrer-total-pill approved">
                <span class="k">Approved</span>
                <span class="v">{{ gbp(totals.approved) }}</span>
            </div>
            <div class="referrer-total-pill paid">
                <span class="k">Paid</span>
                <span class="v">{{ gbp(totals.paid) }}</span>
            </div>
        </div>

        <!-- Commission table -->
        <div v-if="commissions.data.length === 0" class="portal-empty">
            <IconCoin :size="32" stroke-width="1.5" />
            <div class="portal-empty-title">No commissions yet</div>
            <div class="portal-empty-sub">
                Once your referred customers convert and pay, credits will land here automatically.
            </div>
        </div>

        <div v-else class="card">
            <table class="referrer-table">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Product</th>
                        <th>Type</th>
                        <th class="num">Gross</th>
                        <th class="num">Commission</th>
                        <th>Status</th>
                        <th>Period</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in commissions.data" :key="c.id">
                        <td>
                            <div style="font: 500 13px/1.3 'Inter', sans-serif;">{{ c.customer_name }}</div>
                            <div style="font: 400 11.5px/1.3 'Inter', sans-serif; color: var(--text-tertiary); margin-top: 2px;">
                                {{ c.created_at }}
                            </div>
                        </td>
                        <td>
                            <span
                                class="referrer-prod-chip"
                                :style="{ background: (c.product_colour || 'var(--accent)') + '22', color: c.product_colour || 'var(--text-primary)' }"
                            >{{ c.product_name }}</span>
                        </td>
                        <td>
                            <span style="font: 400 12.5px/1.3 'Inter', sans-serif; color: var(--text-secondary);">
                                {{ triggerLabel(c.trigger_type) }}
                            </span>
                        </td>
                        <td class="num">{{ gbp(c.gross_amount) }}</td>
                        <td class="num" style="font-weight: 600;">{{ gbp(c.commission_amount) }}</td>
                        <td>
                            <span class="badge badge-sm" :class="commissionBadge(c.status).cls">
                                {{ commissionBadge(c.status).label }}
                            </span>
                        </td>
                        <td>
                            <span style="font: 400 12px/1.3 'Inter', sans-serif; color: var(--text-secondary);">
                                {{ formatPeriod(c) }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div v-if="commissions.last_page > 1" class="portal-pagination">
            <Link
                v-for="link in commissions.links"
                :key="link.label"
                :href="link.url || ''"
                class="portal-page-link"
                :class="{ active: link.active, disabled: !link.url }"
                v-html="link.label"
            />
        </div>
    </ReferrerLayout>
</template>
