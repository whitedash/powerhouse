<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Dialog,
    DialogPanel,
    Menu,
    MenuButton,
    MenuItem,
    MenuItems,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import {
    IconPlus,
    IconX,
    IconDots,
} from '@tabler/icons-vue';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const props = defineProps({
    users: { type: Array, default: () => [] },
});

const ROLE_LABEL = { super_admin: 'Super Admin', staff: 'Staff' };

function initials(name) {
    const parts = String(name || '').trim().split(/\s+/);

    return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || '?';
}
function avatarStyle(u) {
    return { background: u.avatar_colour || '#64748B', color: '#fff' };
}
function formatDate(iso) {
    if (! iso) return 'Never';
    return new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}

/* ─── Invite slide-over ─── */
const showInvite = ref(false);
const inviteForm = useForm({ name: '', email: '', role: 'staff' });

function openInvite() {
    inviteForm.reset();
    inviteForm.clearErrors();
    showInvite.value = true;
}
function submitInvite() {
    inviteForm.post('/settings/team/invite', {
        preserveScroll: true,
        onSuccess: () => { showInvite.value = false; },
    });
}

/* ─── Update role ─── */
function changeRole(u, newRole) {
    if (newRole === u.role) return;
    router.put(`/settings/team/${u.id}/role`, { role: newRole }, { preserveScroll: true });
}

/* ─── Remove ─── */
const showRemoveModal = ref(false);
const removeTarget = ref(null);
const removeProcessing = ref(false);

function askRemove(u) {
    removeTarget.value = u;
    showRemoveModal.value = true;
}
function handleRemove() {
    if (! removeTarget.value) return;
    removeProcessing.value = true;
    router.delete(`/settings/team/${removeTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            removeProcessing.value = false;
            showRemoveModal.value = false;
            removeTarget.value = null;
        },
    });
}

</script>

<template>
    <Head title="Team" />

    <SettingsLayout title="Team" active-section="team">
        <template #topbar-actions>
            <button type="button" class="btn btn-primary" @click="openInvite">
                <IconPlus :size="15" stroke-width="1.75" />
                Invite team member
            </button>
        </template>

        <h1 class="set-title">Team members</h1>

        <div
            v-if="$page.props.flash?.temp_password"
            style="margin-bottom: 14px; padding: 12px 14px; background: var(--warning-bg); color: #92400E; border: 1px solid #FDE68A; border-radius: var(--radius-md);"
        >
            <div style="font: 600 13px/1.4 'Inter', sans-serif; margin-bottom: 4px;">Temporary password (shown once):</div>
            <code style="font: 600 14px/1.4 'JetBrains Mono', monospace; background: #fff; padding: 4px 8px; border-radius: 4px; display: inline-block;">{{ $page.props.flash.temp_password }}</code>
            <div style="font: 400 11.5px/1.4 'Inter', sans-serif; margin-top: 6px; color: #92400E;">
                Share via a secure channel. The user must change it on first login.
            </div>
        </div>

        <div style="border: 1px solid var(--border); border-radius: var(--radius-md); overflow: hidden; background: #fff;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #FBFCFE; border-bottom: 1px solid var(--border-soft);">
                        <th style="text-align: left; padding: 12px 16px; font: 500 11px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary);">Member</th>
                        <th style="text-align: left; padding: 12px 16px; font: 500 11px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary);">Role</th>
                        <th style="text-align: left; padding: 12px 16px; font: 500 11px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary);">Last login</th>
                        <th style="text-align: right; padding: 12px 16px;" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="u in users" :key="u.id" style="border-bottom: 1px solid var(--border-soft);">
                        <td style="padding: 14px 16px;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div :style="avatarStyle(u)" style="width: 32px; height: 32px; border-radius: 50%; display: grid; place-items: center; font: 600 12px/1 'Inter', sans-serif;">{{ initials(u.name) }}</div>
                                <div>
                                    <div style="font: 600 14px/1.3 'Inter', sans-serif;">
                                        {{ u.name }}
                                        <span v-if="u.is_me" class="badge badge-inactive badge-sm" style="margin-left: 6px;">You</span>
                                    </div>
                                    <div style="font: 400 12px/1.3 'Inter', sans-serif; color: var(--text-secondary); margin-top: 2px;">{{ u.email }}</div>
                                </div>
                            </div>
                        </td>
                        <td style="padding: 14px 16px;">
                            <span
                                class="badge"
                                :class="u.role === 'super_admin' ? 'badge-pending' : 'badge-active'"
                            >{{ ROLE_LABEL[u.role] }}</span>
                        </td>
                        <td style="padding: 14px 16px; color: var(--text-secondary); font-size: 13px;">{{ formatDate(u.last_login_at) }}</td>
                        <td style="padding: 14px 16px; text-align: right;">
                            <Menu v-if="! u.is_me" as="div" class="dd-menu">
                                <MenuButton class="icon-btn" aria-label="Actions">
                                    <IconDots :size="16" stroke-width="1.75" />
                                </MenuButton>
                                <MenuItems class="dd-popover right-align">
                                    <MenuItem v-if="u.role !== 'super_admin'" v-slot="{ active }">
                                        <button type="button" :class="['dd-option', { active }]" @click="changeRole(u, 'super_admin')">
                                            Promote to Super Admin
                                        </button>
                                    </MenuItem>
                                    <MenuItem v-if="u.role !== 'staff'" v-slot="{ active }">
                                        <button type="button" :class="['dd-option', { active }]" @click="changeRole(u, 'staff')">
                                            Demote to Staff
                                        </button>
                                    </MenuItem>
                                    <div style="height: 1px; background: var(--border-soft); margin: 4px 0;" />
                                    <MenuItem v-slot="{ active }">
                                        <button type="button" :class="['dd-option', { active }]" style="color: var(--danger);" @click="askRemove(u)">
                                            Remove
                                        </button>
                                    </MenuItem>
                                </MenuItems>
                            </Menu>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Invite slide-over -->
        <TransitionRoot as="template" :show="showInvite">
            <Dialog as="div" class="slide-over-dialog" @close="showInvite = false">
                <TransitionChild
                    as="template"
                    enter="transition-opacity ease-out duration-200"
                    enter-from="opacity-0"
                    enter-to="opacity-100"
                    leave="transition-opacity ease-in duration-150"
                    leave-from="opacity-100"
                    leave-to="opacity-0"
                >
                    <div class="slide-over-backdrop" />
                </TransitionChild>
                <TransitionChild
                    as="template"
                    enter="transform transition ease-out duration-200"
                    enter-from="translate-x-full"
                    enter-to="translate-x-0"
                    leave="transform transition ease-in duration-150"
                    leave-from="translate-x-0"
                    leave-to="translate-x-full"
                >
                    <DialogPanel class="slide-over-panel">
                        <form class="slide-over-form" @submit.prevent="submitInvite">
                            <header class="slide-over-header">
                                <h2>Invite team member</h2>
                                <button type="button" class="icon-btn" aria-label="Close" @click="showInvite = false">
                                    <IconX :size="18" stroke-width="1.75" />
                                </button>
                            </header>

                            <div class="slide-over-body">
                                <div class="form-section">
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Name<span class="req">*</span></label>
                                            <input v-model="inviteForm.name" type="text" :class="{ 'has-err': inviteForm.errors.name }" required>
                                            <div v-if="inviteForm.errors.name" class="err">{{ inviteForm.errors.name }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Email<span class="req">*</span></label>
                                            <input v-model="inviteForm.email" type="email" :class="{ 'has-err': inviteForm.errors.email }" required>
                                            <div v-if="inviteForm.errors.email" class="err">{{ inviteForm.errors.email }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Role<span class="req">*</span></label>
                                            <select v-model="inviteForm.role" required>
                                                <option value="staff">Staff</option>
                                                <option value="super_admin">Super Admin</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <footer class="slide-over-footer">
                                <button type="button" class="btn btn-secondary" @click="showInvite = false">Cancel</button>
                                <button type="submit" class="btn btn-primary" :disabled="inviteForm.processing">
                                    <IconPlus :size="15" stroke-width="1.75" />
                                    {{ inviteForm.processing ? 'Inviting…' : 'Send invitation' }}
                                </button>
                            </footer>
                        </form>
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </TransitionRoot>

        <ConfirmModal
            v-model:show="showRemoveModal"
            :title="removeTarget ? `Remove ${removeTarget.name}?` : 'Remove team member?'"
            message="This user will lose access immediately. Their owned records (customers, invoices, tasks) must already be reassigned."
            confirm-label="Remove member"
            variant="danger"
            :loading="removeProcessing"
            @confirm="handleRemove"
        />
    </SettingsLayout>
</template>
