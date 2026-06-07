<script setup>
/**
 * Customer-facing READ-ONLY asset overview (Stage 4): Websites (+ hosting),
 * Domains and Services in one place. Mirrors the internal Assets grouping but
 * shows only customer-safe fields. The only action is the SaaS SSO launch on a
 * service (unchanged behaviour).
 */
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';

const props = defineProps({
    websites: { type: Array, default: () => [] },
    domains: { type: Array, default: () => [] },
    services: { type: Array, default: () => [] },
});

const counts = computed(() => ({ invoices: undefined, support: undefined }));

function gbp(n) {
    return Number(n ?? 0).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

const hostingLabel = { active: 'Active', suspended: 'Suspended', none: 'No hosting' };
const sslLabel = { valid: 'Valid', expired: 'Expired', unknown: 'Unknown' };

/* ─── SaaS SSO launch (same behaviour as the dashboard) ─── */
const launchingId = ref(null);
function launchService(s) {
    if (! s.sso_enabled) {
        if (s.sso_url) window.location.href = s.sso_url;
        return;
    }
    launchingId.value = s.id;
    router.post(`/portal/launch/${s.product_slug}`, {}, {
        onFinish: () => { launchingId.value = null; },
    });
}
</script>

<template>
    <Head title="My Assets" />

    <PortalLayout active-nav="assets" :counts="counts">
        <div class="wrap">
            <header class="page-head">
                <h1>My assets</h1>
                <p>Your websites, domains and services with us.</p>
            </header>

            <!-- ═══ Websites ═══ -->
            <section class="asset-sec">
                <div class="asset-sec-head">
                    <h2><i class="ti ti-world" /> Websites</h2>
                    <span class="muted">{{ websites.length }}</span>
                </div>
                <div v-if="websites.length === 0" class="card card-pad muted">No websites yet.</div>
                <div v-else class="asset-grid">
                    <div v-for="w in websites" :key="w.id" class="card card-pad asset-card">
                        <div class="asset-card-head">
                            <div class="nm">{{ w.name }}</div>
                            <span
                                v-if="w.hosting_status && w.hosting_status !== 'none'"
                                class="badge"
                                :class="w.hosting_status === 'active' ? 'badge-active' : 'badge-amber'"
                            >{{ hostingLabel[w.hosting_status] }}</span>
                        </div>
                        <a :href="w.url" target="_blank" rel="noopener" class="asset-url">{{ w.url }}</a>
                        <dl class="asset-meta">
                            <div v-if="w.hosting_plan"><dt>Hosting</dt><dd>{{ w.hosting_plan }}<template v-if="w.hosting_tier"> · {{ w.hosting_tier }}</template></dd></div>
                            <div><dt>SSL</dt><dd :class="{ bad: w.ssl === 'expired' }">{{ sslLabel[w.ssl] }}</dd></div>
                            <div v-if="w.pagespeed_mobile !== null || w.pagespeed_desktop !== null">
                                <dt>PageSpeed</dt>
                                <dd>{{ w.pagespeed_mobile ?? '—' }} mobile · {{ w.pagespeed_desktop ?? '—' }} desktop</dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </section>

            <!-- ═══ Domains ═══ -->
            <section class="asset-sec">
                <div class="asset-sec-head">
                    <h2><i class="ti ti-at" /> Domains</h2>
                    <span class="muted">{{ domains.length }}</span>
                </div>
                <div v-if="domains.length === 0" class="card card-pad muted">No domains yet.</div>
                <div v-else class="asset-grid">
                    <div v-for="d in domains" :key="d.id" class="card card-pad asset-card">
                        <div class="asset-card-head">
                            <div class="nm">{{ d.domain }}</div>
                            <span class="badge" :class="d.auto_renew ? 'badge-active' : 'badge-amber'">
                                {{ d.auto_renew ? 'Auto-renews' : 'Manual renewal' }}
                            </span>
                        </div>
                        <dl class="asset-meta">
                            <div v-if="d.expiry_date"><dt>Expires</dt><dd>{{ d.expiry_date }}</dd></div>
                            <div><dt>SSL</dt><dd :class="{ bad: d.ssl === 'expired' }">{{ sslLabel[d.ssl] }}</dd></div>
                            <div v-if="d.renewal"><dt>Renewal</dt><dd>£{{ gbp(d.renewal.price) }} · {{ d.renewal.interval }}</dd></div>
                        </dl>
                    </div>
                </div>
            </section>

            <!-- ═══ Services ═══ -->
            <section class="asset-sec">
                <div class="asset-sec-head">
                    <h2><i class="ti ti-stack-2" /> Services</h2>
                    <span class="muted">{{ services.length }}</span>
                </div>
                <div v-if="services.length === 0" class="card card-pad muted">
                    No active services. <Link href="/portal/subscriptions">Browse services →</Link>
                </div>
                <div v-else class="asset-grid">
                    <div v-for="s in services" :key="s.id" class="card card-pad asset-card">
                        <div class="asset-card-head">
                            <div class="nm">
                                <span class="svc-dot" :style="{ background: s.icon_colour || '#64748b' }" />
                                {{ s.product_name }}
                            </div>
                            <span class="badge" :class="s.status === 'active' ? 'badge-active' : (s.status === 'trial' ? 'badge-blue' : 'badge-amber')">
                                {{ s.status }}
                            </span>
                        </div>
                        <dl class="asset-meta">
                            <div><dt>Plan</dt><dd>{{ s.plan_name }}</dd></div>
                            <div><dt>Price</dt><dd>£{{ gbp(s.price) }} · {{ s.interval_label }}</dd></div>
                            <div v-if="s.next_billing_date"><dt>Next billing</dt><dd>{{ s.next_billing_date }}</dd></div>
                        </dl>
                        <div v-if="s.status === 'active' && (s.sso_enabled || s.sso_url)" class="asset-card-foot">
                            <button class="btn btn-secondary btn-sm" :disabled="launchingId === s.id" @click="launchService(s)">
                                {{ launchingId === s.id ? 'Opening…' : 'Open' }}<i class="ti ti-external-link" />
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </PortalLayout>
</template>

<style scoped>
.wrap { max-width: 1100px; margin: 0 auto; padding: 24px 16px 64px; }
.page-head h1 { font: 700 22px/1.2 'Inter', sans-serif; margin: 0 0 4px; }
.page-head p { color: var(--text-secondary, #64748b); margin: 0 0 8px; font-size: 14px; }
.asset-sec { margin-top: 28px; }
.asset-sec-head { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
.asset-sec-head h2 { font: 600 15px/1.2 'Inter', sans-serif; margin: 0; display: flex; align-items: center; gap: 6px; }
.asset-sec-head .muted { color: var(--text-tertiary, #94a3b8); font-size: 13px; }
.asset-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }
.asset-card { display: flex; flex-direction: column; gap: 8px; }
.asset-card-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.asset-card-head .nm { font: 600 14px/1.3 'Inter', sans-serif; display: flex; align-items: center; gap: 7px; }
.svc-dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
.asset-url { font-size: 12.5px; color: var(--accent, #6366f1); text-decoration: none; word-break: break-all; }
.asset-url:hover { text-decoration: underline; }
.asset-meta { margin: 2px 0 0; display: flex; flex-direction: column; gap: 4px; }
.asset-meta > div { display: flex; justify-content: space-between; gap: 12px; font-size: 13px; }
.asset-meta dt { color: var(--text-tertiary, #94a3b8); margin: 0; }
.asset-meta dd { margin: 0; color: var(--text-primary, #0f172a); text-align: right; }
.asset-meta dd.bad { color: var(--danger, #ef4444); }
.asset-card-foot { margin-top: 4px; }
.card.muted { color: var(--text-secondary, #64748b); font-size: 14px; }
.badge-amber { background: #fef3c7; color: #92400e; }
.badge-blue { background: #dbeafe; color: #1e40af; }
</style>
