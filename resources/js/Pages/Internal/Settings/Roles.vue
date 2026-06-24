<script setup>
import { reactive, ref, computed, watch } from 'vue';
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
    IconPlus, IconX, IconDots, IconSearch, IconCrown,
    IconUsers, IconUserCircle, IconReceipt, IconFilter, IconFileDescription,
    IconBriefcase, IconCheckbox, IconHeadset, IconForms, IconWorld, IconCash,
    IconRoute, IconBook2, IconLayoutGrid, IconChartLine, IconUsersGroup,
    IconToolsKitchen2, IconSettings, IconAlertTriangle,
} from '@tabler/icons-vue';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const props = defineProps({
    roles: { type: Array, default: () => [] },
    groups: { type: Array, default: () => [] },
    areas: { type: Array, default: () => [] },
    scopeOptions: { type: Array, default: () => [] },
    copyableRoles: { type: Array, default: () => [] },
    superAdminNote: { type: String, default: '' },
});

const GROUP_ICONS = {
    users: IconUsers, 'user-circle': IconUserCircle, receipt: IconReceipt,
    filter: IconFilter, 'file-description': IconFileDescription, briefcase: IconBriefcase,
    checkbox: IconCheckbox, headset: IconHeadset, forms: IconForms, world: IconWorld,
    cash: IconCash, route: IconRoute, book: IconBook2, 'layout-grid': IconLayoutGrid,
    chart: IconChartLine, 'users-group': IconUsersGroup, tools: IconToolsKitchen2,
    settings: IconSettings,
};

const SCOPE_LABEL = { all: 'All', assigned: 'Assigned', none: 'None' };
const SCOPE_SEG = { all: 's-all', assigned: 's-asgn', none: 's-none' };

/* ─── Local optimistic state: roleId -> { perms:Set, scopes:{area:scope} } ─── */
const state = reactive({});
function rebuild() {
    Object.keys(state).forEach((k) => delete state[k]);
    props.roles.forEach((r) => {
        state[r.id] = { perms: new Set(r.permissions), scopes: { ...r.scopes } };
    });
}
watch(() => props.roles, rebuild, { immediate: true, deep: true });

const hasPerm = (roleId, key) => state[roleId]?.perms.has(key) ?? false;
const scopeOf = (roleId, area) => state[roleId]?.scopes?.[area] ?? 'none';

function togglePerm(role, key) {
    const set = state[role.id].perms;
    const granted = ! set.has(key);
    if (granted) set.add(key); else set.delete(key);
    router.put(`/settings/roles/${role.id}/permissions`, { permission: key, granted }, {
        preserveScroll: true,
        preserveState: true,
        onError: () => rebuild(),
    });
}
function setScope(role, area, scope) {
    if (state[role.id].scopes[area] === scope) return;
    state[role.id].scopes[area] = scope;
    router.put(`/settings/roles/${role.id}/scope`, { area, scope }, {
        preserveScroll: true,
        preserveState: true,
        onError: () => rebuild(),
    });
}

/* ─── Search + filters (live) ─── */
const search = ref('');
const areaFilter = ref('');
const highStakesOnly = ref(false);

const filteredGroups = computed(() => {
    const q = search.value.trim().toLowerCase();
    return props.groups.map((g) => {
        if (areaFilter.value && g.key !== areaFilter.value) return null;
        let rows = g.rows;
        if (highStakesOnly.value) rows = rows.filter((r) => r.type === 'danger');
        if (q) {
            rows = rows.filter((r) =>
                r.key.toLowerCase().includes(q)
                || (r.label || '').toLowerCase().includes(q)
                || (r.desc || '').toLowerCase().includes(q));
        }
        if (! rows.length) return null;

        return { ...g, rows };
    }).filter(Boolean);
});
const visibleCount = computed(() => filteredGroups.value.reduce((n, g) => n + g.rows.length, 0));
function clearFilters() {
    search.value = '';
    areaFilter.value = '';
    highStakesOnly.value = false;
}

function rowClass(row) {
    if (row.type === 'danger') return 'danger';
    if (row.type === 'pii') return 'pii';
    if (row.type === 'scope') return 'scoperow';
    if (row.type === 'compose') return 'sub';

    return '';
}
function toggleClass(row) {
    if (row.type === 'danger') return 'danger';
    if (row.type === 'pii') return 'pii';
    if (row.type === 'compose') return 'compose';

    return '';
}

/* ─── Create role ─── */
const showCreate = ref(false);
const createForm = useForm({ name: '', mode: 'blank', copy_from_id: null });
function openCreate() {
    createForm.reset();
    createForm.clearErrors();
    if (props.copyableRoles.length) createForm.copy_from_id = props.copyableRoles[0].id;
    showCreate.value = true;
}
function submitCreate() {
    createForm.transform((d) => ({
        name: d.name,
        mode: d.mode,
        copy_from_id: d.mode === 'copy' ? d.copy_from_id : null,
    })).post('/settings/roles', {
        preserveScroll: true,
        onSuccess: () => { showCreate.value = false; },
    });
}

/* ─── Rename role ─── */
const showRename = ref(false);
const renameTarget = ref(null);
const renameForm = useForm({ name: '' });
function openRename(role) {
    renameTarget.value = role;
    renameForm.name = role.name;
    renameForm.clearErrors();
    showRename.value = true;
}
function submitRename() {
    if (! renameTarget.value) return;
    renameForm.put(`/settings/roles/${renameTarget.value.id}`, {
        preserveScroll: true,
        onSuccess: () => { showRename.value = false; },
    });
}

/* ─── Delete role ─── */
const showDelete = ref(false);
const deleteTarget = ref(null);
const deleteProcessing = ref(false);
function askDelete(role) {
    deleteTarget.value = role;
    showDelete.value = true;
}
function handleDelete() {
    if (! deleteTarget.value) return;
    deleteProcessing.value = true;
    router.delete(`/settings/roles/${deleteTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deleteProcessing.value = false;
            showDelete.value = false;
            deleteTarget.value = null;
        },
    });
}
</script>

<template>
    <Head title="Roles & permissions" />

    <SettingsLayout title="Roles & permissions" active-section="roles">
        <template #topbar-actions>
            <button type="button" class="btn btn-primary" @click="openCreate">
                <IconPlus :size="15" stroke-width="1.75" />
                Create role
            </button>
        </template>

        <h1 class="set-title">Roles &amp; permissions</h1>
        <p class="set-intro">
            One row per capability, one column per role. Most sections have an <b>Access</b> and a <b>Manage</b>
            toggle; the four scoped sections use a three-way <b>All / Assigned / None</b> scope.
            Changes save immediately.
        </p>

        <section class="card rp-note">
            <div class="card-body rp-note-body">
                <span class="rp-note-ic"><IconCrown :size="18" stroke-width="1.75" /></span>
                <span>{{ superAdminNote }} It has no column here; the matrix governs the configurable roles only.</span>
            </div>
        </section>

        <section class="table-card roles-matrix">
            <div class="pm-toolbar">
                <div class="pm-search">
                    <IconSearch class="pm-search-ic" :size="16" stroke-width="1.75" />
                    <input v-model="search" type="text" placeholder="Search permissions…  (e.g. invoice, delete, scope)">
                </div>
                <div class="pm-filters">
                    <select v-model="areaFilter" class="pm-areasel" aria-label="Filter by area">
                        <option value="">All areas</option>
                        <option v-for="g in groups" :key="g.key" :value="g.key">{{ g.label }}</option>
                    </select>
                    <button
                        type="button"
                        class="pm-fchip"
                        :class="{ active: highStakesOnly }"
                        @click="highStakesOnly = ! highStakesOnly"
                    >
                        <IconAlertTriangle :size="14" stroke-width="1.75" />
                        High-stakes only
                    </button>
                    <button v-if="search || areaFilter || highStakesOnly" type="button" class="pm-fchip" @click="clearFilters">
                        Clear
                    </button>
                </div>
                <p class="pm-toolbar-note">Showing {{ visibleCount }} permission{{ visibleCount === 1 ? '' : 's' }}. Edits persist immediately.</p>
            </div>

            <table class="pm-table">
                <thead>
                    <tr>
                        <th class="pm-head-perm"><span class="h-lbl">Permission</span></th>
                        <th v-for="role in roles" :key="role.id" class="pm-head-role">
                            <div class="rname-row">
                                <span class="rname">{{ role.name }}</span>
                                <Menu v-if="! role.is_system" as="div" class="dd-menu">
                                    <MenuButton class="icon-btn" aria-label="Role actions">
                                        <IconDots :size="15" stroke-width="1.75" />
                                    </MenuButton>
                                    <MenuItems class="dd-popover right-align">
                                        <MenuItem v-slot="{ active }">
                                            <button type="button" :class="['dd-option', { active }]" @click="openRename(role)">Rename</button>
                                        </MenuItem>
                                        <MenuItem v-slot="{ active }">
                                            <button type="button" :class="['dd-option', { active }]" style="color: var(--danger);" @click="askDelete(role)">Delete</button>
                                        </MenuItem>
                                    </MenuItems>
                                </Menu>
                                <span v-else class="rname-lock" title="System role — reconfigurable but not renamable or deletable">●</span>
                            </div>
                            <div class="rcount">{{ role.user_count }} user{{ role.user_count === 1 ? '' : 's' }}</div>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="g in filteredGroups" :key="g.key">
                        <tr class="pm-group">
                            <td :colspan="roles.length + 1">
                                <component :is="GROUP_ICONS[g.icon]" v-if="GROUP_ICONS[g.icon]" class="gi" :size="13" stroke-width="2" />
                                {{ g.label }}
                                <span v-if="g.scoped" class="scoped-flag">Scoped section</span>
                            </td>
                        </tr>
                        <tr v-for="row in g.rows" :key="row.key" class="pm-row" :class="rowClass(row)">
                            <td class="pm-perm">
                                <div class="top">
                                    <span class="pm-name">{{ row.key }}</span>
                                    <span class="pm-tag" :class="row.type">{{ row.label }}</span>
                                </div>
                                <div class="pm-desc">{{ row.desc }}</div>
                            </td>
                            <td v-for="role in roles" :key="role.id" class="pm-cell">
                                <div v-if="row.scope" class="pm-scope" role="radiogroup">
                                    <label v-for="opt in scopeOptions" :key="opt">
                                        <input
                                            type="radio"
                                            :name="`sc-${role.id}-${row.area}`"
                                            :checked="scopeOf(role.id, row.area) === opt"
                                            @change="setScope(role, row.area, opt)"
                                        >
                                        <span :class="SCOPE_SEG[opt]">{{ SCOPE_LABEL[opt] }}</span>
                                    </label>
                                </div>
                                <label v-else class="pm-toggle" :class="toggleClass(row)">
                                    <input
                                        type="checkbox"
                                        :checked="hasPerm(role.id, row.key)"
                                        @change="togglePerm(role, row.key)"
                                    >
                                    <span class="pm-track"><span class="pm-knob" /></span>
                                </label>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="! filteredGroups.length">
                        <td :colspan="roles.length + 1" class="pm-empty">No permissions match the current search / filters.</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <!-- Create role slide-over -->
        <TransitionRoot as="template" :show="showCreate">
            <Dialog as="div" class="slide-over-dialog" @close="showCreate = false">
                <TransitionChild as="template" enter="transition-opacity ease-out duration-200" enter-from="opacity-0" enter-to="opacity-100" leave="transition-opacity ease-in duration-150" leave-from="opacity-100" leave-to="opacity-0">
                    <div class="slide-over-backdrop" />
                </TransitionChild>
                <TransitionChild as="template" enter="transform transition ease-out duration-200" enter-from="translate-x-full" enter-to="translate-x-0" leave="transform transition ease-in duration-150" leave-from="translate-x-0" leave-to="translate-x-full">
                    <DialogPanel class="slide-over-panel">
                        <form class="slide-over-form" @submit.prevent="submitCreate">
                            <header class="slide-over-header">
                                <h2>Create role</h2>
                                <button type="button" class="icon-btn" aria-label="Close" @click="showCreate = false">
                                    <IconX :size="18" stroke-width="1.75" />
                                </button>
                            </header>
                            <div class="slide-over-body">
                                <div class="form-section">
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Role name<span class="req">*</span></label>
                                            <input v-model="createForm.name" type="text" placeholder="e.g. Billing Clerk" :class="{ 'has-err': createForm.errors.name }" required>
                                            <div v-if="createForm.errors.name" class="err">{{ createForm.errors.name }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Starting point</label>
                                            <label class="rp-radio">
                                                <input v-model="createForm.mode" type="radio" value="blank">
                                                <span><b>Start blank</b> — everything off, all four scopes at None.</span>
                                            </label>
                                            <label class="rp-radio">
                                                <input v-model="createForm.mode" type="radio" value="copy">
                                                <span><b>Copy from</b> an existing role — duplicates its complete state (all permissions, all four scopes, view-unassigned) as a one-time seed.</span>
                                            </label>
                                        </div>
                                    </div>
                                    <div v-if="createForm.mode === 'copy'" class="form-row single">
                                        <div class="form-field">
                                            <label>Copy from<span class="req">*</span></label>
                                            <select v-model="createForm.copy_from_id" :class="{ 'has-err': createForm.errors.copy_from_id }">
                                                <option v-for="r in copyableRoles" :key="r.id" :value="r.id">{{ r.name }}</option>
                                            </select>
                                            <div v-if="createForm.errors.copy_from_id" class="err">{{ createForm.errors.copy_from_id }}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <footer class="slide-over-footer">
                                <button type="button" class="btn btn-secondary" @click="showCreate = false">Cancel</button>
                                <button type="submit" class="btn btn-primary" :disabled="createForm.processing">
                                    <IconPlus :size="15" stroke-width="1.75" />
                                    {{ createForm.processing ? 'Creating…' : 'Create role' }}
                                </button>
                            </footer>
                        </form>
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </TransitionRoot>

        <!-- Rename role -->
        <TransitionRoot as="template" :show="showRename">
            <Dialog as="div" class="rp-modal" @close="showRename = false">
                <TransitionChild as="template" enter="transition-opacity ease-out duration-200" enter-from="opacity-0" enter-to="opacity-100" leave="transition-opacity ease-in duration-150" leave-from="opacity-100" leave-to="opacity-0">
                    <div class="rp-modal-backdrop" />
                </TransitionChild>
                <div class="rp-modal-wrap">
                    <TransitionChild as="template" enter="transition ease-out duration-200" enter-from="opacity-0 scale-95" enter-to="opacity-100 scale-100" leave="transition ease-in duration-150" leave-from="opacity-100 scale-100" leave-to="opacity-0 scale-95">
                        <DialogPanel class="rp-modal-panel">
                            <form @submit.prevent="submitRename">
                                <h2 class="rp-modal-title">Rename role</h2>
                                <div class="form-field">
                                    <label>Role name<span class="req">*</span></label>
                                    <input v-model="renameForm.name" type="text" :class="{ 'has-err': renameForm.errors.name }" required>
                                    <div v-if="renameForm.errors.name" class="err">{{ renameForm.errors.name }}</div>
                                </div>
                                <div class="rp-modal-foot">
                                    <button type="button" class="btn btn-secondary" @click="showRename = false">Cancel</button>
                                    <button type="submit" class="btn btn-primary" :disabled="renameForm.processing">{{ renameForm.processing ? 'Saving…' : 'Save' }}</button>
                                </div>
                            </form>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </Dialog>
        </TransitionRoot>

        <ConfirmModal
            v-model:show="showDelete"
            :title="deleteTarget ? `Delete ${deleteTarget.name}?` : 'Delete role?'"
            message="This removes the role and its scope settings. A role with users still assigned can't be deleted — reassign them first."
            confirm-label="Delete role"
            variant="danger"
            :loading="deleteProcessing"
            @confirm="handleDelete"
        />
    </SettingsLayout>
</template>

<style>
/* Roles & permissions matrix — namespaced under .roles-matrix per
   CONVENTIONS.md so none of these rules leak onto shared primitives. */

.rp-note { margin-bottom: 14px; }
.rp-note .rp-note-body { display: flex; align-items: center; gap: 12px; padding: 12px 16px; font: 400 12.5px/1.45 'Inter', sans-serif; color: #92400E; background: var(--warning-bg); border-radius: var(--radius-lg); }
.rp-note .rp-note-ic { width: 32px; height: 32px; flex-shrink: 0; border-radius: 8px; background: rgba(245,158,11,.16); color: #B45309; display: grid; place-items: center; }

.roles-matrix .pm-toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 12px 14px; border-bottom: 1px solid var(--border); }
.roles-matrix .pm-search { position: relative; flex: 1 1 240px; max-width: 380px; }
.roles-matrix .pm-search-ic { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-tertiary); }
.roles-matrix .pm-search input { width: 100%; height: 34px; border: 1px solid var(--border); background: var(--content-bg); border-radius: var(--radius-md); padding: 0 12px 0 32px; font: 400 13px/1 'Inter', sans-serif; color: var(--text-primary); outline: 0; }
.roles-matrix .pm-search input:focus { background: #fff; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(245,158,11,.18); }
.roles-matrix .pm-filters { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.roles-matrix .pm-areasel { height: 34px; border: 1px solid var(--border); border-radius: var(--radius-md); padding: 0 12px; font: 500 12.5px/1 'Inter', sans-serif; color: var(--text-primary); background: #fff; cursor: pointer; outline: 0; }
.roles-matrix .pm-fchip { display: inline-flex; align-items: center; gap: 6px; height: 34px; padding: 0 12px; border: 1px solid var(--border); border-radius: var(--radius-md); background: #fff; font: 500 12.5px/1 'Inter', sans-serif; color: var(--text-secondary); cursor: pointer; transition: background .12s, border-color .12s, color .12s; }
.roles-matrix .pm-fchip:hover { background: var(--neutral-bg); }
.roles-matrix .pm-fchip.active { background: var(--danger-bg); border-color: #FECACA; color: #B91C1C; }
.roles-matrix .pm-toolbar-note { width: 100%; margin: 0; font: 400 11px/1.4 'Inter', sans-serif; color: var(--text-tertiary); }

.roles-matrix .pm-table { width: 100%; border-collapse: collapse; }
.roles-matrix thead th { background: #FBFCFE; border-bottom: 1px solid var(--border); padding: 10px 12px; vertical-align: bottom; }
.roles-matrix .pm-head-perm { text-align: left; width: 38%; }
.roles-matrix .pm-head-perm .h-lbl { font: 500 11px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary); }
.roles-matrix .pm-head-role { text-align: center; border-left: 1px solid var(--border-soft); min-width: 130px; }
.roles-matrix .pm-head-role .rname-row { display: flex; align-items: center; justify-content: center; gap: 4px; }
.roles-matrix .pm-head-role .rname { font: 600 13px/1.1 'Inter', sans-serif; color: var(--text-primary); text-transform: capitalize; }
.roles-matrix .pm-head-role .rname-lock { color: var(--text-tertiary); font-size: 10px; }
.roles-matrix .pm-head-role .rcount { display: inline-block; margin-top: 5px; font: 500 10px/1.2 'Inter', sans-serif; color: var(--text-secondary); background: var(--neutral-bg); padding: 3px 7px; border-radius: 999px; }

.roles-matrix .pm-group td { background: var(--neutral-bg); border-top: 1px solid var(--border); border-bottom: 1px solid var(--border-soft); padding: 7px 12px; font: 600 11px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .1em; color: var(--text-secondary); }
.roles-matrix .pm-group td .gi { vertical-align: -2px; margin-right: 7px; color: var(--text-tertiary); }
.roles-matrix .pm-group td .scoped-flag { float: right; text-transform: none; letter-spacing: 0; font: 600 9.5px/1.4 'Inter', sans-serif; color: #1D4ED8; background: var(--info-bg); border: 1px solid #BFDBFE; border-radius: 5px; padding: 1px 7px; }

.roles-matrix .pm-row { border-bottom: 1px solid var(--border-soft); }
.roles-matrix .pm-row:hover { background: #FBFCFE; }
.roles-matrix .pm-perm { padding: 11px 12px; vertical-align: top; }
.roles-matrix .pm-perm .top { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.roles-matrix .pm-name { font: 500 12.5px/1.2 'JetBrains Mono', monospace; color: var(--text-primary); letter-spacing: -.01em; }
.roles-matrix .pm-desc { font: 400 12px/1.4 'Inter', sans-serif; color: var(--text-secondary); margin-top: 4px; }
.roles-matrix .pm-row.sub .pm-perm { padding-left: 26px; }
.roles-matrix .pm-row.danger { box-shadow: inset 3px 0 0 var(--danger); }
.roles-matrix .pm-row.pii { box-shadow: inset 3px 0 0 var(--purple); }
.roles-matrix .pm-row.scoperow { box-shadow: inset 3px 0 0 var(--info); }

.roles-matrix .pm-tag { display: inline-flex; align-items: center; gap: 4px; padding: 2px 7px; border-radius: 5px; font: 600 9.5px/1.3 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .06em; border: 1px solid transparent; }
.roles-matrix .pm-tag.access { color: #0F766E; background: rgba(13,148,136,.1); border-color: rgba(13,148,136,.25); }
.roles-matrix .pm-tag.scope { color: #1D4ED8; background: var(--info-bg); border-color: #BFDBFE; }
.roles-matrix .pm-tag.compose { color: #0F766E; background: rgba(13,148,136,.1); border-color: rgba(13,148,136,.25); }
.roles-matrix .pm-tag.manage { color: #475569; background: var(--neutral-bg); border-color: var(--border); }
.roles-matrix .pm-tag.danger { color: #B91C1C; background: var(--danger-bg); border-color: #FECACA; }
.roles-matrix .pm-tag.pii { color: #6D28D9; background: rgba(124,58,237,.08); border-color: rgba(124,58,237,.25); }

.roles-matrix .pm-cell { text-align: center; padding: 10px 6px; border-left: 1px solid var(--border-soft); vertical-align: middle; }

.roles-matrix .pm-toggle { position: relative; display: inline-flex; cursor: pointer; }
.roles-matrix .pm-toggle input { position: absolute; opacity: 0; width: 0; height: 0; }
.roles-matrix .pm-track { width: 34px; height: 20px; border-radius: 999px; background: #CBD5E1; position: relative; transition: background .15s; display: inline-block; }
.roles-matrix .pm-knob { position: absolute; top: 2px; left: 2px; width: 16px; height: 16px; border-radius: 50%; background: #fff; box-shadow: 0 1px 2px rgba(15,23,42,.25); transition: left .15s; }
.roles-matrix .pm-toggle input:checked + .pm-track { background: var(--accent); }
.roles-matrix .pm-toggle input:checked + .pm-track .pm-knob { left: 16px; }
.roles-matrix .pm-toggle.danger input:checked + .pm-track { background: var(--danger); }
.roles-matrix .pm-toggle.pii input:checked + .pm-track { background: var(--purple); }
.roles-matrix .pm-toggle.compose input:checked + .pm-track { background: var(--teal); }
.roles-matrix .pm-toggle input:focus-visible + .pm-track { box-shadow: 0 0 0 3px rgba(245,158,11,.3); }

.roles-matrix .pm-scope { display: inline-flex; border: 1px solid var(--border); border-radius: var(--radius-sm); overflow: hidden; background: #fff; box-shadow: var(--shadow-sm); }
.roles-matrix .pm-scope label { position: relative; display: inline-flex; margin: 0; }
.roles-matrix .pm-scope input { position: absolute; opacity: 0; width: 0; height: 0; }
.roles-matrix .pm-scope span { display: block; padding: 4px 7px; font: 600 10px/1.4 'Inter', sans-serif; color: var(--text-secondary); cursor: pointer; white-space: nowrap; border-left: 1px solid var(--border-soft); transition: background .12s, color .12s; }
.roles-matrix .pm-scope label:first-child span { border-left: 0; }
.roles-matrix .pm-scope input:checked + span.s-all { background: var(--accent); color: var(--bg-navy); }
.roles-matrix .pm-scope input:checked + span.s-asgn { background: var(--info); color: #fff; }
.roles-matrix .pm-scope input:checked + span.s-none { background: var(--neutral-bg); color: var(--text-primary); box-shadow: inset 0 0 0 1px var(--border); }

.roles-matrix .pm-empty { padding: 28px; text-align: center; color: var(--text-tertiary); font: 400 13px/1.4 'Inter', sans-serif; }

/* Rename modal */
.rp-modal { position: relative; z-index: 60; }
.rp-modal-backdrop { position: fixed; inset: 0; background: rgba(15,23,42,.45); }
.rp-modal-wrap { position: fixed; inset: 0; display: grid; place-items: center; padding: 20px; }
.rp-modal-panel { width: 100%; max-width: 380px; background: #fff; border-radius: var(--radius-lg); box-shadow: var(--shadow-md); padding: 20px; }
.rp-modal-title { margin: 0 0 14px; font: 600 16px/1.2 'Inter', sans-serif; }
.rp-modal-foot { display: flex; gap: 8px; justify-content: flex-end; margin-top: 18px; }
.rp-radio { display: flex; gap: 8px; align-items: flex-start; padding: 8px 0; font: 400 12.5px/1.45 'Inter', sans-serif; color: var(--text-secondary); cursor: pointer; }
.rp-radio input { margin-top: 2px; }
.rp-radio b { color: var(--text-primary); }
</style>
