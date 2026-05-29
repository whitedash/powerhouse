<script setup>
import { Head, router } from '@inertiajs/vue3';
import { IconCheck, IconAlertCircle, IconExternalLink } from '@tabler/icons-vue';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';

defineProps({
    integrations: { type: Array, default: () => [] },
});

function testIntegration(key) {
    router.get(`/settings/integrations/${key}/test`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Integrations" />

    <SettingsLayout title="Integrations" active-section="integrations">
        <h1 class="set-title">Integrations</h1>

        <div
            v-if="$page.props.flash?.success"
            style="margin-bottom: 14px; padding: 10px 14px; background: var(--success-bg); color: #047857; border: 1px solid #A7F3D0; border-radius: var(--radius-md); font: 500 13px/1.4 'Inter', sans-serif; display: flex; align-items: center; gap: 8px;"
        >
            <IconCheck :size="16" stroke-width="2" />{{ $page.props.flash.success }}
        </div>
        <div
            v-if="$page.props.flash?.error"
            style="margin-bottom: 14px; padding: 10px 14px; background: var(--danger-bg); color: var(--danger); border: 1px solid #FECACA; border-radius: var(--radius-md); font: 500 13px/1.4 'Inter', sans-serif; display: flex; align-items: center; gap: 8px;"
        >
            <IconAlertCircle :size="16" stroke-width="2" />{{ $page.props.flash.error }}
        </div>

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

        <div class="sec-label">About integrations</div>
        <p style="color: var(--text-secondary); font: 400 13px/1.6 'Inter', sans-serif;">
            Credentials live in <code>.env</code> (<code>CLOUDFLARE_API_TOKEN</code>, <code>POSTMARK_TOKEN</code>,
            <code>STRIPE_SECRET</code>, <code>QBO_CLIENT_ID</code>). Configure flows for Postmark / Stripe / QBO ship
            in the integrations sprint.
        </p>
    </SettingsLayout>
</template>
