<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { IconAlertCircle, IconArrowLeft, IconCircleCheck, IconMail } from '@tabler/icons-vue';

const form = useForm({
    email: '',
});

function submit() {
    form.post('/portal/forgot-password', {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Forgot password · Whitedash" />
    <div class="portal-login">
        <div class="portal-login-card">
            <div class="portal-login-brand">
                <div class="brand-mark">W</div>
                <div class="portal-login-brand-name">Whitedash</div>
                <div class="portal-login-brand-sub">customer portal</div>
            </div>

            <h1 class="portal-login-title">Forgot your password?</h1>
            <p class="portal-login-subtitle">
                Enter the email tied to your portal account. If it matches, we'll send you a reset link.
            </p>

            <div
                v-if="$page.props.flash?.success"
                class="portal-login-flash success"
            >
                <IconCircleCheck :size="16" stroke-width="2" />
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

                <button type="submit" class="btn btn-primary btn-block" :disabled="form.processing">
                    <IconMail :size="14" stroke-width="2" />
                    {{ form.processing ? 'Sending…' : 'Send reset link' }}
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
