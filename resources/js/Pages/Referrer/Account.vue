<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import {
    IconCircleCheck,
    IconLock,
    IconAlertCircle,
    IconUser,
    IconCreditCard,
    IconShieldLock,
} from '@tabler/icons-vue';
import ReferrerLayout from '@/Layouts/ReferrerLayout.vue';

const props = defineProps({
    user: { type: Object, required: true },
    payment_summary: { type: Object, required: true },
});

const profileForm = useForm({
    name: props.user.name,
    email: props.user.email,
});

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const paymentForm = useForm({
    bank_name: props.payment_summary.bank_name ?? '',
    account_name: props.payment_summary.account_name ?? '',
    sort_code: props.payment_summary.sort_code ?? '',
    account_number: '',
});

function submitProfile() {
    profileForm.put('/referrer/account', { preserveScroll: true });
}

function submitPassword() {
    passwordForm.put('/referrer/account/password', {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
    });
}

function submitPayment() {
    paymentForm.put('/referrer/account/payment', {
        preserveScroll: true,
        onSuccess: () => paymentForm.reset('account_number'),
    });
}
</script>

<template>
    <Head title="Account · Whitedash Partners" />
    <ReferrerLayout title="Account" active-nav="account">
        <div
            v-if="$page.props.flash?.success"
            class="portal-flash success"
        >
            <IconCircleCheck :size="16" stroke-width="2" />
            {{ $page.props.flash.success }}
        </div>
        <div
            v-if="$page.props.flash?.error"
            class="portal-flash error"
        >
            <IconAlertCircle :size="16" stroke-width="2" />
            {{ $page.props.flash.error }}
        </div>

        <div class="portal-section-head">
            <div class="col-l">
                <h2>Your account</h2>
                <div class="desc">Manage your personal details, password, and payout preferences.</div>
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

        <!-- Change password -->
        <form class="portal-account-card" @submit.prevent="submitPassword">
            <div class="portal-account-card-header">
                <IconLock :size="18" stroke-width="1.75" />
                <h3>Change password</h3>
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
                <div class="form-hint">Minimum 10 characters.</div>
                <div v-if="passwordForm.errors.password" class="err">{{ passwordForm.errors.password }}</div>
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

        <!-- Payment details -->
        <form class="portal-account-card" @submit.prevent="submitPayment">
            <div class="portal-account-card-header">
                <IconCreditCard :size="18" stroke-width="1.75" />
                <h3>Payment details</h3>
                <span
                    v-if="payment_summary.has_details"
                    class="badge badge-active badge-sm"
                    style="margin-left: auto;"
                >
                    On file
                </span>
            </div>

            <p style="margin: 0 0 6px; font: 400 13px/1.5 'Inter', sans-serif; color: var(--text-secondary);">
                How would you like to receive commission payments? We pay out monthly by UK bank transfer.
            </p>

            <div
                v-if="payment_summary.has_details"
                style="display: flex; align-items: center; gap: 8px; padding: 10px 12px; background: var(--neutral-bg); border: 1px solid var(--border-soft); border-radius: var(--radius-md); font: 400 12.5px/1.4 'Inter', sans-serif; color: var(--text-secondary);"
            >
                <IconShieldLock :size="14" stroke-width="1.75" />
                Account number on file:
                <code style="font: 500 13px/1.3 'JetBrains Mono', monospace; color: var(--text-primary); margin-left: 2px;">
                    •••• {{ payment_summary.account_number_last4 }}
                </code>
                — re-enter your full account number to update.
            </div>

            <div class="form-field">
                <label>Bank name</label>
                <input
                    v-model="paymentForm.bank_name"
                    type="text"
                    :class="{ 'has-err': paymentForm.errors.bank_name }"
                    placeholder="e.g. Monzo, Starling, Lloyds"
                    required
                >
                <div v-if="paymentForm.errors.bank_name" class="err">{{ paymentForm.errors.bank_name }}</div>
            </div>

            <div class="form-field">
                <label>Account holder name</label>
                <input
                    v-model="paymentForm.account_name"
                    type="text"
                    :class="{ 'has-err': paymentForm.errors.account_name }"
                    placeholder="Name on the bank account"
                    required
                >
                <div v-if="paymentForm.errors.account_name" class="err">{{ paymentForm.errors.account_name }}</div>
            </div>

            <div class="form-field">
                <label>Sort code</label>
                <input
                    v-model="paymentForm.sort_code"
                    type="text"
                    :class="{ 'has-err': paymentForm.errors.sort_code }"
                    placeholder="00-00-00"
                    inputmode="numeric"
                    required
                >
                <div v-if="paymentForm.errors.sort_code" class="err">{{ paymentForm.errors.sort_code }}</div>
            </div>

            <div class="form-field">
                <label>Account number</label>
                <input
                    v-model="paymentForm.account_number"
                    type="text"
                    :class="{ 'has-err': paymentForm.errors.account_number }"
                    placeholder="8 digits"
                    inputmode="numeric"
                    autocomplete="off"
                    required
                >
                <div v-if="paymentForm.errors.account_number" class="err">{{ paymentForm.errors.account_number }}</div>
            </div>

            <div
                style="display: flex; align-items: flex-start; gap: 8px; padding: 10px 12px; background: var(--info-bg); border: 1px solid #BFDBFE; border-radius: var(--radius-md); font: 400 12.5px/1.45 'Inter', sans-serif; color: #1D4ED8;"
            >
                <IconShieldLock :size="14" stroke-width="1.75" style="flex-shrink: 0; margin-top: 1px;" />
                <span>
                    Payment details are encrypted at rest and only used for commission payouts. Whitedash staff cannot
                    read your bank details — they're decrypted only at payout time.
                </span>
            </div>

            <div class="portal-account-footer">
                <button type="submit" class="btn btn-primary" :disabled="paymentForm.processing">
                    {{ paymentForm.processing ? 'Saving…' : 'Save payment details' }}
                </button>
            </div>
        </form>

        <div class="portal-last-login">
            <span v-if="user.last_login_at">Last sign-in: {{ user.last_login_at }}</span>
        </div>
    </ReferrerLayout>
</template>
