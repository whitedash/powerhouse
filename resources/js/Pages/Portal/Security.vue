<script setup>
/**
 * Portal Security page — password change + per-token revoke list.
 *
 * Two surfaces stacked vertically:
 *
 *   1. Password card — current password + new + confirm, with the
 *      same PasswordStrengthMeter as the forgot-password flow.
 *      POSTs to /portal/account/password (existing endpoint shared
 *      with the Account page so validation rules stay in one place).
 *
 *   2. Connected applications card — every active OAuth token for
 *      this customer (aggregated across portal users). Per row:
 *      app name + scopes + issued/expires + Revoke button.
 *
 * The dashboard still surfaces a roll-up of connected apps (one
 * card per client). This page is the fine-grained view — token by
 * token, so a user can revoke a specific launch session without
 * killing the whole client.
 */
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import {
    IconShieldLock, IconKey, IconClock, IconX,
} from '@tabler/icons-vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PasswordStrengthMeter from '@/Components/UI/PasswordStrengthMeter.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const props = defineProps({
    password_meta: { type: Object, default: () => ({}) },
    tokens: { type: Array, default: () => [] },
});

/* ─── Password change ─── */
const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function submitPassword() {
    passwordForm.put('/portal/account/password', {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
    });
}

/* ─── Token revoke ─── */
const confirmRevoke = ref(false);
const revokeTarget = ref(null);
function askRevoke(token) {
    revokeTarget.value = token;
    confirmRevoke.value = true;
}
function doRevoke() {
    if (!revokeTarget.value) return;
    router.delete(`/portal/security/tokens/${revokeTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => { confirmRevoke.value = false; revokeTarget.value = null; },
    });
}

function fmtDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}
function fmtRelative(iso) {
    if (!iso) return '—';
    const diff = (Date.now() - new Date(iso).getTime()) / 1000;
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    if (diff < 86400 * 30) return Math.floor(diff / 86400) + 'd ago';
    return fmtDate(iso);
}
</script>

<template>
    <PortalLayout active-nav="security">
        <Head title="Security · Portal" />

        <div class="portal-security">
            <header class="portal-security-head">
                <IconShieldLock :size="24" stroke-width="1.6" />
                <div>
                    <h1>Security</h1>
                    <p class="muted">Manage your password and review the apps that have access to your account.</p>
                </div>
            </header>

            <!-- Password card -->
            <section class="portal-security-card">
                <header class="portal-security-card-head">
                    <IconKey :size="18" stroke-width="2" />
                    <div>
                        <h2>Password</h2>
                        <div v-if="password_meta?.last_changed_at" class="muted small">
                            Last changed {{ fmtRelative(password_meta.last_changed_at) }}
                        </div>
                        <div v-else class="muted small">Hasn't been changed since this account was created.</div>
                    </div>
                </header>

                <form class="portal-security-form" @submit.prevent="submitPassword">
                    <div class="form-row">
                        <label>Current password</label>
                        <input
                            v-model="passwordForm.current_password"
                            type="password"
                            autocomplete="current-password"
                            :class="{ 'has-err': passwordForm.errors.current_password }"
                            required
                        />
                        <div v-if="passwordForm.errors.current_password" class="err">
                            {{ passwordForm.errors.current_password }}
                        </div>
                    </div>
                    <div class="form-row">
                        <label>New password</label>
                        <input
                            v-model="passwordForm.password"
                            type="password"
                            autocomplete="new-password"
                            :class="{ 'has-err': passwordForm.errors.password }"
                            required
                        />
                        <PasswordStrengthMeter :password="passwordForm.password" />
                        <div v-if="passwordForm.errors.password" class="err">
                            {{ passwordForm.errors.password }}
                        </div>
                    </div>
                    <div class="form-row">
                        <label>Confirm new password</label>
                        <input
                            v-model="passwordForm.password_confirmation"
                            type="password"
                            autocomplete="new-password"
                            required
                        />
                    </div>
                    <div class="portal-security-form-foot">
                        <button type="submit" class="btn btn-primary" :disabled="passwordForm.processing">
                            {{ passwordForm.processing ? 'Updating…' : 'Update password' }}
                        </button>
                    </div>
                </form>
            </section>

            <!-- Connected applications -->
            <section class="portal-security-card">
                <header class="portal-security-card-head">
                    <IconShieldLock :size="18" stroke-width="2" />
                    <div>
                        <h2>Connected applications</h2>
                        <div class="muted small">
                            Apps holding active access tokens for your account.
                            Revoking will sign you out of that application.
                        </div>
                    </div>
                </header>

                <div v-if="tokens.length === 0" class="portal-security-empty muted">
                    No connected apps. Tokens appear here once you've opened a product from your dashboard or authorised an integration.
                </div>

                <div v-else class="portal-security-token-list">
                    <article v-for="t in tokens" :key="t.id" class="portal-security-token-row">
                        <div class="ptr-meta">
                            <div class="ptr-name">{{ t.client_name }}</div>
                            <div class="muted small">
                                {{ t.name }}
                                <template v-if="t.scopes && t.scopes.length">
                                    · {{ t.scopes.join(', ') }}
                                </template>
                            </div>
                            <div class="muted small ptr-dates">
                                <IconClock :size="12" stroke-width="2" />
                                Issued {{ fmtRelative(t.created_at) }}
                                <template v-if="t.expires_at">
                                    · Expires {{ fmtDate(t.expires_at) }}
                                </template>
                            </div>
                        </div>
                        <button type="button" class="btn btn-ghost btn-sm" @click="askRevoke(t)">
                            <IconX :size="13" stroke-width="2" /> Revoke
                        </button>
                    </article>
                </div>
            </section>
        </div>

        <ConfirmModal
            v-model:show="confirmRevoke"
            variant="danger"
            :title="`Revoke access for ${revokeTarget?.client_name}?`"
            message="The app holding this token will lose access immediately. You can grant access again by signing in from the product."
            confirm-label="Revoke token"
            @confirm="doRevoke"
        />
    </PortalLayout>
</template>
