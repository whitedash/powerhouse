<script setup>
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import {
    IconLock,
    IconUser,
    IconBuilding,
    IconShieldLock,
    IconClock,
    IconX,
} from '@tabler/icons-vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PasswordStrengthMeter from '@/Components/UI/PasswordStrengthMeter.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const props = defineProps({
    portal_user: { type: Object, required: true },
    customer: { type: Object, required: true },
    password_meta: { type: Object, default: () => ({}) },
    tokens: { type: Array, default: () => [] },
});

const profileForm = useForm({
    name: props.portal_user.name,
    email: props.portal_user.email,
});

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function submitProfile() {
    profileForm.put('/portal/account', { preserveScroll: true });
}

function submitPassword() {
    passwordForm.put('/portal/account/password', {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
    });
}

/* ─── Connected apps: per-token revoke ─── */
const confirmRevoke = ref(false);
const revokeTarget = ref(null);

function askRevoke(token) {
    revokeTarget.value = token;
    confirmRevoke.value = true;
}

function doRevoke() {
    if (!revokeTarget.value) return;
    router.delete(`/portal/account/tokens/${revokeTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => { confirmRevoke.value = false; revokeTarget.value = null; },
    });
}

function fmtDate(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function fmtRelative(iso) {
    if (!iso) return '—';
    const diff = (Date.now() - new Date(iso).getTime()) / 1000;
    if (diff < 60) return 'just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    if (diff < 86400 * 30) return `${Math.floor(diff / 86400)}d ago`;
    return fmtDate(iso);
}
</script>

<template>
    <Head title="Account · Whitedash" />
    <PortalLayout title="Account" active-nav="account">
        <div class="portal-section-head">
            <div class="col-l">
                <h2>Your account</h2>
                <div class="desc">Manage your personal details and password.</div>
            </div>
        </div>

        <!-- Personal details -->
        <form class="portal-account-card" @submit.prevent="submitProfile">
            <div class="portal-account-card-header">
                <IconUser :size="18" stroke-width="1.75" />
                <h3>Personal details</h3>
            </div>

            <div class="form-field">
                <label>Name</label>
                <input
                    v-model="profileForm.name"
                    type="text"
                    :class="{ 'has-err': profileForm.errors.name }"
                    required
                >
                <div v-if="profileForm.errors.name" class="err">{{ profileForm.errors.name }}</div>
            </div>

            <div class="form-field">
                <label>Email</label>
                <input
                    v-model="profileForm.email"
                    type="email"
                    :class="{ 'has-err': profileForm.errors.email }"
                    required
                >
                <div v-if="profileForm.errors.email" class="err">{{ profileForm.errors.email }}</div>
            </div>

            <div class="portal-account-footer">
                <button type="submit" class="btn btn-primary" :disabled="profileForm.processing">
                    {{ profileForm.processing ? 'Saving…' : 'Save changes' }}
                </button>
            </div>
        </form>

        <!-- Business details (read-only) -->
        <div class="portal-account-card">
            <div class="portal-account-card-header">
                <IconBuilding :size="18" stroke-width="1.75" />
                <h3>Business details</h3>
                <span class="portal-account-readonly">read-only — contact your account manager to update</span>
            </div>

            <dl class="portal-account-readonly-list">
                <div>
                    <dt>Company</dt>
                    <dd>{{ customer.name }}</dd>
                </div>
                <div v-if="customer.address_line1">
                    <dt>Address</dt>
                    <dd>
                        {{ customer.address_line1 }}<br>
                        <template v-if="customer.city">{{ customer.city }}</template>
                        <template v-if="customer.postcode">, {{ customer.postcode }}</template>
                        <template v-if="customer.country"><br>{{ customer.country }}</template>
                    </dd>
                </div>
                <div v-if="customer.primary_contact_email">
                    <dt>Primary contact</dt>
                    <dd>{{ customer.primary_contact_email }}</dd>
                </div>
            </dl>
        </div>

        <!-- Change password -->
        <form class="portal-account-card" @submit.prevent="submitPassword">
            <div class="portal-account-card-header">
                <IconLock :size="18" stroke-width="1.75" />
                <h3>Change password</h3>
                <span v-if="password_meta?.last_changed_at" class="portal-account-readonly">
                    last changed {{ fmtRelative(password_meta.last_changed_at) }}
                </span>
            </div>

            <div class="form-field">
                <label>Current password</label>
                <input
                    v-model="passwordForm.current_password"
                    type="password"
                    autocomplete="current-password"
                    :class="{ 'has-err': passwordForm.errors.current_password }"
                    required
                >
                <div v-if="passwordForm.errors.current_password" class="err">{{ passwordForm.errors.current_password }}</div>
            </div>

            <div class="form-field">
                <label>New password</label>
                <input
                    v-model="passwordForm.password"
                    type="password"
                    autocomplete="new-password"
                    :class="{ 'has-err': passwordForm.errors.password }"
                    required
                >
                <div class="form-hint">Minimum 10 characters with upper case, lower case, a number, and a symbol.</div>
                <div v-if="passwordForm.errors.password" class="err">{{ passwordForm.errors.password }}</div>
                <PasswordStrengthMeter :password="passwordForm.password" />
            </div>

            <div class="form-field">
                <label>Confirm new password</label>
                <input
                    v-model="passwordForm.password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    required
                >
            </div>

            <div class="portal-account-footer">
                <button type="submit" class="btn btn-primary" :disabled="passwordForm.processing">
                    {{ passwordForm.processing ? 'Saving…' : 'Update password' }}
                </button>
            </div>
        </form>

        <!-- Connected apps -->
        <div class="portal-account-card">
            <div class="portal-account-card-header">
                <IconShieldLock :size="18" stroke-width="1.75" />
                <h3>Connected apps</h3>
                <span class="portal-account-readonly">apps with active access to your account</span>
            </div>

            <div v-if="tokens.length === 0" class="portal-account-apps-empty">
                No connected apps yet. They appear here once you open a product from your dashboard or authorise an integration.
            </div>

            <div v-else class="portal-account-apps">
                <article v-for="t in tokens" :key="t.id" class="portal-account-app">
                    <div class="paa-meta">
                        <div class="paa-name">{{ t.client_name }}</div>
                        <div class="paa-sub">
                            {{ t.name }}
                            <template v-if="t.scopes && t.scopes.length"> · {{ t.scopes.join(', ') }}</template>
                        </div>
                        <div class="paa-dates">
                            <IconClock :size="12" stroke-width="2" />
                            Issued {{ fmtRelative(t.created_at) }}
                            <template v-if="t.expires_at"> · Expires {{ fmtDate(t.expires_at) }}</template>
                        </div>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm" @click="askRevoke(t)">
                        <IconX :size="13" stroke-width="2" />
                        Revoke
                    </button>
                </article>
            </div>
        </div>

        <div class="portal-last-login">
            <span v-if="portal_user.last_login_at">Last sign-in: {{ portal_user.last_login_at }}</span>
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

<style scoped>
.portal-account-apps {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.portal-account-app {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 14px;
    border: 1px solid var(--border-soft, #eef2f7);
    border-radius: 10px;
}
.paa-meta { min-width: 0; }
.paa-name { font: 600 14px/1.2 'Inter', sans-serif; color: var(--text-primary, #111827); }
.paa-sub { font: 400 12px/1.4 'Inter', sans-serif; color: var(--text-muted, #6b7280); margin-top: 2px; }
.paa-dates {
    display: flex;
    align-items: center;
    gap: 4px;
    font: 400 11px/1.4 'Inter', sans-serif;
    color: var(--text-tertiary, #9ca3af);
    margin-top: 4px;
}
.portal-account-apps-empty {
    font: 400 13px/1.5 'Inter', sans-serif;
    color: var(--text-muted, #6b7280);
}
</style>
