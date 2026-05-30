<script setup>
/**
 * Workflows — automation rules.
 *
 * Each workflow has one trigger + an ordered list of actions.
 * The editor is a single slide-over where the operator picks
 * the trigger type (which determines the trigger_config fields)
 * and stacks actions whose config inputs vary with type.
 *
 * Form/staff pickers are pre-fetched server-side so action
 * configs can reference them as dropdowns rather than
 * free-text IDs.
 */
import { ref, computed, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    IconPlus, IconX, IconBolt, IconDots, IconTrash, IconEdit,
    IconChevronUp, IconChevronDown, IconCheck,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const props = defineProps({
    workflows: { type: Array, required: true },
    forms: { type: Array, default: () => [] },
    staff: { type: Array, default: () => [] },
    trigger_types: { type: Array, required: true },
    action_types: { type: Array, required: true },
});

const TRIGGER_LABEL = {
    form_submitted: 'Form submitted',
    webhook_received: 'Webhook received',
    lead_created: 'Lead created',
    lead_status_changed: 'Lead status changed',
    manual: 'Manual',
};
const ACTION_LABEL = {
    create_lead: 'Create lead',
    update_lead_status: 'Update lead status',
    create_task: 'Create task',
    assign_to_user: 'Assign to user',
    add_note: 'Add note',
    send_notification: 'Send notification',
    add_to_group: 'Add to group',
    webhook_outbound: 'Outbound webhook',
};
const LEAD_STATUSES = ['new', 'contacted', 'qualified', 'proposal', 'negotiation', 'won', 'lost', 'unresponsive'];
const LEAD_SOURCES = ['manual', 'landing_page', 'facebook', 'google', 'referral', 'email', 'phone', 'event', 'word_of_mouth', 'other'];
const TASK_TYPES = ['task', 'call', 'email', 'meeting', 'note'];
const TASK_PRIORITIES = ['low', 'medium', 'high'];

// Mustache strings live in JS constants to keep them out of the
// template where Vue would try to interpolate them.
const taskTitlePlaceholder = 'Follow up with {{first_name}}';
const noteContentPlaceholder = 'Lead from {{source}}: {{message}}';
const notificationPlaceholder = 'New lead: {{first_name}} from {{source}}';

/* ─── Row menu ─── */
const openMenu = ref(null);
function toggleMenu(id) { openMenu.value = openMenu.value === id ? null : id; }

/* ─── Delete confirm ─── */
const confirmDelete = ref(false);
const deleteTarget = ref(null);
function askDelete(w) { deleteTarget.value = w; confirmDelete.value = true; }
function doDelete() {
    if (!deleteTarget.value) return;
    router.delete(`/workflows/${deleteTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => { confirmDelete.value = false; deleteTarget.value = null; },
    });
}

/* ─── Toggle on/off ─── */
function toggle(w) {
    fetch(`/workflows/${w.id}/toggle`, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            'X-Requested-With': 'XMLHttpRequest',
        },
    }).then(() => router.reload({ only: ['workflows'] }));
}

/* ─── Editor slide-over ─── */
const editorOpen = ref(false);
const editingId = ref(null);
const editor = useForm(emptyEditor());

function emptyEditor() {
    return {
        name: '',
        description: '',
        is_active: true,
        trigger_type: 'form_submitted',
        trigger_config: {},
        actions: [],
    };
}

function openCreate() {
    editingId.value = null;
    editor.clearErrors();
    Object.assign(editor, emptyEditor());
    editorOpen.value = true;
}
function openEdit(w) {
    editingId.value = w.id;
    editor.clearErrors();
    Object.assign(editor, {
        name: w.name,
        description: w.description || '',
        is_active: w.is_active,
        trigger_type: w.trigger_type,
        trigger_config: { ...(w.trigger_config || {}) },
        actions: w.actions.map(a => ({
            action_type: a.action_type,
            config: { ...(a.config || {}) },
        })),
    });
    editorOpen.value = true;
    openMenu.value = null;
}

function addAction() {
    editor.actions.push({ action_type: 'create_lead', config: {} });
}
function removeAction(i) { editor.actions.splice(i, 1); }
function moveAction(i, delta) {
    const j = i + delta;
    if (j < 0 || j >= editor.actions.length) return;
    const [item] = editor.actions.splice(i, 1);
    editor.actions.splice(j, 0, item);
}

function save() {
    const endpoint = editingId.value ? `/workflows/${editingId.value}` : '/workflows';
    const method = editingId.value ? 'put' : 'post';
    editor[method](endpoint, {
        preserveScroll: true,
        onSuccess: () => { editorOpen.value = false; },
    });
}

function fmtRelative(iso) {
    if (!iso) return 'Never';
    const d = new Date(iso);
    const diff = (Date.now() - d.getTime()) / 1000;
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    return Math.floor(diff / 86400) + 'd ago';
}
</script>

<template>
    <InternalLayout title="Workflows" active-nav="workflows">
        <Head title="Workflows" />

        <div class="workflows-index page-shell">
            <div class="page-head">
                <div>
                    <h1>Workflows</h1>
                    <p class="muted">Automation rules that fire on triggers like form submissions.</p>
                </div>
                <button type="button" class="btn btn-primary" @click="openCreate">
                    <IconPlus :size="16" stroke-width="2" /> New workflow
                </button>
            </div>

            <div v-if="workflows.length === 0" class="empty-card">
                <IconBolt :size="40" stroke-width="1.4" />
                <h3>No workflows yet</h3>
                <p class="muted">Create one to turn form submissions into leads automatically.</p>
                <button type="button" class="btn btn-primary" @click="openCreate">
                    <IconPlus :size="16" stroke-width="2" /> New workflow
                </button>
            </div>

            <div v-else class="workflows-list">
                <article v-for="w in workflows" :key="w.id" class="workflow-card">
                    <header class="workflow-card-head">
                        <div class="workflow-card-title">
                            <label class="switch">
                                <input type="checkbox" :checked="w.is_active" @change="toggle(w)" />
                                <span class="slider"></span>
                            </label>
                            <h3>{{ w.name }}</h3>
                            <span class="trigger-chip">{{ TRIGGER_LABEL[w.trigger_type] || w.trigger_type }}</span>
                        </div>
                        <div class="workflow-card-actions">
                            <div class="menu-wrap">
                                <button type="button" class="icon-btn" @click="toggleMenu(w.id)">
                                    <IconDots :size="16" stroke-width="2" />
                                </button>
                                <div v-if="openMenu === w.id" class="row-menu">
                                    <button type="button" @click="openEdit(w)">
                                        <IconEdit :size="14" stroke-width="2" /> Edit
                                    </button>
                                    <button type="button" class="danger" @click="askDelete(w)">
                                        <IconTrash :size="14" stroke-width="2" /> Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                    </header>

                    <p v-if="w.description" class="workflow-card-desc muted">{{ w.description }}</p>

                    <div class="workflow-card-meta muted small">
                        {{ w.actions_count }} action{{ w.actions_count === 1 ? '' : 's' }}
                        · {{ w.run_count }} run{{ w.run_count === 1 ? '' : 's' }}
                        · last run {{ fmtRelative(w.last_run_at) }}
                    </div>

                    <div v-if="w.actions.length" class="workflow-actions-preview">
                        <span v-for="a in w.actions" :key="a.id" class="action-chip">
                            {{ ACTION_LABEL[a.action_type] || a.action_type }}
                        </span>
                    </div>
                </article>
            </div>
        </div>

        <!-- Editor slide-over -->
        <Teleport to="body">
            <div v-if="editorOpen" class="modal-overlay" @click.self="editorOpen = false">
                <aside class="slide-over slide-over-wide" role="dialog">
                    <header class="slide-over-head">
                        <h2>{{ editingId ? 'Edit workflow' : 'New workflow' }}</h2>
                        <button type="button" class="icon-btn" @click="editorOpen = false">
                            <IconX :size="18" stroke-width="2" />
                        </button>
                    </header>

                    <form class="slide-over-body" @submit.prevent="save">
                        <section class="form-section">
                            <h3 class="form-section-title">Basics</h3>
                            <div class="form-row">
                                <label>Name <span class="req">*</span></label>
                                <input v-model="editor.name" type="text" maxlength="255" required />
                                <div v-if="editor.errors.name" class="err">{{ editor.errors.name }}</div>
                            </div>
                            <div class="form-row">
                                <label>Description</label>
                                <textarea v-model="editor.description" rows="2" maxlength="2000"></textarea>
                            </div>
                            <label class="checkbox-inline">
                                <input v-model="editor.is_active" type="checkbox" /> Active
                            </label>
                        </section>

                        <section class="form-section">
                            <h3 class="form-section-title">Trigger</h3>
                            <div class="form-row">
                                <label>When this happens</label>
                                <select v-model="editor.trigger_type" @change="editor.trigger_config = {}">
                                    <option v-for="t in trigger_types" :key="t" :value="t">{{ TRIGGER_LABEL[t] || t }}</option>
                                </select>
                            </div>

                            <div v-if="editor.trigger_type === 'form_submitted'" class="form-row">
                                <label>From form (optional — leave blank to fire on any form)</label>
                                <select v-model.number="editor.trigger_config.form_id">
                                    <option :value="null">Any form</option>
                                    <option v-for="f in forms" :key="f.id" :value="f.id">{{ f.name }} (/{{ f.slug }})</option>
                                </select>
                            </div>

                            <div v-if="editor.trigger_type === 'lead_status_changed'" class="form-row">
                                <label>When status becomes</label>
                                <select v-model="editor.trigger_config.to">
                                    <option :value="null">Any status</option>
                                    <option v-for="s in LEAD_STATUSES" :key="s" :value="s">{{ s }}</option>
                                </select>
                            </div>

                            <div v-if="editor.trigger_type === 'webhook_received'" class="form-row">
                                <label>Source (optional)</label>
                                <input v-model="editor.trigger_config.source" type="text" placeholder="e.g. mailchimp" />
                            </div>
                        </section>

                        <section class="form-section">
                            <div class="form-section-head">
                                <h3 class="form-section-title">Actions</h3>
                                <button type="button" class="btn btn-ghost btn-sm" @click="addAction">
                                    <IconPlus :size="14" stroke-width="2" /> Add action
                                </button>
                            </div>
                            <div v-if="editor.actions.length === 0" class="muted small">
                                No actions yet. A workflow without actions is a no-op.
                            </div>

                            <div v-for="(action, i) in editor.actions" :key="i" class="action-builder-row">
                                <div class="action-builder-handle">
                                    <button type="button" class="icon-btn" :disabled="i === 0" @click="moveAction(i, -1)">
                                        <IconChevronUp :size="14" stroke-width="2" />
                                    </button>
                                    <button type="button" class="icon-btn" :disabled="i === editor.actions.length - 1" @click="moveAction(i, 1)">
                                        <IconChevronDown :size="14" stroke-width="2" />
                                    </button>
                                </div>

                                <div class="action-builder-body">
                                    <div class="form-row">
                                        <label class="small">Action type</label>
                                        <select v-model="action.action_type" @change="action.config = {}">
                                            <option v-for="t in action_types" :key="t" :value="t">{{ ACTION_LABEL[t] || t }}</option>
                                        </select>
                                    </div>

                                    <!-- create_lead config -->
                                    <template v-if="action.action_type === 'create_lead'">
                                        <div class="grid-2">
                                            <div class="form-row">
                                                <label class="small">First name field</label>
                                                <input v-model="action.config.first_name_field" type="text" placeholder="first_name" />
                                            </div>
                                            <div class="form-row">
                                                <label class="small">Last name field</label>
                                                <input v-model="action.config.last_name_field" type="text" placeholder="last_name" />
                                            </div>
                                            <div class="form-row">
                                                <label class="small">Email field</label>
                                                <input v-model="action.config.email_field" type="text" placeholder="email" />
                                            </div>
                                            <div class="form-row">
                                                <label class="small">Phone field</label>
                                                <input v-model="action.config.phone_field" type="text" placeholder="phone" />
                                            </div>
                                            <div class="form-row">
                                                <label class="small">Company field</label>
                                                <input v-model="action.config.company_field" type="text" placeholder="company" />
                                            </div>
                                            <div class="form-row">
                                                <label class="small">Source</label>
                                                <select v-model="action.config.source">
                                                    <option v-for="s in LEAD_SOURCES" :key="s" :value="s">{{ s }}</option>
                                                </select>
                                            </div>
                                            <div class="form-row">
                                                <label class="small">Status</label>
                                                <select v-model="action.config.status">
                                                    <option v-for="s in LEAD_STATUSES" :key="s" :value="s">{{ s }}</option>
                                                </select>
                                            </div>
                                            <div class="form-row">
                                                <label class="small">Assigned to</label>
                                                <select v-model.number="action.config.assigned_to">
                                                    <option :value="null">Unassigned</option>
                                                    <option v-for="u in staff" :key="u.id" :value="u.id">{{ u.name }}</option>
                                                </select>
                                            </div>
                                        </div>
                                    </template>

                                    <!-- create_task config -->
                                    <template v-if="action.action_type === 'create_task'">
                                        <div class="form-row">
                                            <label class="small">Title template</label>
                                            <input v-model="action.config.title_template" type="text" :placeholder="taskTitlePlaceholder" />
                                            <small class="muted">Use <code>&#123;&#123;field_key&#125;&#125;</code> placeholders to splice in submitted values.</small>
                                        </div>
                                        <div class="grid-3">
                                            <div class="form-row">
                                                <label class="small">Type</label>
                                                <select v-model="action.config.type">
                                                    <option v-for="t in TASK_TYPES" :key="t" :value="t">{{ t }}</option>
                                                </select>
                                            </div>
                                            <div class="form-row">
                                                <label class="small">Priority</label>
                                                <select v-model="action.config.priority">
                                                    <option v-for="p in TASK_PRIORITIES" :key="p" :value="p">{{ p }}</option>
                                                </select>
                                            </div>
                                            <div class="form-row">
                                                <label class="small">Due in (days)</label>
                                                <input v-model.number="action.config.due_in_days" type="number" min="0" max="365" />
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="small">Assigned to</label>
                                            <select v-model.number="action.config.assigned_to">
                                                <option :value="null">Unassigned</option>
                                                <option v-for="u in staff" :key="u.id" :value="u.id">{{ u.name }}</option>
                                            </select>
                                        </div>
                                    </template>

                                    <!-- add_note config -->
                                    <template v-if="action.action_type === 'add_note'">
                                        <div class="form-row">
                                            <label class="small">Note template</label>
                                            <textarea v-model="action.config.content_template" rows="3"
                                                :placeholder="noteContentPlaceholder"></textarea>
                                            <small class="muted">Only fires if a customer is in context — lead-only notes aren't supported.</small>
                                        </div>
                                    </template>

                                    <!-- update_lead_status config -->
                                    <template v-if="action.action_type === 'update_lead_status'">
                                        <div class="form-row">
                                            <label class="small">New status</label>
                                            <select v-model="action.config.status">
                                                <option v-for="s in LEAD_STATUSES" :key="s" :value="s">{{ s }}</option>
                                            </select>
                                        </div>
                                    </template>

                                    <!-- assign_to_user config -->
                                    <template v-if="action.action_type === 'assign_to_user'">
                                        <div class="form-row">
                                            <label class="small">Assign lead to</label>
                                            <select v-model.number="action.config.user_id">
                                                <option v-for="u in staff" :key="u.id" :value="u.id">{{ u.name }}</option>
                                            </select>
                                        </div>
                                    </template>

                                    <!-- send_notification config -->
                                    <template v-if="action.action_type === 'send_notification'">
                                        <div class="form-row">
                                            <label class="small">Notify user</label>
                                            <select v-model.number="action.config.user_id">
                                                <option v-for="u in staff" :key="u.id" :value="u.id">{{ u.name }}</option>
                                            </select>
                                        </div>
                                        <div class="form-row">
                                            <label class="small">Message template</label>
                                            <input v-model="action.config.message_template" type="text" :placeholder="notificationPlaceholder" />
                                        </div>
                                    </template>
                                </div>

                                <button type="button" class="icon-btn danger" @click="removeAction(i)">
                                    <IconTrash :size="14" stroke-width="2" />
                                </button>
                            </div>
                        </section>

                        <footer class="slide-over-foot">
                            <button type="button" class="btn btn-ghost" @click="editorOpen = false">Cancel</button>
                            <button type="submit" class="btn btn-primary" :disabled="editor.processing">
                                {{ editingId ? 'Save changes' : 'Create workflow' }}
                            </button>
                        </footer>
                    </form>
                </aside>
            </div>
        </Teleport>

        <ConfirmModal
            v-model:show="confirmDelete"
            variant="danger"
            :title="`Delete ${deleteTarget?.name}?`"
            message="This workflow and its actions will be permanently removed."
            confirm-label="Delete"
            @confirm="doDelete"
        />
    </InternalLayout>
</template>
