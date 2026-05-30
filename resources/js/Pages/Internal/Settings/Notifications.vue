<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { IconDeviceFloppy } from '@tabler/icons-vue';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';

const props = defineProps({
    values: { type: Object, required: true },
});

const form = useForm({
    notifications: {
        invoice_overdue_alert: !!props.values['notifications.invoice_overdue_alert'],
        invoice_overdue_days: Number(props.values['notifications.invoice_overdue_days'] ?? 1),
        domain_expiry_alert: !!props.values['notifications.domain_expiry_alert'],
        domain_expiry_days: Number(props.values['notifications.domain_expiry_days'] ?? 30),
        domain_critical_days: Number(props.values['notifications.domain_critical_days'] ?? 7),
        reminders_enabled: !!props.values['notifications.reminders_enabled'],
        reminders_time: String(props.values['notifications.reminders_time'] ?? '09:00'),
        email_on_overdue: !!props.values['notifications.email_on_overdue'],
        email_on_sla_breach: !!props.values['notifications.email_on_sla_breach'],
    },
    // support.* lives in its own nest so the controller can validate
    // it as a separate group without polluting the notifications
    // namespace persisted to the settings table.
    support: {
        auto_close_days: Number(props.values['support.auto_close_days'] ?? 7),
    },
});

function submit() {
    form.post('/settings/notifications', { preserveScroll: true });
}
</script>

<template>
    <Head title="Notifications" />

    <SettingsLayout title="Notifications" active-section="notifications">
        <h1 class="set-title">Notifications</h1>

        <form @submit.prevent="submit">
            <!-- Invoice alerts -->
            <div class="sec-label">Invoice alerts</div>
            <div class="status-rows" style="background: var(--neutral-bg); border-radius: var(--radius-md); padding: 12px 16px;">
                <div class="set-row" style="display: flex; align-items: center; gap: 14px; border-bottom: 1px solid var(--border-soft); padding: 10px 0;">
                    <div style="flex: 1;">
                        <div class="nm">Overdue invoice alert</div>
                        <div class="sb">Surface overdue invoices in the nav badge + Needs Attention card.</div>
                    </div>
                    <button
                        type="button"
                        class="toggle"
                        :class="{ on: form.notifications.invoice_overdue_alert }"
                        aria-label="Toggle invoice overdue"
                        @click="form.notifications.invoice_overdue_alert = !form.notifications.invoice_overdue_alert"
                    />
                </div>
                <div class="set-row" style="display: flex; align-items: center; gap: 14px; padding: 10px 0 0;">
                    <div style="flex: 1;">
                        <div class="nm">Alert after</div>
                        <div class="sb">Days past due_date before an invoice flips to overdue status.</div>
                    </div>
                    <input
                        v-model.number="form.notifications.invoice_overdue_days"
                        type="number"
                        min="0"
                        max="90"
                        class="field-input"
                        style="width: 80px;"
                    >
                    <span style="color: var(--text-secondary); font-size: 13px;">days</span>
                </div>
            </div>

            <!-- Domain alerts -->
            <div class="sec-label">Domain alerts</div>
            <div class="status-rows" style="background: var(--neutral-bg); border-radius: var(--radius-md); padding: 12px 16px;">
                <div class="set-row" style="display: flex; align-items: center; gap: 14px; border-bottom: 1px solid var(--border-soft); padding: 10px 0;">
                    <div style="flex: 1;">
                        <div class="nm">Domain expiry alert</div>
                        <div class="sb">Show domain/SSL expiry warnings on the dashboard.</div>
                    </div>
                    <button
                        type="button"
                        class="toggle"
                        :class="{ on: form.notifications.domain_expiry_alert }"
                        @click="form.notifications.domain_expiry_alert = !form.notifications.domain_expiry_alert"
                    />
                </div>
                <div class="set-row" style="display: flex; align-items: center; gap: 14px; border-bottom: 1px solid var(--border-soft); padding: 10px 0;">
                    <div style="flex: 1;">
                        <div class="nm">Alert</div>
                        <div class="sb">Standard warning lead time before expiry.</div>
                    </div>
                    <input
                        v-model.number="form.notifications.domain_expiry_days"
                        type="number"
                        min="1"
                        max="180"
                        class="field-input"
                        style="width: 80px;"
                    >
                    <span style="color: var(--text-secondary); font-size: 13px;">days before</span>
                </div>
                <div class="set-row" style="display: flex; align-items: center; gap: 14px; padding: 10px 0 0;">
                    <div style="flex: 1;">
                        <div class="nm">Critical alert</div>
                        <div class="sb">Escalation to red on the dashboard KPI card.</div>
                    </div>
                    <input
                        v-model.number="form.notifications.domain_critical_days"
                        type="number"
                        min="1"
                        max="90"
                        class="field-input"
                        style="width: 80px;"
                    >
                    <span style="color: var(--text-secondary); font-size: 13px;">days before</span>
                </div>
            </div>

            <!-- Reminder automation -->
            <div class="sec-label">Reminder automation</div>
            <div class="status-rows" style="background: var(--neutral-bg); border-radius: var(--radius-md); padding: 12px 16px;">
                <div class="set-row" style="display: flex; align-items: center; gap: 14px; border-bottom: 1px solid var(--border-soft); padding: 10px 0;">
                    <div style="flex: 1;">
                        <div class="nm">Automated invoice reminders</div>
                        <div class="sb">Daily cron fires the tier matrix in <code>invoices:send-reminders</code>.</div>
                    </div>
                    <button
                        type="button"
                        class="toggle"
                        :class="{ on: form.notifications.reminders_enabled }"
                        @click="form.notifications.reminders_enabled = !form.notifications.reminders_enabled"
                    />
                </div>
                <div class="set-row" style="display: flex; align-items: center; gap: 14px; padding: 10px 0 0;">
                    <div style="flex: 1;">
                        <div class="nm">Send reminders at</div>
                        <div class="sb">Scheduled in <code>routes/console.php</code> (Europe/London).</div>
                    </div>
                    <input
                        v-model="form.notifications.reminders_time"
                        type="time"
                        class="field-input"
                        style="width: 120px;"
                    >
                </div>
            </div>

            <!-- Email -->
            <div class="sec-label">Email notifications</div>
            <div class="status-rows" style="background: var(--neutral-bg); border-radius: var(--radius-md); padding: 12px 16px;">
                <div class="set-row" style="display: flex; align-items: center; gap: 14px; border-bottom: 1px solid var(--border-soft); padding: 10px 0; opacity: .65;">
                    <div style="flex: 1;">
                        <div class="nm">Email me on overdue invoice</div>
                        <div class="sb">Requires Postmark setup — disabled until the email sprint runs.</div>
                    </div>
                    <button
                        type="button"
                        class="toggle"
                        :class="{ on: form.notifications.email_on_overdue }"
                        disabled
                        style="opacity: .55; cursor: not-allowed;"
                        @click.prevent
                    />
                </div>
                <div class="set-row" style="display: flex; align-items: center; gap: 14px; padding: 10px 0 0; opacity: .65;">
                    <div style="flex: 1;">
                        <div class="nm">Email me on SLA breach</div>
                        <div class="sb">Requires Postmark setup.</div>
                    </div>
                    <button
                        type="button"
                        class="toggle"
                        :class="{ on: form.notifications.email_on_sla_breach }"
                        disabled
                        style="opacity: .55; cursor: not-allowed;"
                        @click.prevent
                    />
                </div>
            </div>

            <!-- Support automation -->
            <div class="sec-label">Support automation</div>
            <div class="status-rows" style="background: var(--neutral-bg); border-radius: var(--radius-md); padding: 12px 16px;">
                <div class="set-row" style="display: flex; align-items: center; gap: 14px; padding: 10px 0;">
                    <div style="flex: 1;">
                        <div class="nm">Auto-close inactive tickets</div>
                        <div class="sb">
                            Tickets waiting on the customer for longer than this window are closed automatically.
                            Set to <strong>0</strong> to disable.
                        </div>
                    </div>
                    <input
                        v-model.number="form.support.auto_close_days"
                        type="number"
                        min="0"
                        max="90"
                        class="field-input"
                        style="width: 80px;"
                    >
                    <span style="color: var(--text-secondary); font-size: 13px;">days</span>
                </div>
            </div>

            <div class="set-save-row">
                <button type="submit" class="btn btn-primary" :disabled="form.processing">
                    <IconDeviceFloppy :size="15" stroke-width="1.75" />
                    {{ form.processing ? 'Saving…' : 'Save notification settings' }}
                </button>
            </div>
        </form>
    </SettingsLayout>
</template>
