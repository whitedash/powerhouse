<script setup>
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { IconChevronLeft, IconChevronRight, IconShare } from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';

const props = defineProps({
    referrals: { type: Object, default: () => ({ data: [] }) },
    filters: { type: Object, default: () => ({}) },
    referrerOptions: { type: Array, default: () => [] },
    sourceOptions: { type: Array, default: () => [] },
    productOptions: { type: Array, default: () => [] },
});

const breadcrumbs = [{ label: 'Referrals' }];

const referrerId = ref(props.filters?.referrer_id ?? '');
const source = ref(props.filters?.source ?? '');
const product = ref(props.filters?.product ?? '');

function navigate() {
    const q = {};
    if (referrerId.value) q.referrer_id = referrerId.value;
    if (source.value) q.source = source.value;
    if (product.value) q.product = product.value;
    router.get('/referrals', q, { preserveState: true, preserveScroll: true, replace: true });
}
watch([referrerId, source, product], navigate);

function go(url) { if (url) router.visit(url, { preserveScroll: true }); }

function fmtDate(iso) {
    if (! iso) return '—';
    return new Date(iso).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}
const SOURCE_CLASS = {
    cookie: 'badge-active', param: 'badge-pending', manual: 'badge-inactive', api: 'badge-gold',
};
</script>

<template>
    <Head title="Referrals" />

    <InternalLayout title="Referrals" :breadcrumbs="breadcrumbs" active-nav="referrals">
        <div class="ref-ledger">
            <div class="filter-bar">
                <select v-model="referrerId">
                    <option value="">All referrers</option>
                    <option v-for="o in referrerOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
                <select v-model="source">
                    <option value="">All sources</option>
                    <option v-for="o in sourceOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
                <select v-model="product">
                    <option value="">All products</option>
                    <option v-for="o in productOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
            </div>

            <section class="table-card">
                <table v-if="referrals.data.length" class="tbl">
                    <colgroup>
                        <col><col><col style="width: 130px;"><col style="width: 130px;"><col style="width: 150px;"><col style="width: 140px;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Company</th>
                            <th>Referrer</th>
                            <th>Source</th>
                            <th>Product</th>
                            <th>Campaign</th>
                            <th>Attributed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in referrals.data" :key="r.id">
                            <td><Link :href="`/companies/${r.customer_id}`" class="rl-link">{{ r.customer_name }}</Link></td>
                            <td><Link :href="`/referrers/${r.referrer_id}`" class="rl-link">{{ r.referrer_name }}</Link></td>
                            <td><span class="badge badge-sm" :class="SOURCE_CLASS[r.source] || 'badge-inactive'">{{ r.source_label }}</span></td>
                            <td><span :class="{ muted: ! r.product }">{{ r.product ?? '—' }}</span></td>
                            <td><span :class="{ muted: ! r.campaign }">{{ r.campaign ?? '—' }}</span></td>
                            <td><span class="muted small">{{ fmtDate(r.attributed_at) }}</span></td>
                        </tr>
                    </tbody>
                </table>

                <div v-else class="rl-empty">
                    <IconShare :size="40" stroke-width="1.5" />
                    <h3>No attributions yet</h3>
                    <p>Referral attributions appear here once a referred lead converts or a company is linked to a referrer.</p>
                </div>

                <div v-if="referrals.data.length" class="tbl-foot">
                    <div class="info">Showing <strong>{{ referrals.from ?? 0 }} – {{ referrals.to ?? 0 }}</strong> of <strong>{{ referrals.total }}</strong></div>
                    <div class="right">
                        <button type="button" class="pg-btn" :disabled="! referrals.prev_page_url" @click="go(referrals.prev_page_url)">
                            <IconChevronLeft :size="14" stroke-width="1.75" /> Previous
                        </button>
                        <button type="button" class="pg-btn" :disabled="! referrals.next_page_url" @click="go(referrals.next_page_url)">
                            Next <IconChevronRight :size="14" stroke-width="1.75" />
                        </button>
                    </div>
                </div>
            </section>
        </div>
    </InternalLayout>
</template>

<style scoped>
.ref-ledger { display: flex; flex-direction: column; gap: 16px; }
.filter-bar { display: flex; gap: 10px; flex-wrap: wrap; }
.filter-bar select { min-width: 160px; }
.rl-link { font-weight: 600; color: var(--text); }
.rl-link:hover { color: var(--accent); }
.rl-empty {
    display: flex; flex-direction: column; align-items: center; gap: 10px;
    padding: 56px 24px; text-align: center; color: var(--text-muted);
}
.rl-empty h3 { margin: 4px 0 0; color: var(--text); }
.rl-empty p { max-width: 460px; margin: 0; }
</style>
