<script setup>
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { IconPlus, IconPencil, IconTrash, IconX, IconUsers } from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const props = defineProps({
    groups: { type: Array, default: () => [] },
});

const breadcrumbs = [
    { label: 'Customers', href: '/customers' },
    { label: 'Groups' },
];

// Default chip palette — used when the operator doesn't pick one
// from the colour input. Indexed by group id so each new group
// without a colour still reads as visually distinct.
const FALLBACK_PALETTE = ['#0D9488', '#F59E0B', '#3B82F6', '#10B981', '#7C3AED', '#EF4444', '#06B6D4'];
function fallbackColour(id) {
    return FALLBACK_PALETTE[((id ?? 0) - 1) % FALLBACK_PALETTE.length];
}
function chipStyle(group) {
    const c = group.colour || fallbackColour(group.id);
    return { background: c + '22', color: c, borderColor: c };
}

const showForm = ref(false);
const editingId = ref(null);
const form = useForm({
    name: '',
    description: '',
    colour: '#3B82F6',
});

function openCreate() {
    editingId.value = null;
    form.reset();
    form.colour = '#3B82F6';
    form.clearErrors();
    showForm.value = true;
}

function openEdit(group) {
    editingId.value = group.id;
    form.reset();
    form.name = group.name;
    form.description = group.description ?? '';
    form.colour = group.colour ?? fallbackColour(group.id);
    form.clearErrors();
    showForm.value = true;
}

function submit() {
    const onSuccess = () => {
        showForm.value = false;
        form.reset();
        editingId.value = null;
    };
    if (editingId.value) {
        form.put(`/customer-groups/${editingId.value}`, { preserveScroll: true, onSuccess });
    } else {
        form.post('/customer-groups', { preserveScroll: true, onSuccess });
    }
}

const showDeleteModal = ref(false);
const deleteTarget = ref(null);
const deleteProcessing = ref(false);
function askDelete(group) {
    deleteTarget.value = group;
    showDeleteModal.value = true;
}
function performDelete() {
    if (! deleteTarget.value) return;
    deleteProcessing.value = true;
    router.delete(`/customer-groups/${deleteTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deleteProcessing.value = false;
            showDeleteModal.value = false;
            deleteTarget.value = null;
        },
    });
}
</script>

<template>
    <Head title="Customer groups" />

    <InternalLayout title="Customer groups" :breadcrumbs="breadcrumbs" active-nav="customers">
        <template #topbar-actions>
            <button type="button" class="btn btn-primary" @click="openCreate">
                <IconPlus :size="15" stroke-width="1.75" />
                New group
            </button>
        </template>

        <div class="cg-page">
            <div v-if="groups.length === 0" class="cg-empty">
                <IconUsers :size="40" stroke-width="1.5" />
                <h3>No customer groups yet</h3>
                <p>Use groups to tag customers as VIP, beta testers, or anything else worth filtering by.</p>
                <button type="button" class="btn btn-primary" @click="openCreate">
                    <IconPlus :size="14" stroke-width="1.75" /> Create first group
                </button>
            </div>

            <div v-else class="cg-grid">
                <article v-for="g in groups" :key="g.id" class="cg-card">
                    <header class="cg-card-head">
                        <span class="customer-group-badge" :style="chipStyle(g)">{{ g.name }}</span>
                        <div class="cg-card-actions">
                            <button type="button" class="icon-btn" :aria-label="`Edit ${g.name}`" @click="openEdit(g)">
                                <IconPencil :size="14" stroke-width="1.75" />
                            </button>
                            <button type="button" class="icon-btn" :aria-label="`Delete ${g.name}`" @click="askDelete(g)">
                                <IconTrash :size="14" stroke-width="1.75" />
                            </button>
                        </div>
                    </header>
                    <p v-if="g.description" class="cg-desc">{{ g.description }}</p>
                    <div class="cg-meta">
                        <span><IconUsers :size="12" stroke-width="1.75" /> {{ g.customer_count }} customer{{ g.customer_count === 1 ? '' : 's' }}</span>
                        <span v-if="g.created_by" class="cg-meta-sep">·</span>
                        <span v-if="g.created_by">by {{ g.created_by }}</span>
                    </div>
                </article>
            </div>
        </div>

        <!-- Create / edit slide-over -->
        <Teleport to="body">
            <transition name="slide-over">
                <div v-if="showForm" class="slide-over">
                    <div class="slide-over-backdrop" @click="showForm = false" />
                    <aside class="slide-over-panel" style="width: 420px;" role="dialog" aria-modal="true">
                        <form class="slide-over-form" @submit.prevent="submit">
                            <header class="slide-over-header">
                                <h2>{{ editingId ? 'Edit group' : 'New group' }}</h2>
                                <button type="button" class="icon-btn" aria-label="Close" @click="showForm = false">
                                    <IconX :size="18" stroke-width="1.75" />
                                </button>
                            </header>
                            <div class="slide-over-body">
                                <div class="form-section">
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Name<span class="req">*</span></label>
                                            <input v-model="form.name" type="text" required maxlength="100" :class="{ 'has-err': form.errors.name }">
                                            <div v-if="form.errors.name" class="err">{{ form.errors.name }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Description</label>
                                            <textarea v-model="form.description" rows="3" maxlength="500" />
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Colour</label>
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <input v-model="form.colour" type="color" style="width: 48px; height: 32px; padding: 0; border: 1px solid var(--border); border-radius: var(--radius-md);">
                                                <input v-model="form.colour" type="text" maxlength="7" placeholder="#3B82F6" style="flex: 1;">
                                                <span
                                                    class="customer-group-badge"
                                                    :style="{ background: form.colour + '22', color: form.colour, borderColor: form.colour }"
                                                >Preview</span>
                                            </div>
                                            <div v-if="form.errors.colour" class="err">{{ form.errors.colour }}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <footer class="slide-over-footer">
                                <button type="button" class="btn btn-secondary" @click="showForm = false">Cancel</button>
                                <button type="submit" class="btn btn-primary" :disabled="form.processing">
                                    {{ form.processing ? 'Saving…' : (editingId ? 'Save changes' : 'Create group') }}
                                </button>
                            </footer>
                        </form>
                    </aside>
                </div>
            </transition>
        </Teleport>

        <ConfirmModal
            v-model:show="showDeleteModal"
            :title="deleteTarget ? `Delete '${deleteTarget.name}'?` : 'Delete group?'"
            :message="deleteTarget ? `${deleteTarget.customer_count} customer${deleteTarget.customer_count === 1 ? '' : 's'} will be detached from this group. The customers themselves stay.` : ''"
            confirm-label="Delete"
            variant="danger"
            :loading="deleteProcessing"
            @confirm="performDelete"
        />
    </InternalLayout>
</template>
