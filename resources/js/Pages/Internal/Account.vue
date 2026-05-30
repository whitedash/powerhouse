<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    IconUser,
    IconLock,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';
import PasswordStrengthMeter from '@/Components/UI/PasswordStrengthMeter.vue';

const props = defineProps({
    user: { type: Object, required: true },
});

const breadcrumbs = [{ label: 'My account' }];

const initials = computed(() => {
    const parts = (props.user.name || '').trim().split(/\s+/);

    return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || '?';
});

const roleLabel = computed(() => {
    return {
        super_admin: 'Super admin',
        staff: 'Staff',
        referrer: 'Referrer',
    }[props.user.role] ?? props.user.role;
});

const memberSince = computed(() => {
    if (! props.user.created_at) return '—';

    return new Date(props.user.created_at).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
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

function submitProfile() {
    profileForm.put('/account', { preserveScroll: true });
}

function submitPassword() {
    passwordForm.put('/account/password', {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
    });
}
</script>

<template>
    <Head title="My account" />
    <InternalLayout title="My account" :breadcrumbs="breadcrumbs">
        <div class="my-account">
            <!-- Profile -->
            <section class="my-account-card">
                <header class="my-account-card-header">
                    <IconUser :size="18" stroke-width="1.75" />
                    <h3>Profile</h3>
                </header>

                <div class="my-account-avatar-block">
                    <div
                        class="my-account-avatar"
                        :style="{ background: user.avatar_colour || 'var(--accent)' }"
                    >{{ initials }}</div>
                    <div>
                        <div class="my-account-name">{{ user.name }}</div>
                        <div class="my-account-role">
                            <span class="badge badge-info badge-sm">{{ roleLabel }}</span>
                            <span class="my-account-since">Member since {{ memberSince }}</span>
                        </div>
                    </div>
                </div>

                <form class="my-account-form" @submit.prevent="submitProfile">
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

                    <div class="my-account-footer">
                        <button type="submit" class="btn btn-primary" :disabled="profileForm.processing">
                            {{ profileForm.processing ? 'Saving…' : 'Save changes' }}
                        </button>
                    </div>
                </form>
            </section>

            <!-- Password -->
            <section class="my-account-card">
                <header class="my-account-card-header">
                    <IconLock :size="18" stroke-width="1.75" />
                    <h3>Change password</h3>
                </header>

                <form class="my-account-form" @submit.prevent="submitPassword">
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

                    <div class="my-account-footer">
                        <button type="submit" class="btn btn-primary" :disabled="passwordForm.processing">
                            {{ passwordForm.processing ? 'Saving…' : 'Update password' }}
                        </button>
                    </div>
                </form>
            </section>

            <div v-if="user.last_login_at" class="my-account-last-login">
                Last sign-in: {{ user.last_login_at }}
            </div>
        </div>
    </InternalLayout>
</template>
