<script setup>
import { ref, watch } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import {
    IconPencil, IconTrash, IconX, IconPlus, IconBuildingStore,
    IconMail, IconPhone, IconSearch, IconLink, IconUnlink,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const props = defineProps({
    person: { type: Object, required: true },
    roleOptions: { type: Array, default: () => [] },
});

const breadcrumbs = [
    { label: 'People', href: '/people' },
    { label: props.person.name },
];

/* ─── Edit person ─── */
const showEdit = ref(false);
const editForm = useForm({
    name: props.person.name,
    email: props.person.email ?? '',
    phone: props.person.phone ?? '',
    notes: props.person.notes ?? '',
});
function openEdit() {
    editForm.name = props.person.name;
    editForm.email = props.person.email ?? '';
    editForm.phone = props.person.phone ?? '';
    editForm.notes = props.person.notes ?? '';
    editForm.clearErrors();
    showEdit.value = true;
}
function submitEdit() {
    editForm.put(`/people/${props.person.id}`, {
        preserveScroll: true,
        onSuccess: () => { showEdit.value = false; },
    });
}

/* ─── Delete person ─── */
const showDelete = ref(false);
const deleting = ref(false);
function performDelete() {
    deleting.value = true;
    router.delete(`/people/${props.person.id}`, {
        onFinish: () => { deleting.value = false; showDelete.value = false; },
    });
}

/* ─── Change role on an existing company link ─── */
function changeRole(company, role) {
    if (role === company.role) return;
    router.put(`/people/${props.person.id}/companies/${company.id}`,
        { customer_id: company.id, role, job_title: company.job_title ?? null },
        { preserveScroll: true });
}

/* ─── Detach a company ─── */
const detachTarget = ref(null);
const detaching = ref(false);
function askDetach(company) { detachTarget.value = company; }
function performDetach() {
    if (! detachTarget.value) return;
    detaching.value = true;
    router.delete(`/people/${props.person.id}/companies/${detachTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => { detaching.value = false; detachTarget.value = null; },
    });
}

/* ─── Attach a company ─── */
const showAttach = ref(false);
const attachForm = useForm({ customer_id: null, role: 'owner', job_title: '' });
const custQuery = ref('');
const custResults = ref([]);
const custSearching = ref(false);
const selectedCust = ref(null);
let custTimer = null;
// Set when we populate the query from a picked result, so the watch below
// doesn't immediately clear the selection + re-search.
let skipCustSearch = false;

watch(custQuery, (v) => {
    if (skipCustSearch) { skipCustSearch = false; return; }
    selectedCust.value = null;
    attachForm.customer_id = null;
    clearTimeout(custTimer);
    if (! v || v.length < 2) { custResults.value = []; return; }
    custTimer = setTimeout(async () => {
        custSearching.value = true;
        try {
            const res = await fetch(`/search?q=${encodeURIComponent(v)}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await res.json();
            // The global search returns mixed rows; keep only customers
            // and pull the id out of the /companies/{id} url.
            custResults.value = (data.results ?? [])
                .filter((r) => r.type === 'customer')
                .map((r) => ({ id: Number(r.url.split('/').pop()), name: r.title, sub: r.sub }));
        } catch (e) {
            custResults.value = [];
        } finally {
            custSearching.value = false;
        }
    }, 300);
});

function pickCust(c) {
    selectedCust.value = c;
    attachForm.customer_id = c.id;
    custResults.value = [];
    skipCustSearch = true;
    custQuery.value = c.name;
}
function openAttach() {
    attachForm.reset();
    attachForm.role = 'owner';
    attachForm.clearErrors();
    custQuery.value = '';
    custResults.value = [];
    selectedCust.value = null;
    showAttach.value = true;
}
function submitAttach() {
    attachForm.post(`/people/${props.person.id}/companies`, {
        preserveScroll: true,
        onSuccess: () => { showAttach.value = false; },
    });
}

function roleChipStyle(role) {
    const palette = {
        owner: '#0D9488', director: '#7C3AED', shareholder: '#3B82F6',
        partner: '#06B6D4', manager: '#F59E0B', accounts: '#10B981',
        signatory: '#EF4444', other: '#64748B',
    };
    const c = palette[role] ?? '#64748B';
    return { background: c + '22', color: c, borderColor: c };
}
</script>

<template>
    <Head :title="person.name" />

    <InternalLayout :title="person.name" :breadcrumbs="breadcrumbs" active-nav="people">
        <template #topbar-actions>
            <button type="button" class="btn btn-secondary" @click="openEdit">
                <IconPencil :size="15" stroke-width="1.75" /> Edit
            </button>
            <button type="button" class="btn btn-danger" @click="showDelete = true">
                <IconTrash :size="15" stroke-width="1.75" /> Delete
            </button>
        </template>

        <div class="person-show">
            <!-- Detail card -->
            <section class="card">
                <div class="card-head">
                    <div class="card-header"><IconBuildingStore :size="16" stroke-width="1.75" /> Details</div>
                </div>
                <div class="card-body">
                    <dl class="detail-grid">
                        <div>
                            <dt><IconMail :size="13" stroke-width="1.75" /> Email</dt>
                            <dd :class="{ muted: ! person.email }">{{ person.email ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt><IconPhone :size="13" stroke-width="1.75" /> Phone</dt>
                            <dd :class="{ muted: ! person.phone }">{{ person.phone ?? '—' }}</dd>
                        </div>
                        <div class="full">
                            <dt>Notes</dt>
                            <dd :class="{ muted: ! person.notes }">{{ person.notes ?? 'No notes.' }}</dd>
                        </div>
                    </dl>
                    <p v-if="person.created_by" class="meta-line">Added by {{ person.created_by }}</p>
                </div>
            </section>

            <!-- Companies card -->
            <section class="card">
                <div class="card-head">
                    <div class="card-header"><IconBuildingStore :size="16" stroke-width="1.75" /> Companies</div>
                    <button type="button" class="btn btn-sm btn-primary" @click="openAttach">
                        <IconPlus :size="14" stroke-width="1.75" /> Link company
                    </button>
                </div>
                <div class="card-body">
                    <div v-if="person.companies.length === 0" class="ppl-empty-inline">
                        <IconLink :size="28" stroke-width="1.5" />
                        <p>Not linked to any companies yet.</p>
                    </div>

                    <ul v-else class="company-list">
                        <li v-for="c in person.companies" :key="c.id" class="company-row">
                            <div class="company-main">
                                <Link :href="`/companies/${c.id}`" class="company-name">{{ c.name }}</Link>
                                <span v-if="c.job_title" class="company-title">{{ c.job_title }}</span>
                            </div>
                            <span class="badge badge-sm role-chip" :style="roleChipStyle(c.role)">{{ c.role_label }}</span>
                            <select class="role-select" :value="c.role" @change="changeRole(c, $event.target.value)">
                                <option v-for="o in roleOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </select>
                            <button type="button" class="icon-btn" :aria-label="`Unlink ${c.name}`" @click="askDetach(c)">
                                <IconUnlink :size="15" stroke-width="1.75" />
                            </button>
                        </li>
                    </ul>
                </div>
            </section>
        </div>

        <!-- Edit slide-over -->
        <Teleport to="body">
            <transition name="slide-over">
                <div v-if="showEdit" class="slide-over">
                    <div class="slide-over-backdrop" @click="showEdit = false" />
                    <aside class="slide-over-panel" style="width: 420px;" role="dialog" aria-modal="true">
                        <form class="slide-over-form" @submit.prevent="submitEdit">
                            <header class="slide-over-header">
                                <h2>Edit person</h2>
                                <button type="button" class="icon-btn" aria-label="Close" @click="showEdit = false">
                                    <IconX :size="18" stroke-width="1.75" />
                                </button>
                            </header>
                            <div class="slide-over-body">
                                <div class="form-section">
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Name<span class="req">*</span></label>
                                            <input v-model="editForm.name" type="text" required maxlength="255" :class="{ 'has-err': editForm.errors.name }">
                                            <div v-if="editForm.errors.name" class="err">{{ editForm.errors.name }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Email</label>
                                            <input v-model="editForm.email" type="email" maxlength="255" :class="{ 'has-err': editForm.errors.email }">
                                            <div v-if="editForm.errors.email" class="err">{{ editForm.errors.email }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Phone</label>
                                            <input v-model="editForm.phone" type="text" maxlength="50">
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Notes</label>
                                            <textarea v-model="editForm.notes" rows="3" maxlength="2000" />
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <footer class="slide-over-footer">
                                <button type="button" class="btn btn-secondary" @click="showEdit = false">Cancel</button>
                                <button type="submit" class="btn btn-primary" :disabled="editForm.processing">
                                    {{ editForm.processing ? 'Saving…' : 'Save changes' }}
                                </button>
                            </footer>
                        </form>
                    </aside>
                </div>
            </transition>
        </Teleport>

        <!-- Attach company slide-over -->
        <Teleport to="body">
            <transition name="slide-over">
                <div v-if="showAttach" class="slide-over">
                    <div class="slide-over-backdrop" @click="showAttach = false" />
                    <aside class="slide-over-panel" style="width: 420px;" role="dialog" aria-modal="true">
                        <form class="slide-over-form" @submit.prevent="submitAttach">
                            <header class="slide-over-header">
                                <h2>Link a company</h2>
                                <button type="button" class="icon-btn" aria-label="Close" @click="showAttach = false">
                                    <IconX :size="18" stroke-width="1.75" />
                                </button>
                            </header>
                            <div class="slide-over-body">
                                <div class="form-section">
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Company<span class="req">*</span></label>
                                            <div class="field-search">
                                                <span class="search-icon"><IconSearch :size="15" stroke-width="1.75" /></span>
                                                <input v-model="custQuery" type="search" placeholder="Search companies…" autocomplete="off">
                                            </div>
                                            <ul v-if="custResults.length" class="cust-results">
                                                <li v-for="c in custResults" :key="c.id">
                                                    <button type="button" @click="pickCust(c)">
                                                        <strong>{{ c.name }}</strong><span class="muted small">{{ c.sub }}</span>
                                                    </button>
                                                </li>
                                            </ul>
                                            <p v-else-if="custSearching" class="muted small">Searching…</p>
                                            <p v-if="selectedCust" class="cust-chosen">Selected: <strong>{{ selectedCust.name }}</strong></p>
                                            <div v-if="attachForm.errors.customer_id" class="err">{{ attachForm.errors.customer_id }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Role<span class="req">*</span></label>
                                            <select v-model="attachForm.role">
                                                <option v-for="o in roleOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                                            </select>
                                            <div v-if="attachForm.errors.role" class="err">{{ attachForm.errors.role }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Job title</label>
                                            <input v-model="attachForm.job_title" type="text" maxlength="100" placeholder="e.g. Managing Director">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <footer class="slide-over-footer">
                                <button type="button" class="btn btn-secondary" @click="showAttach = false">Cancel</button>
                                <button type="submit" class="btn btn-primary" :disabled="attachForm.processing || ! attachForm.customer_id">
                                    {{ attachForm.processing ? 'Linking…' : 'Link company' }}
                                </button>
                            </footer>
                        </form>
                    </aside>
                </div>
            </transition>
        </Teleport>

        <ConfirmModal
            v-model:show="showDelete"
            :title="`Delete '${person.name}'?`"
            message="The person and all their company links are removed. Linked contacts stay but lose the person link. This cannot be undone."
            confirm-label="Delete"
            variant="danger"
            :loading="deleting"
            @confirm="performDelete"
        />

        <ConfirmModal
            :show="!! detachTarget"
            :title="detachTarget ? `Unlink ${detachTarget.name}?` : 'Unlink company?'"
            message="The company association is removed. The company and the person both stay."
            confirm-label="Unlink"
            variant="warning"
            :loading="detaching"
            @confirm="performDetach"
            @update:show="(v) => { if (! v) detachTarget = null; }"
        />
    </InternalLayout>
</template>

<style scoped>
.person-show { display: flex; flex-direction: column; gap: 16px; max-width: 760px; }
.detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px 24px; margin: 0; }
.detail-grid .full { grid-column: 1 / -1; }
.detail-grid dt {
    display: flex; align-items: center; gap: 6px;
    font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em;
    color: var(--text-muted); margin-bottom: 4px;
}
.detail-grid dd { margin: 0; color: var(--text); }
.meta-line { margin: 16px 0 0; font-size: 0.8rem; color: var(--text-muted); }
.company-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; }
.company-row {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 0; border-bottom: 1px solid var(--border-soft);
}
.company-row:last-child { border-bottom: 0; }
.company-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.company-name { font-weight: 600; color: var(--text); }
.company-name:hover { color: var(--accent); }
.company-title { font-size: 0.8rem; color: var(--text-muted); }
.role-chip { border: 1px solid; }
.role-select { max-width: 150px; }
.ppl-empty-inline {
    display: flex; flex-direction: column; align-items: center; gap: 8px;
    padding: 28px; color: var(--text-muted); text-align: center;
}
.ppl-empty-inline p { margin: 0; }
.cust-results {
    list-style: none; margin: 6px 0 0; padding: 4px;
    border: 1px solid var(--border); border-radius: var(--radius-md);
    background: var(--surface); max-height: 220px; overflow-y: auto;
}
.cust-results li button {
    display: flex; flex-direction: column; gap: 2px; width: 100%;
    padding: 8px 10px; text-align: left; background: none; border: 0;
    border-radius: var(--radius-sm); cursor: pointer; color: var(--text);
}
.cust-results li button:hover { background: var(--surface-hover); }
.cust-chosen { margin: 8px 0 0; font-size: 0.85rem; color: var(--text); }
</style>
