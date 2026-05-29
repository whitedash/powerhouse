<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { IconAlertCircle, IconArrowLeft, IconLock } from '@tabler/icons-vue';
import PasswordStrengthMeter from '@/Components/UI/PasswordStrengthMeter.vue';

const props = defineProps({
    email: { type: String, default: '' },
    token: { type: String, default: '' },
});

const form = useForm({
    email: props.email,
    token: props.token,
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post('/portal/reset-password', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Set new password · Whitedash" />
    <div class="portal-login">
        <div class="portal-login-card">
            <div class="portal-login-brand">
                <div class="brand-mark">W</div>
                <div class="portal-login-brand-name">Whitedash</div>
                <div class="portal-login-brand-sub">customer portal</div>
            </div>

            <h1 class="portal-login-title">Set a new password</h1>
            <p class="portal-login-subtitle">
                Pick something strong. You'll need it every time you sign in.
            </p>

            <div
                v-if="form.errors.token"
                class="portal-login-flash error"
            >
                <IconAlertCircle :size="16" stroke-width="2" />
                {{ form.errors.token }}
            </div>
            <div
                v-if="form.errors.email && ! form.errors.token"
                class="portal-login-flash error"
            >
                <IconAlertCircle :size="16" stroke-width="2" />
                {{ form.errors.email }}
            </div>

            <form class="portal-login-form" @submit.prevent="submit">
                <input v-model="form.token" type="hidden">

                <div class="form-field">
                    <label for="email">Email address</label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                        required
                        readonly
                    >
                </div>

                <div class="form-field">
                    <label for="password">New password</label>
                    <input
                        id="password"
                        v-model="form.password"
                        type="password"
                        autocomplete="new-password"
                        required
                        autofocus
                        placeholder="••••••••"
                    >
                    <div v-if="form.errors.password" class="err">{{ form.errors.password }}</div>
                    <PasswordStrengthMeter :password="form.password" />
                </div>

                <div class="form-field">
                    <label for="password_confirmation">Confirm password</label>
                    <input
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        required
                        placeholder="••••••••"
                    >
                </div>

                <button type="submit" class="btn btn-primary btn-block" :disabled="form.processing">
                    <IconLock :size="14" stroke-width="2" />
                    {{ form.processing ? 'Updating…' : 'Update password' }}
                </button>
            </form>

            <div class="portal-login-footer">
                <Link href="/portal/login" class="portal-back-link">
                    <IconArrowLeft :size="14" stroke-width="1.75" />
                    Back to sign in
                </Link>
            </div>
        </div>

        <div class="portal-login-legal">
            © 2026 Whitedash Holdings Ltd · <a href="#">Privacy</a> · <a href="#">Terms</a>
        </div>
    </div>
</template>
