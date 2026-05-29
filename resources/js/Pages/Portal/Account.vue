<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import {
    IconCircleCheck,
    IconLock,
    IconAlertCircle,
    IconUser,
} from '@tabler/icons-vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';

const props = defineProps({
    portal_user: { type: Object, required: true },
    customer: { type: Object, required: true },
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
</script>

<template>
    <Head title="Account · Whitedash" />
    <PortalLayout title="Account" active-nav="account">
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

        <!-- Customer details (read-only) -->
        <div class="portal-account-card">
            <div class="portal-account-card-header">
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

        <div class="portal-last-login">
            <span v-if="portal_user.last_login_at">Last sign-in: {{ portal_user.last_login_at }}</span>
        </div>
    </PortalLayout>
</template>
