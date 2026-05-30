<script setup>
import { Head } from '@inertiajs/vue3';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';

defineProps({
    workspace: { type: Object, required: true },
});
</script>

<template>
    <Head title="General settings" />

    <SettingsLayout title="Settings" active-section="general">
        <h1 class="set-title">General</h1>

        <div class="sec-label">Workspace</div>
        <div class="form-grid-2">
            <div class="field">
                <label class="field-label">Name</label>
                <input class="field-input" :value="workspace.name" readonly>
                <div class="field-help">Configured via APP_NAME in .env.</div>
            </div>
            <div class="field">
                <label class="field-label">URL</label>
                <input class="field-input mono" :value="workspace.url" readonly>
            </div>
            <div class="field">
                <label class="field-label">Timezone</label>
                <input class="field-input" :value="workspace.timezone" readonly>
                <div class="field-help">Set via APP_TIMEZONE.</div>
            </div>
            <div class="field">
                <label class="field-label">Locale</label>
                <input class="field-input" :value="workspace.locale" readonly>
            </div>
        </div>

        <div class="sec-label">About this page</div>
        <p style="color: var(--text-secondary); font: 400 13px/1.6 'Inter', sans-serif;">
            Workspace-level settings live in <code>.env</code> and <code>config/app.php</code>.
            Change them there and run <code>php artisan config:cache</code>. The deeper sections in the
            left nav (Team, Security, Notifications, Integrations, Audit log, Products, Danger zone)
            manage runtime state and are editable here.
        </p>
    </SettingsLayout>
</template>
