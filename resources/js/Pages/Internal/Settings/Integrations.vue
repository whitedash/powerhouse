<script setup>
import { Head, router } from '@inertiajs/vue3';
import { IconExternalLink, IconRefresh } from '@tabler/icons-vue';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';

defineProps({
    integrations: { type: Array, default: () => [] },
    webhook_deliveries: { type: Array, default: () => [] },
});

function testIntegration(key) {
    router.get(`/settings/integrations/${key}/test`, {}, { preserveScroll: true });
}

const STATUS_BADGE = {
    delivered: 'badge-active',
    failed: 'badge-pending',
    abandoned: 'badge-overdue',
    pending: 'badge-inactive',
};
function statusBadge(s) {
    return STATUS_BADGE[s] ?? 'badge-inactive';
}

function retryDelivery(id) {
    router.post(`/webhooks/deliveries/${id}/retry`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Integrations" />

    <SettingsLayout title="Integrations" active-section="integrations">
        <h1 class="set-title">Integrations</h1>

        <div class="sec-label">Connected services</div>
        <div style="display: flex; flex-direction: column; gap: 10px;">
            <div
                v-for="ix in integrations"
                :key="ix.key"
                class="integration-card"
                style="display: flex; align-items: center; gap: 14px; padding: 14px 16px; background: #fff; border: 1px solid var(--border); border-radius: var(--radius-md);"
            >
                <div
                    class="integration-icon"
                    :style="{ background: ix.colour, color: '#fff' }"
                    style="width: 40px; height: 40px; border-radius: var(--radius-md); display: grid; place-items: center; font: 600 13px/1 'Inter', sans-serif; letter-spacing: .02em;"
                >
                    {{ ix.initials }}
                </div>
                <div style="flex: 1;">
                    <div style="font: 600 14px/1.3 'Inter', sans-serif;">{{ ix.name }}</div>
                    <div style="font: 400 12.5px/1.4 'Inter', sans-serif; color: var(--text-secondary); margin-top: 2px;">{{ ix.description }}</div>
                </div>
                <span class="badge" :class="ix.connected ? 'badge-active' : 'badge-inactive'">
                    {{ ix.connected ? 'Connected' : 'Not connected' }}
                </span>
                <button
                    v-if="ix.testable && ix.connected"
                    type="button"
                    class="btn btn-secondary"
                    @click="testIntegration(ix.key)"
                >
                    Test connection
                </button>
                <button
                    v-else
                    type="button"
                    class="btn btn-ghost"
                    disabled
                    style="opacity: .55; cursor: not-allowed;"
                >
                    Configure
                    <IconExternalLink :size="13" stroke-width="1.75" />
                </button>
            </div>
        </div>

        <!-- ─── Webhook deliveries ─── -->
        <div class="sec-label">Webhook deliveries</div>
        <div class="webhook-log card">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Event</th>
                        <th style="width: 120px;">Product</th>
                        <th style="width: 100px;">Status</th>
                        <th style="width: 70px;" class="num">HTTP</th>
                        <th style="width: 90px;" class="num">Attempts</th>
                        <th style="width: 130px;">Time</th>
                        <th style="width: 50px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="d in webhook_deliveries" :key="d.id">
                        <td><code class="wh-event">{{ d.event_type }}</code></td>
                        <td><span class="badge badge-neutral badge-sm">{{ d.product_slug }}</span></td>
                        <td><span class="badge badge-sm" :class="statusBadge(d.status)">{{ d.status }}</span></td>
                        <td class="num">{{ d.http_status ?? '—' }}</td>
                        <td class="num">{{ d.attempts }}/{{ d.max_attempts }}</td>
                        <td class="muted small">{{ d.created_at }}</td>
                        <td>
                            <button
                                v-if="d.can_retry"
                                type="button"
                                class="icon-btn xs"
                                title="Retry delivery"
                                @click="retryDelivery(d.id)"
                            >
                                <IconRefresh :size="14" stroke-width="2" />
                            </button>
                        </td>
                    </tr>
                    <tr v-if="webhook_deliveries.length === 0">
                        <td colspan="7" class="muted center">No webhook deliveries yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="sec-label">About integrations</div>
        <p style="color: var(--text-secondary); font: 400 13px/1.6 'Inter', sans-serif;">
            Credentials live in <code>.env</code> (<code>CLOUDFLARE_API_TOKEN</code>, <code>POSTMARK_TOKEN</code>,
            <code>STRIPE_SECRET</code>, <code>QBO_CLIENT_ID</code>). Outbound product webhooks
            (suspension, reinstatement) sign with <code>MAAVELUS_WEBHOOK_SECRET</code> /
            <code>MYORDERPAD_WEBHOOK_SECRET</code>.
        </p>
    </SettingsLayout>
</template>
