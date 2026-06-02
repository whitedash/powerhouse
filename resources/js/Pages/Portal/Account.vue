<script setup>
import { computed, ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const props = defineProps({
    portal_user: { type: Object, required: true },
    customer: { type: Object, required: true },
    password_meta: { type: Object, default: () => ({}) },
    tokens: { type: Array, default: () => [] },
});

const profileForm = useForm({ name: props.portal_user.name, email: props.portal_user.email });
const passwordForm = useForm({ current_password: '', password: '', password_confirmation: '' });

function submitProfile() {
    profileForm.put('/portal/account', { preserveScroll: true });
}
function submitPassword() {
    passwordForm.put('/portal/account/password', { preserveScroll: true, onSuccess: () => passwordForm.reset() });
}

// Single-line business address from whatever parts exist.
const businessAddress = computed(() => [
    props.customer.address_line1,
    props.customer.city,
    props.customer.postcode,
    props.customer.country,
].filter(Boolean).join(', '));

// Password strength → the handoff's 4-segment meter.
const strength = computed(() => {
    const p = passwordForm.password || '';
    if (! p) return { score: 0, label: '', tone: '' };
    let score = 0;
    if (p.length >= 10) score++;
    if (/[a-z]/.test(p) && /[A-Z]/.test(p)) score++;
    if (/\d/.test(p)) score++;
    if (/[^A-Za-z0-9]/.test(p)) score++;
    const map = {
        1: { label: 'Weak — add length, cases, a number and a symbol', tone: 'on1', color: '#DC2626' },
        2: { label: 'Fair — add a number and a symbol', tone: 'on2', color: '#B45309' },
        3: { label: 'Good — add a symbol for a stronger password', tone: 'on2', color: '#B45309' },
        4: { label: 'Strong password', tone: 'on3', color: '#047857' },
    };
    return { score, ...(map[score] ?? map[1]) };
});

const lastChanged = computed(() => {
    const iso = props.password_meta?.last_changed_at;
    if (! iso) return 'never';
    return new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
});

/* ── Connected apps: per-token revoke (kept) ── */
const confirmRevoke = ref(false);
const revokeTarget = ref(null);
function askRevoke(token) {
    revokeTarget.value = token;
    confirmRevoke.value = true;
}
function doRevoke() {
    if (! revokeTarget.value) return;
    router.delete(`/portal/account/tokens/${revokeTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => { confirmRevoke.value = false; revokeTarget.value = null; },
    });
}
</script>

<template>
    <Head title="Account · Whitedash" />
    <PortalLayout active-nav="account">
        <!-- ═══════════ DESKTOP ═══════════ -->
        <template #desktop>
            <div class="content narrow">
                <div class="page-head"><div class="ph-l"><h1>Your account</h1><div class="ph-sub">Manage your details, security and connected apps</div></div></div>

                <div class="acc-stack">
                    <!-- Personal details -->
                    <form class="acc-card" @submit.prevent="submitProfile">
                        <div class="head"><h3>Personal details</h3><div class="s">Shown on your invoices and account emails</div></div>
                        <div class="body">
                            <div class="grid-2">
                                <div class="field"><label>Full name</label><input class="input" v-model="profileForm.name" required><div v-if="profileForm.errors.name" class="strength-label" style="color:var(--danger)">{{ profileForm.errors.name }}</div></div>
                                <div class="field"><label>Email address</label><input class="input" type="email" v-model="profileForm.email" required><div v-if="profileForm.errors.email" class="strength-label" style="color:var(--danger)">{{ profileForm.errors.email }}</div></div>
                            </div>
                        </div>
                        <div class="foot"><button type="submit" class="btn btn-primary" :disabled="profileForm.processing">{{ profileForm.processing ? 'Saving…' : 'Save changes' }}</button></div>
                    </form>

                    <!-- Business details (read-only) -->
                    <div class="acc-card">
                        <div class="head"><h3>Business details</h3><div class="s">Used for billing &amp; VAT</div></div>
                        <div class="body">
                            <div class="form-rows">
                                <div class="field"><label>Company</label><input class="input readonly" :value="customer.name" readonly></div>
                                <div class="field"><label>Registered address</label><input class="input readonly" :value="businessAddress || '—'" readonly></div>
                            </div>
                            <div class="readonly-note"><i class="ti ti-lock" />Read-only — contact your account manager to update business details.</div>
                        </div>
                    </div>

                    <!-- Password -->
                    <form class="acc-card" @submit.prevent="submitPassword">
                        <div class="head"><h3>Password</h3><div class="s">Last changed: {{ lastChanged }}</div></div>
                        <div class="body">
                            <div class="form-rows">
                                <div class="field"><label>Current password</label><input class="input" type="password" autocomplete="current-password" v-model="passwordForm.current_password" required><div v-if="passwordForm.errors.current_password" class="strength-label" style="color:var(--danger)">{{ passwordForm.errors.current_password }}</div></div>
                                <div class="grid-2">
                                    <div class="field">
                                        <label>New password</label>
                                        <input class="input" :class="{ focused: passwordForm.password }" type="password" autocomplete="new-password" v-model="passwordForm.password" required>
                                        <div class="strength"><div class="seg" :class="strength.score >= 1 ? strength.tone : ''" /><div class="seg" :class="strength.score >= 2 ? strength.tone : ''" /><div class="seg" :class="strength.score >= 3 ? strength.tone : ''" /><div class="seg" :class="strength.score >= 4 ? strength.tone : ''" /></div>
                                        <div v-if="strength.label" class="strength-label" :style="{ color: strength.color }">Strength: {{ strength.label }}</div>
                                        <div v-if="passwordForm.errors.password" class="strength-label" style="color:var(--danger)">{{ passwordForm.errors.password }}</div>
                                    </div>
                                    <div class="field"><label>Confirm new password</label><input class="input" type="password" autocomplete="new-password" v-model="passwordForm.password_confirmation" required></div>
                                </div>
                            </div>
                        </div>
                        <div class="foot"><button type="submit" class="btn btn-primary" :disabled="passwordForm.processing">{{ passwordForm.processing ? 'Saving…' : 'Update password' }}</button></div>
                    </form>

                    <!-- Connected apps -->
                    <div class="acc-card">
                        <div class="head"><h3>Connected apps</h3><div class="s">Apps and API tokens with access to your account</div></div>
                        <div v-if="tokens.length === 0" class="empty">
                            <div class="ic"><i class="ti ti-plug-connected" /></div>
                            <h4>No connected apps yet</h4>
                            <p>When you connect an app or generate an API token, it will appear here so you can review and revoke access.</p>
                        </div>
                        <div v-else class="body" style="display:flex;flex-direction:column;gap:10px">
                            <div v-for="t in tokens" :key="t.id" style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border:1px solid var(--border-soft);border-radius:var(--radius-md)">
                                <div>
                                    <div style="font:600 14px/1.2 'Inter'">{{ t.client_name }}</div>
                                    <div style="font:400 12px/1.4 'Inter';color:var(--text-secondary);margin-top:2px">{{ t.name }}<template v-if="t.scopes && t.scopes.length"> · {{ t.scopes.join(', ') }}</template></div>
                                </div>
                                <button class="btn btn-danger-soft btn-sm" @click="askRevoke(t)"><i class="ti ti-x" />Revoke</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- ═══════════ MOBILE ═══════════ -->
        <template #mobile>
            <div class="m-appbar">
                <div class="title">Account</div>
                <div class="spacer" />
            </div>
            <div class="m-body is-pb">
                <form class="m-card" @submit.prevent="submitProfile">
                    <div class="ch"><div style="font:600 14px/1.3 'Inter'">Personal details</div></div>
                    <div class="cb" style="display:flex;flex-direction:column;gap:14px">
                        <div class="field"><label>Full name</label><input class="input" v-model="profileForm.name" required></div>
                        <div class="field"><label>Email address</label><input class="input" type="email" v-model="profileForm.email" required></div>
                        <button type="submit" class="btn btn-primary btn-block btn-sm" :disabled="profileForm.processing">{{ profileForm.processing ? 'Saving…' : 'Save changes' }}</button>
                    </div>
                </form>

                <div class="m-card">
                    <div class="ch"><div style="font:600 14px/1.3 'Inter'">Business details</div></div>
                    <div class="cb" style="display:flex;flex-direction:column;gap:14px">
                        <div class="field"><label>Company</label><input class="input readonly" :value="customer.name" readonly></div>
                        <div class="field"><label>Address</label><input class="input readonly" :value="businessAddress || '—'" readonly></div>
                        <div class="readonly-note" style="margin-top:0"><i class="ti ti-lock" />Read-only — contact your account manager.</div>
                    </div>
                </div>

                <form class="m-card" @submit.prevent="submitPassword">
                    <div class="ch"><div style="font:600 14px/1.3 'Inter'">Password</div></div>
                    <div class="cb" style="display:flex;flex-direction:column;gap:14px">
                        <div class="field"><label>Current password</label><input class="input" type="password" autocomplete="current-password" v-model="passwordForm.current_password" required></div>
                        <div class="field">
                            <label>New password</label>
                            <input class="input" :class="{ focused: passwordForm.password }" type="password" autocomplete="new-password" v-model="passwordForm.password" required>
                            <div class="strength"><div class="seg" :class="strength.score >= 1 ? strength.tone : ''" /><div class="seg" :class="strength.score >= 2 ? strength.tone : ''" /><div class="seg" :class="strength.score >= 3 ? strength.tone : ''" /><div class="seg" :class="strength.score >= 4 ? strength.tone : ''" /></div>
                            <div v-if="strength.label" class="strength-label" :style="{ color: strength.color }">Strength: {{ strength.label }}</div>
                        </div>
                        <div class="field"><label>Confirm new password</label><input class="input" type="password" autocomplete="new-password" v-model="passwordForm.password_confirmation" required></div>
                        <button type="submit" class="btn btn-primary btn-block btn-sm" :disabled="passwordForm.processing">{{ passwordForm.processing ? 'Saving…' : 'Update password' }}</button>
                    </div>
                </form>

                <div class="m-card">
                    <div class="ch"><div style="font:600 14px/1.3 'Inter'">Connected apps</div></div>
                    <div v-if="tokens.length === 0" class="empty" style="padding:28px 20px"><div class="ic"><i class="ti ti-plug-connected" /></div><h4>No connected apps yet</h4><p>API tokens and connected apps will appear here.</p></div>
                    <div v-else class="cb" style="display:flex;flex-direction:column;gap:10px">
                        <div v-for="t in tokens" :key="t.id" style="display:flex;align-items:center;justify-content:space-between;gap:12px">
                            <div><div style="font:600 13.5px/1.2 'Inter'">{{ t.client_name }}</div><div style="font:400 12px/1.4 'Inter';color:var(--text-secondary)">{{ t.name }}</div></div>
                            <button class="btn btn-danger-soft btn-sm" @click="askRevoke(t)">Revoke</button>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </PortalLayout>

    <ConfirmModal
        v-model:show="confirmRevoke"
        variant="danger"
        :title="`Revoke access for ${revokeTarget?.client_name}?`"
        message="The app holding this token will lose access immediately. You can grant access again by signing in from the product."
        confirm-label="Revoke token"
        @confirm="doRevoke"
    />
</template>
