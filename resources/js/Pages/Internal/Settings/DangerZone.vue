<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { IconAlertTriangle, IconCheck, IconAlertCircle } from '@tabler/icons-vue';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

defineProps({
    env_is_production: { type: Boolean, default: false },
});

const showResetModal = ref(false);
const resetProcessing = ref(false);

function askReset() {
    showResetModal.value = true;
}

function handleReset() {
    resetProcessing.value = true;
    router.post('/settings/danger/reset-notifications', {}, {
        preserveScroll: true,
        onFinish: () => {
            resetProcessing.value = false;
            showResetModal.value = false;
        },
    });
}
</script>

<template>
    <Head title="Danger zone" />

    <SettingsLayout title="Danger zone" active-section="danger">
        <h1 class="set-title" style="color: var(--danger);">Danger zone</h1>

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

        <div
            v-if="env_is_production"
            style="margin-bottom: 14px; padding: 12px 14px; background: var(--warning-bg); color: #92400E; border: 1px solid #FDE68A; border-radius: var(--radius-md); display: flex; align-items: flex-start; gap: 10px;"
        >
            <IconAlertTriangle :size="18" stroke-width="2" style="flex-shrink: 0; margin-top: 1px;" />
            <div style="font: 500 13px/1.5 'Inter', sans-serif;">
                You're on a production environment. Destructive actions are irreversible — double-check before confirming.
            </div>
        </div>

        <!-- Clear test data -->
        <div class="sec-label">Clear test data</div>
        <div style="background: #fff; border: 1px solid var(--border); border-radius: var(--radius-md); padding: 16px 18px; display: flex; align-items: center; gap: 14px;">
            <div style="flex: 1;">
                <div style="font: 600 14px/1.3 'Inter', sans-serif;">Wipe non-production customers, invoices, tasks</div>
                <div style="font: 400 12.5px/1.4 'Inter', sans-serif; color: var(--text-secondary); margin-top: 2px;">
                    Removes rows flagged <code>is_test</code> across the data set. Disabled until the seed/cleanup sprint runs.
                </div>
            </div>
            <button type="button" class="btn btn-secondary" disabled style="opacity: .55; cursor: not-allowed;">
                Clear test data
            </button>
        </div>

        <!-- Reset notification settings -->
        <div class="sec-label">Reset notification settings</div>
        <div style="background: #fff; border: 1px solid var(--border); border-radius: var(--radius-md); padding: 16px 18px; display: flex; align-items: center; gap: 14px;">
            <div style="flex: 1;">
                <div style="font: 600 14px/1.3 'Inter', sans-serif;">Restore notification defaults</div>
                <div style="font: 400 12.5px/1.4 'Inter', sans-serif; color: var(--text-secondary); margin-top: 2px;">
                    Deletes every <code>notifications.*</code> row from <code>settings</code> — they fall back to the built-in defaults.
                </div>
            </div>
            <button type="button" class="btn btn-danger" @click="askReset">
                Reset to defaults
            </button>
        </div>

        <!-- Export all data -->
        <div class="sec-label">Export all data</div>
        <div style="background: #fff; border: 1px solid var(--border); border-radius: var(--radius-md); padding: 16px 18px; display: flex; align-items: center; gap: 14px;">
            <div style="flex: 1;">
                <div style="font: 600 14px/1.3 'Inter', sans-serif;">GDPR data export</div>
                <div style="font: 400 12.5px/1.4 'Inter', sans-serif; color: var(--text-secondary); margin-top: 2px;">
                    Generates a ZIP of every customer's stored records. Disabled until the compliance sprint wires the export queue.
                </div>
            </div>
            <button type="button" class="btn btn-secondary" disabled style="opacity: .55; cursor: not-allowed;">
                Request export
            </button>
        </div>

        <ConfirmModal
            v-model:show="showResetModal"
            title="Reset notification settings?"
            message="Every notifications.* row will be deleted. The Notifications page will fall back to its built-in defaults until you save again."
            confirm-label="Reset to defaults"
            variant="danger"
            :loading="resetProcessing"
            @confirm="handleReset"
        />
    </SettingsLayout>
</template>
