<script setup>
import { Head } from '@inertiajs/vue3';
import { IconUsers } from '@tabler/icons-vue';
import ReferrerLayout from '@/Layouts/ReferrerLayout.vue';

defineProps({
    referrals: { type: Array, default: () => [] },
    total_customers: { type: Number, default: 0 },
});

function gbp(n) {
    return `£${Number(n).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function customerInitials(name) {
    const parts = (name || '').trim().split(/\s+/);
    return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || '?';
}

function productStatusLabel(status) {
    if (status === 'trial') return 'Trial';
    if (status === 'suspended') return 'Suspended';
    return 'Active';
}
</script>

<template>
    <Head title="My customers · Whitedash Partners" />
    <ReferrerLayout title="My customers" active-nav="my-customers">
        <div class="portal-section-head">
            <div class="col-l">
                <h2>{{ total_customers }} referred customer{{ total_customers === 1 ? '' : 's' }}</h2>
                <div class="desc">Customers you've introduced to Whitedash.</div>
            </div>
        </div>

        <div v-if="total_customers === 0" class="portal-empty">
            <IconUsers :size="32" stroke-width="1.5" />
            <div class="portal-empty-title">No customers yet</div>
            <div class="portal-empty-sub">
                Share your referral link to start earning commission on every customer who signs up.
            </div>
        </div>

        <div v-else class="referrer-customer-list">
            <article v-for="r in referrals" :key="r.customer_id" class="referrer-customer-card">
                <div class="referrer-customer-left">
                    <div class="portal-avatar av-teal" style="width: 40px; height: 40px;">
                        {{ customerInitials(r.name) }}
                    </div>
                </div>

                <div class="referrer-customer-main">
                    <div class="referrer-customer-name">{{ r.name }}</div>
                    <div class="referrer-customer-meta">
                        <template v-if="r.city">{{ r.city }} · </template>
                        Attributed {{ r.attributed_at }}
                    </div>

                    <div v-if="r.products.length > 0" class="referrer-customer-products">
                        <span
                            v-for="(p, i) in r.products"
                            :key="i"
                            class="referrer-prod-chip"
                            :style="{ background: (p.colour || 'var(--accent)') + '22', color: p.colour || 'var(--text-primary)' }"
                            :title="`${p.name} · ${productStatusLabel(p.status)}`"
                        >
                            {{ p.name }}
                            <span style="opacity: .7;">· {{ productStatusLabel(p.status) }}</span>
                        </span>
                    </div>
                    <div v-else class="referrer-customer-products-empty">
                        No active subscriptions yet
                    </div>
                </div>

                <div class="referrer-customer-right">
                    <div class="referrer-customer-amt-label">Commission earned</div>
                    <div class="referrer-customer-amt">{{ gbp(r.total_commission) }}</div>
                </div>
            </article>
        </div>
    </ReferrerLayout>
</template>
