<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { IconDeviceFloppy } from '@tabler/icons-vue';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';

const props = defineProps({
    values: { type: Object, required: true },
});

const form = useForm({
    auto_suspend_days: Number(props.values['billing.auto_suspend_days'] ?? 15),
    suspension_grace_hours: Number(props.values['billing.suspension_grace_hours'] ?? 24),
    auto_reinstate: !!props.values['billing.auto_reinstate'],
});

function submit() {
    form.post('/settings/billing', { preserveScroll: true });
}
</script>

<template>
    <Head title="Billing automation" />

    <SettingsLayout title="Billing automation" active-section="billing">
        <h1 class="set-title">Billing automation</h1>

        <form @submit.prevent="submit">
            <!-- Auto-suspension -->
            <div class="sec-label">Auto-suspension</div>
            <div class="status-rows" style="background: var(--neutral-bg); border-radius: var(--radius-md); padding: 12px 16px;">
                <div class="set-row" style="display: flex; align-items: center; gap: 14px; border-bottom: 1px solid var(--border-soft); padding: 10px 0;">
                    <div style="flex: 1;">
                        <div class="nm">Auto-suspend after</div>
                        <div class="sb">
                            Days an invoice must be overdue before its products are
                            suspended. Set to <strong>0</strong> to disable auto-suspension.
                        </div>
                    </div>
                    <input
                        v-model.number="form.auto_suspend_days"
                        type="number"
                        min="0"
                        max="365"
                        class="field-input"
                        style="width: 80px;"
                    >
                    <span style="color: var(--text-secondary); font-size: 13px;">days overdue</span>
                </div>
                <div class="set-row" style="display: flex; align-items: center; gap: 14px; padding: 10px 0 0;">
                    <div style="flex: 1;">
                        <div class="nm">Grace period after final notice</div>
                        <div class="sb">
                            Hours that must elapse after a final-notice reminder before
                            suspension fires.
                        </div>
                    </div>
                    <input
                        v-model.number="form.suspension_grace_hours"
                        type="number"
                        min="0"
                        max="720"
                        class="field-input"
                        style="width: 80px;"
                    >
                    <span style="color: var(--text-secondary); font-size: 13px;">hours</span>
                </div>
            </div>

            <!-- Reinstatement -->
            <div class="sec-label">Reinstatement</div>
            <div class="status-rows" style="background: var(--neutral-bg); border-radius: var(--radius-md); padding: 12px 16px;">
                <div class="set-row" style="display: flex; align-items: center; gap: 14px; padding: 10px 0;">
                    <div style="flex: 1;">
                        <div class="nm">Auto-reinstate on payment</div>
                        <div class="sb">
                            Reinstate suspended products automatically when the outstanding
                            balance is paid. Consumed by the Stripe sprint.
                        </div>
                    </div>
                    <button
                        type="button"
                        class="toggle"
                        :class="{ on: form.auto_reinstate }"
                        aria-label="Toggle auto-reinstate"
                        @click="form.auto_reinstate = !form.auto_reinstate"
                    />
                </div>
            </div>

            <p class="muted" style="font-size: 12.5px; margin-top: 12px;">
                The sweep runs daily at 10:00 (Europe/London) via
                <code>invoices:process-suspensions</code>. A customer is only suspended
                once a final-notice reminder has been sent and the grace period elapsed.
            </p>

            <div class="set-save-row">
                <button type="submit" class="btn btn-primary" :disabled="form.processing">
                    <IconDeviceFloppy :size="15" stroke-width="1.75" />
                    {{ form.processing ? 'Saving…' : 'Save billing settings' }}
                </button>
            </div>
        </form>
    </SettingsLayout>
</template>
