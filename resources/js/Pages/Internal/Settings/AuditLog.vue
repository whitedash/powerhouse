<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';

const props = defineProps({
    entries: { type: Array, default: () => [] },
});

const rows = computed(() => props.entries);

function formatDate(iso) {
    if (! iso) return '—';

    return new Date(iso).toLocaleString('en-GB', {
        day: 'numeric', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}

function actionLabel(action) {
    return String(action || '').replace(/[._]/g, ' ');
}

function roleLabel(role) {
    if (! role) return 'system';
    const map = { super_admin: 'Super Admin', staff: 'Staff', customer: 'Customer', referrer: 'Referrer' };

    return map[role] || role;
}
</script>

<template>
    <Head title="Audit log" />

    <SettingsLayout title="Audit log" active-section="audit-log">
        <h1 class="set-title">Audit log</h1>
        <p style="color: var(--text-secondary); font: 400 13px/1.6 'Inter', sans-serif; margin-bottom: 14px;">
            Latest 200 events from <code>activity_log</code>. Use this when reviewing who changed what and when.
        </p>

        <div style="border: 1px solid var(--border); border-radius: var(--radius-md); overflow: hidden; background: #fff;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #FBFCFE; border-bottom: 1px solid var(--border-soft);">
                        <th style="text-align: left; padding: 10px 14px; font: 500 11px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary);">When</th>
                        <th style="text-align: left; padding: 10px 14px; font: 500 11px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary);">Role</th>
                        <th style="text-align: left; padding: 10px 14px; font: 500 11px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary);">Action</th>
                        <th style="text-align: left; padding: 10px 14px; font: 500 11px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary);">Entity</th>
                        <th style="text-align: left; padding: 10px 14px; font: 500 11px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary);">IP</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="! rows.length">
                        <td colspan="5" style="padding: 28px 14px; text-align: center; color: var(--text-secondary); font: 400 13px/1.4 'Inter', sans-serif;">
                            No activity yet.
                        </td>
                    </tr>
                    <tr v-for="row in rows" :key="row.id" style="border-bottom: 1px solid var(--border-soft);">
                        <td style="padding: 11px 14px; color: var(--text-secondary); font-size: 12.5px; white-space: nowrap;">
                            {{ formatDate(row.created_at) }}
                        </td>
                        <td style="padding: 11px 14px; font-size: 12.5px; color: var(--text-secondary); text-transform: capitalize;">
                            {{ roleLabel(row.user_role) }}
                        </td>
                        <td style="padding: 11px 14px; font: 500 13px/1.3 'Inter', sans-serif; text-transform: capitalize;">
                            {{ actionLabel(row.action) }}
                        </td>
                        <td style="padding: 11px 14px; font-size: 12.5px; color: var(--text-secondary);">
                            <span v-if="row.entity_type">
                                {{ row.entity_type }}<span v-if="row.entity_id">#{{ row.entity_id }}</span>
                            </span>
                            <span v-else>—</span>
                        </td>
                        <td style="padding: 11px 14px; font: 400 12px/1.4 'JetBrains Mono', monospace; color: var(--text-secondary);">
                            {{ row.ip_address || '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </SettingsLayout>
</template>
