<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { IconAlertCircle, IconLock } from '@tabler/icons-vue';

defineProps({
    canResetPassword: { type: Boolean, default: false },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit() {
    form.post('/portal/login', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Sign in · Whitedash" />
    <div class="portal-login">
        <div class="portal-login-card">
            <div class="portal-login-brand">
                <div class="brand-mark">W</div>
                <div class="portal-login-brand-name">Whitedash</div>
                <div class="portal-login-brand-sub">customer portal</div>
            </div>

            <h1 class="portal-login-title">Sign in to your account</h1>
            <p class="portal-login-subtitle">
                Manage your subscriptions, invoices, and support tickets.
            </p>

            <div
                v-if="$page.props.flash?.success"
                class="portal-login-flash success"
            >
                {{ $page.props.flash.success }}
            </div>

            <div
                v-if="form.errors.email"
                class="portal-login-flash error"
            >
                <IconAlertCircle :size="16" stroke-width="2" />
                {{ form.errors.email }}
            </div>

            <form class="portal-login-form" @submit.prevent="submit">
                <div class="form-field">
                    <label for="email">Email address</label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                        required
                        autofocus
                        placeholder="you@example.com"
                    >
                </div>

                <div class="form-field">
                    <label for="password">Password</label>
                    <input
                        id="password"
                        v-model="form.password"
                        type="password"
                        autocomplete="current-password"
                        required
                        placeholder="••••••••"
                    >
                </div>

                <div class="portal-login-options">
                    <label class="portal-login-remember">
                        <input v-model="form.remember" type="checkbox">
                        <span>Remember me</span>
                    </label>
                    <a v-if="canResetPassword" href="#" class="portal-login-forgot">Forgot password?</a>
                </div>

                <button type="submit" class="btn btn-primary btn-block" :disabled="form.processing">
                    <IconLock :size="14" stroke-width="2" />
                    {{ form.processing ? 'Signing in…' : 'Sign in' }}
                </button>
            </form>

            <div class="portal-login-footer">
                Need access? Ask your account manager to invite you.
            </div>
        </div>

        <div class="portal-login-legal">
            © 2026 Whitedash Holdings Ltd · <a href="#">Privacy</a> · <a href="#">Terms</a>
        </div>
    </div>
</template>
