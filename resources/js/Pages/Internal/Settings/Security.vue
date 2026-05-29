<script setup>
import { Head, useForm, router } from '@inertiajs/vue3';
import {
    IconShieldCheck,
    IconKey,
    IconDeviceDesktop,
    IconCheck,
    IconAlertCircle,
} from '@tabler/icons-vue';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';

const props = defineProps({
    session: { type: Object, required: true },
    two_factor_enabled: { type: Boolean, default: false },
});

function formatDate(iso) {
    if (! iso) return '—';

    return new Date(iso).toLocaleString('en-GB', {
        day: 'numeric', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}

function browserLabel(ua) {
    if (! ua) return 'Unknown browser';
    if (/Edg\//.test(ua)) return 'Edge';
    if (/Chrome\//.test(ua)) return 'Chrome';
    if (/Firefox\//.test(ua)) return 'Firefox';
    if (/Safari\//.test(ua) && ! /Chrome/.test(ua)) return 'Safari';

    return 'Browser';
}

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function submitPassword() {
    passwordForm.post('/settings/security/password', {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
    });
}

function clearSessions() {
    router.post('/settings/security/sessions/clear', {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Security" />

    <SettingsLayout title="Security" active-section="security">
        <h1 class="set-title">Security</h1>

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

        <!-- Session -->
        <div class="sec-label">Session management</div>
        <div style="background: var(--neutral-bg); border-radius: var(--radius-md); padding: 16px 18px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 36px; height: 36px; border-radius: var(--radius-md); background: rgba(16,185,129,.12); color: #047857; display: grid; place-items: center;">
                    <IconDeviceDesktop :size="18" stroke-width="1.75" />
                </div>
                <div style="flex: 1;">
                    <div style="font: 600 14px/1.3 'Inter', sans-serif;">{{ browserLabel(session.user_agent) }} · {{ session.ip }}</div>
                    <div style="font: 400 12px/1.4 'Inter', sans-serif; color: var(--text-secondary); margin-top: 2px;">
                        Current session · last login {{ formatDate(session.last_login_at) }}
                    </div>
                </div>
                <span class="badge badge-active badge-sm">Active</span>
            </div>
            <div style="margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--border-soft); display: flex; justify-content: space-between; align-items: center; gap: 12px;">
                <div style="font: 400 12.5px/1.4 'Inter', sans-serif; color: var(--text-secondary);">
                    Rotate the session token. Any other browsers signed into this account will be logged out next time they make a request.
                </div>
                <button type="button" class="btn btn-secondary" @click="clearSessions">
                    Sign out all other sessions
                </button>
            </div>
        </div>

        <!-- 2FA -->
        <div class="sec-label">Two-factor authentication</div>
        <div style="background: #fff; border: 1px solid var(--border); border-radius: var(--radius-md); padding: 16px 18px; display: flex; align-items: center; gap: 14px;">
            <div style="width: 36px; height: 36px; border-radius: var(--radius-md); background: var(--neutral-bg); color: var(--text-secondary); display: grid; place-items: center;">
                <IconShieldCheck :size="18" stroke-width="1.75" />
            </div>
            <div style="flex: 1;">
                <div style="font: 600 14px/1.3 'Inter', sans-serif;">Authenticator app</div>
                <div style="font: 400 12px/1.4 'Inter', sans-serif; color: var(--text-secondary); margin-top: 2px;">
                    Adds an extra layer to your account by requiring a one-time code on every login.
                </div>
            </div>
            <span
                class="badge"
                :class="two_factor_enabled ? 'badge-active' : 'badge-inactive'"
            >{{ two_factor_enabled ? 'Enabled' : 'Not enabled' }}</span>
            <button type="button" class="btn btn-ghost" disabled style="opacity: .55; cursor: not-allowed;">
                {{ two_factor_enabled ? 'Manage' : 'Enable 2FA' }}
            </button>
        </div>
        <div class="field-help" style="margin-top: 6px;">2FA setup ships in the security sprint.</div>

        <!-- Password -->
        <div class="sec-label">Change password</div>
        <form @submit.prevent="submitPassword">
            <div class="form-grid-2">
                <div class="field full">
                    <label class="field-label">Current password</label>
                    <input
                        v-model="passwordForm.current_password"
                        class="field-input"
                        :class="{ 'has-err': passwordForm.errors.current_password }"
                        type="password"
                        autocomplete="current-password"
                        required
                    >
                    <div v-if="passwordForm.errors.current_password" class="field-err">{{ passwordForm.errors.current_password }}</div>
                </div>
                <div class="field">
                    <label class="field-label">New password</label>
                    <input
                        v-model="passwordForm.password"
                        class="field-input"
                        :class="{ 'has-err': passwordForm.errors.password }"
                        type="password"
                        autocomplete="new-password"
                        required
                    >
                    <div class="field-help">12 characters minimum.</div>
                    <div v-if="passwordForm.errors.password" class="field-err">{{ passwordForm.errors.password }}</div>
                </div>
                <div class="field">
                    <label class="field-label">Confirm new password</label>
                    <input
                        v-model="passwordForm.password_confirmation"
                        class="field-input"
                        type="password"
                        autocomplete="new-password"
                        required
                    >
                </div>
            </div>
            <div class="set-save-row">
                <button type="submit" class="btn btn-primary" :disabled="passwordForm.processing">
                    <IconKey :size="15" stroke-width="1.75" />
                    {{ passwordForm.processing ? 'Updating…' : 'Update password' }}
                </button>
            </div>
        </form>
    </SettingsLayout>
</template>
