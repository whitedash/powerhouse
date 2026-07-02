<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { IconAlertCircle, IconArrowLeft, IconInfoCircle, IconLock } from '@tabler/icons-vue';

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
            <a href="/" class="portal-login-brand" style="text-decoration:none;color:inherit;" aria-label="Whitedash hub">
                <div class="brand-mark">W</div>
                <div class="portal-login-brand-name">Whitedash</div>
                <div class="portal-login-brand-sub">customer portal</div>
            </a>

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
                v-if="$page.props.flash?.info"
                class="portal-login-flash info"
            >
                <IconInfoCircle :size="16" stroke-width="2" />
                {{ $page.props.flash.info }}
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
                    <Link href="/portal/forgot-password" class="portal-login-forgot">Forgot password?</Link>
                </div>

                <button type="submit" class="btn btn-primary btn-block" :disabled="form.processing">
                    <IconLock :size="14" stroke-width="2" />
                    {{ form.processing ? 'Signing in…' : 'Sign in' }}
                </button>
            </form>

            <div class="portal-login-footer">
                Need access? Ask your account manager to invite you.
            </div>

            <div style="text-align:center;margin-top:14px;">
                <a href="/" class="portal-back-link">
                    <IconArrowLeft :size="14" stroke-width="1.75" />
                    Back to hub
                </a>
            </div>
        </div>

        <div class="portal-login-legal">
            © 2026 Whitedash Holdings Ltd · <a href="#">Privacy</a> · <a href="#">Terms</a>
        </div>
    </div>
</template>
