<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { IconAlertCircle } from '@tabler/icons-vue';

const page = usePage();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const flashError = computed(() => page.props.flash?.error);
const generalError = computed(() => form.errors.email || flashError.value);

function submit() {
    form.post('/login', {
        preserveScroll: true,
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Sign in" />

    <div class="login-screen">
        <form class="login-card" @submit.prevent="submit">
            <div class="login-brand">
                <div class="brand-mark">W</div>
                <div class="login-brand-text">
                    <div class="login-title">Powerhouse</div>
                    <div class="login-sub">Whitedash</div>
                </div>
            </div>

            <div v-if="generalError" class="login-error">
                <IconAlertCircle :size="18" stroke-width="2" />
                <span>{{ generalError }}</span>
            </div>

            <div class="login-field">
                <label for="email">Email address</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    autocomplete="email"
                    required
                    autofocus
                >
            </div>

            <div class="login-field">
                <label for="password">Password</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    autocomplete="current-password"
                    required
                >
            </div>

            <button
                type="submit"
                class="login-submit"
                :disabled="form.processing"
            >
                {{ form.processing ? 'Signing in…' : 'Sign in' }}
            </button>

            <div class="login-footer">
                Whitedash Holdings · Internal access only
            </div>
        </form>
    </div>
</template>

<style scoped>
.login-screen {
    min-height: 100vh;
    background: var(--bg-navy);
    display: grid;
    place-items: center;
    padding: 24px;
}

.login-card {
    width: 100%;
    max-width: 420px;
    background: var(--card-bg);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-lg);
    padding: 40px;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.login-brand {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    margin-bottom: 32px;
}

.login-brand-text {
    text-align: center;
}

.login-title {
    font: 700 20px/1.1 'Inter', sans-serif;
    color: var(--text-primary);
    letter-spacing: -.01em;
}

.login-sub {
    font: 500 10px/1 'Inter', sans-serif;
    color: var(--text-tertiary);
    text-transform: uppercase;
    letter-spacing: .12em;
    margin-top: 4px;
}

.login-error {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--danger-bg);
    color: var(--danger);
    border-radius: var(--radius-md);
    padding: 10px 14px;
    font: 500 13px/1.4 'Inter', sans-serif;
}

.login-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.login-field label {
    font: 500 12px/1 'Inter', sans-serif;
    color: var(--text-secondary);
    letter-spacing: -.005em;
}

.login-field input {
    width: 100%;
    height: 40px;
    border: 1px solid var(--border);
    background: #fff;
    border-radius: var(--radius-md);
    padding: 0 12px;
    font: 400 14px/1 'Inter', sans-serif;
    color: var(--text-primary);
    outline: 0;
    transition: border-color .15s, box-shadow .15s;
}

.login-field input:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(245, 158, 11, .18);
}

.login-submit {
    width: 100%;
    height: 42px;
    border: 0;
    border-radius: var(--radius-md);
    background: var(--accent);
    color: var(--bg-navy);
    font: 600 14px/1 'Inter', sans-serif;
    letter-spacing: -.005em;
    cursor: pointer;
    margin-top: 8px;
    box-shadow: 0 1px 2px rgba(245, 158, 11, .3);
    transition: background .15s, opacity .15s;
}

.login-submit:hover:not(:disabled) {
    background: #E08C09;
}

.login-submit:disabled {
    opacity: .6;
    cursor: not-allowed;
}

.login-footer {
    text-align: center;
    color: var(--text-tertiary);
    font: 400 12px/1.4 'Inter', sans-serif;
    margin-top: 24px;
}
</style>
