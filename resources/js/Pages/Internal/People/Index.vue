<script setup>
import { ref, watch } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { IconPlus, IconX, IconUser, IconSearch, IconChevronLeft, IconChevronRight, IconBuildingStore } from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';

const props = defineProps({
    people: { type: Object, default: () => ({ data: [] }) },
    filters: { type: Object, default: () => ({}) },
});

const breadcrumbs = [{ label: 'People' }];

/* ─── Search ─── */
const searchInput = ref(props.filters?.search ?? '');
let searchTimer = null;
watch(searchInput, (v) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        if ((v ?? '') === (props.filters?.search ?? '')) return;
        const q = {};
        if (v) q.search = v;
        router.get('/people', q, { preserveState: true, preserveScroll: true, replace: true });
    }, 350);
});

/* ─── Pagination ─── */
function go(url) { if (url) router.visit(url, { preserveScroll: true }); }

/* ─── Create person ─── */
const showForm = ref(false);
const form = useForm({ name: '', email: '', phone: '', notes: '' });

function openCreate() {
    form.reset();
    form.clearErrors();
    showForm.value = true;
}
function submit() {
    // On success the controller redirects to the new person's page.
    form.post('/people', { preserveScroll: true });
}
</script>

<template>
    <Head title="People" />

    <InternalLayout title="People" :breadcrumbs="breadcrumbs" active-nav="people">
        <template #topbar-actions>
            <button type="button" class="btn btn-primary" @click="openCreate">
                <IconPlus :size="15" stroke-width="1.75" />
                New person
            </button>
        </template>

        <div class="people-page">
            <div class="filter-bar">
                <div class="field-search">
                    <span class="search-icon"><IconSearch :size="16" stroke-width="1.75" /></span>
                    <input v-model="searchInput" type="search" placeholder="Search name or email…">
                </div>
            </div>

            <div class="table-card">
                <table v-if="people.data.length" class="tbl">
                    <colgroup>
                        <col>
                        <col style="width: 280px;">
                        <col style="width: 160px;">
                        <col style="width: 130px;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Companies</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in people.data" :key="p.id">
                            <td>
                                <Link :href="`/people/${p.id}`" class="ppl-name">{{ p.name }}</Link>
                            </td>
                            <td><span :class="{ muted: ! p.email }">{{ p.email ?? '—' }}</span></td>
                            <td><span :class="{ muted: ! p.phone }">{{ p.phone ?? '—' }}</span></td>
                            <td>
                                <span class="badge badge-sm">
                                    <IconBuildingStore :size="12" stroke-width="1.75" />
                                    {{ p.company_count }} compan{{ p.company_count === 1 ? 'y' : 'ies' }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div v-else class="ppl-empty">
                    <IconUser :size="40" stroke-width="1.5" />
                    <h3>No people yet</h3>
                    <p>People are cross-company humans — an owner who runs several companies is one person linked to each.</p>
                    <button type="button" class="btn btn-primary" @click="openCreate">
                        <IconPlus :size="14" stroke-width="1.75" /> Add first person
                    </button>
                </div>

                <div v-if="people.data.length" class="tbl-foot">
                    <div class="info">Showing <strong>{{ people.from ?? 0 }} – {{ people.to ?? 0 }}</strong> of <strong>{{ people.total }}</strong></div>
                    <div class="right">
                        <button type="button" class="pg-btn" :disabled="! people.prev_page_url" @click="go(people.prev_page_url)">
                            <IconChevronLeft :size="14" stroke-width="1.75" /> Previous
                        </button>
                        <button type="button" class="pg-btn" :disabled="! people.next_page_url" @click="go(people.next_page_url)">
                            Next <IconChevronRight :size="14" stroke-width="1.75" />
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create slide-over -->
        <Teleport to="body">
            <transition name="slide-over">
                <div v-if="showForm" class="slide-over">
                    <div class="slide-over-backdrop" @click="showForm = false" />
                    <aside class="slide-over-panel" style="width: 420px;" role="dialog" aria-modal="true">
                        <form class="slide-over-form" @submit.prevent="submit">
                            <header class="slide-over-header">
                                <h2>New person</h2>
                                <button type="button" class="icon-btn" aria-label="Close" @click="showForm = false">
                                    <IconX :size="18" stroke-width="1.75" />
                                </button>
                            </header>
                            <div class="slide-over-body">
                                <div class="form-section">
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Name<span class="req">*</span></label>
                                            <input v-model="form.name" type="text" required maxlength="255" :class="{ 'has-err': form.errors.name }">
                                            <div v-if="form.errors.name" class="err">{{ form.errors.name }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Email</label>
                                            <input v-model="form.email" type="email" maxlength="255" :class="{ 'has-err': form.errors.email }">
                                            <div v-if="form.errors.email" class="err">{{ form.errors.email }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Phone</label>
                                            <input v-model="form.phone" type="text" maxlength="50">
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Notes</label>
                                            <textarea v-model="form.notes" rows="3" maxlength="2000" />
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <footer class="slide-over-footer">
                                <button type="button" class="btn btn-secondary" @click="showForm = false">Cancel</button>
                                <button type="submit" class="btn btn-primary" :disabled="form.processing">
                                    {{ form.processing ? 'Saving…' : 'Create person' }}
                                </button>
                            </footer>
                        </form>
                    </aside>
                </div>
            </transition>
        </Teleport>
    </InternalLayout>
</template>

<style scoped>
.people-page { display: flex; flex-direction: column; gap: 16px; }
.ppl-name { font-weight: 600; color: var(--text); }
.ppl-name:hover { color: var(--accent); }
.ppl-empty {
    display: flex; flex-direction: column; align-items: center; gap: 10px;
    padding: 56px 24px; text-align: center; color: var(--text-muted);
}
.ppl-empty h3 { margin: 4px 0 0; color: var(--text); }
.ppl-empty p { max-width: 420px; margin: 0 0 8px; }
</style>
