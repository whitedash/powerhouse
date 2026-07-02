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
    IconChevronUp, IconChevronDown, IconCheck, IconPencil,
    IconForms, IconWebhook, IconUserPlus, IconUserCheck, IconRefresh,
    IconNote, IconBell, IconUsersGroup, IconClick, IconCheckbox, IconMail, IconLifebuoy,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';
import PlaceholderHints from './PlaceholderHints.vue';
import ConditionsEditor from './ConditionsEditor.vue';
import ValueSourceInput from './ValueSourceInput.vue';

const props = defineProps({
    workflows: { type: Array, required: true },
    forms: { type: Array, default: () => [] },
    staff: { type: Array, default: () => [] },
    trigger_types: { type: Array, required: true },
    action_types: { type: Array, required: true },
    operators: { type: Array, default: () => [] },
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
    create_ticket: 'Create ticket',
    assign_to_user: 'Assign to user',
    add_note: 'Add note',
    send_notification: 'Send notification',
    add_to_group: 'Add to group',
    webhook_outbound: 'Outbound webhook',
    send_email: 'Send email',
};

// Server emits a type string; the client maps it to a Tabler *component*
// (no icon webfont is loaded) + a brand colour for the action card chip.
const TRIGGER_ICONS = {
    form_submitted: IconForms,
    webhook_received: IconWebhook,
    lead_created: IconUserPlus,
    lead_status_changed: IconUserCheck,
    manual: IconClick,
};
const ACTION_ICONS = {
    create_lead: IconUserPlus,
    update_lead_status: IconRefresh,
    create_task: IconCheckbox,
    create_ticket: IconLifebuoy,
    assign_to_user: IconUserCheck,
    add_note: IconNote,
    send_notification: IconBell,
    add_to_group: IconUsersGroup,
    webhook_outbound: IconWebhook,
    send_email: IconMail,
};
const ACTION_COLOURS = {
    create_lead: '#10B981',
    update_lead_status: '#F59E0B',
    create_task: '#3B82F6',
    create_ticket: '#14B8A6',
    assign_to_user: '#06B6D4',
    add_note: '#8B5CF6',
    send_notification: '#F97316',
    add_to_group: '#64748B',
    webhook_outbound: '#6366F1',
    send_email: '#0EA5E9',
};
function triggerIcon(t) { return TRIGGER_ICONS[t] ?? IconBolt; }
function triggerLabel(t) { return TRIGGER_LABEL[t] ?? t; }
function actionIcon(t) { return ACTION_ICONS[t] ?? IconBolt; }
function actionColour(t) { return ACTION_COLOURS[t] ?? '#64748B'; }
function actionLabel(t) { return ACTION_LABEL[t] ?? t; }

// One-line human summary of a configured action for the card.
function actionSummary(a) {
    const c = a.config || {};
    switch (a.action_type) {
        case 'create_lead':
            return 'Create a new lead';
        case 'create_task':
            return c.title_template ? `Create task: ${c.title_template}` : 'Create a task';
        case 'create_ticket':
            return 'Create a support ticket';
        case 'assign_to_user': {
            const u = props.staff.find(s => s.id === c.user_id);
            return u ? `Assign to ${u.name}` : 'Assign to a user';
        }
        case 'update_lead_status':
            return c.status ? `Set status → ${c.status}` : 'Update lead status';
        case 'add_note':
            return 'Add a note';
        case 'send_notification': {
            const u = props.staff.find(s => s.id === c.user_id);
            return u ? `Notify ${u.name}` : 'Send a notification';
        }
        case 'add_to_group':
            return 'Add to a group';
        case 'webhook_outbound':
            return 'Send an outbound webhook';
        case 'send_email': {
            const rs = c.recipient_source;
            const to = rs
                ? (rs.source === 'field' ? `{{${rs.field_key || 'field'}}}` : (rs.static || 'support inbox'))
                : (c.to_mode === 'context_field' ? `{{${c.to_field || 'field'}}}` : (c.to_address || 'support inbox'));
            return c.subject_template ? `Email ${to}: ${c.subject_template}` : `Send an email to ${to}`;
        }
        default:
            return ACTION_LABEL[a.action_type] ?? a.action_type;
    }
}
const LEAD_STATUSES = ['new', 'contacted', 'qualified', 'proposal', 'negotiation', 'won', 'lost', 'unresponsive'];
const LEAD_SOURCES = ['manual', 'landing_page', 'facebook', 'google', 'referral', 'email', 'phone', 'event', 'word_of_mouth', 'other'];
const TASK_TYPES = ['task', 'call', 'email', 'meeting', 'note'];
const TASK_PRIORITIES = ['low', 'medium', 'high'];
const TICKET_PRIORITIES = ['low', 'medium', 'high', 'urgent'];

// Mustache strings live in JS constants to keep them out of the
// template where Vue would try to interpolate them.
const taskTitlePlaceholder = 'Follow up with {{first_name}}';
const noteContentPlaceholder = 'Lead from {{source}}: {{message}}';
const notificationPlaceholder = 'New lead: {{first_name}} from {{source}}';
const emailSubjectPlaceholder = 'Thanks for getting in touch, {{first_name}}';
const emailBodyPlaceholder = 'Hi {{first_name}},\n\nThanks for your message — we\'ll be in touch shortly.';

// Placeholder reference (PlaceholderHints). System keys each LIVE trigger seeds
// into the template context — mirrors what the controllers pass to
// WorkflowEngine::trigger(). Form-submitted field_keys are added dynamically
// from the selected form; these are the fixed keys.
const TRIGGER_SYSTEM_KEYS = {
    form_submitted: ['form_id', 'form_name', 'submission_id', 'ip', 'referrer_id', 'referral_code'],
    webhook_received: ['form_id', 'form_name', 'submission_id', 'source'],
};
// Triggers that are selectable but never fire (no code calls trigger() for them).
const INACTIVE_TRIGGERS = ['lead_created', 'lead_status_changed', 'manual'];
// Static note — keys later create_lead / create_ticket actions add to context.
// Deliberately NOT computed per-action (would duplicate the engine's accumulation).
const ACTION_ADDED_KEYS_NOTE = 'Earlier Create-lead / Create-ticket actions also add: lead_id, first_name, last_name, email, phone, company, source / ticket_id.';

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

// The selected form's field list ([{ key, label }]), shared by the placeholder
// reference and the conditions editor's field picker so both read one source.
// Empty for "Any form", a fieldless form, or a non-form trigger — callers then
// fall back to free-text field entry.
const selectedFormFields = computed(() => {
    if (editor.trigger_type !== 'form_submitted' || ! editor.trigger_config.form_id) {
        return [];
    }
    const form = props.forms.find(f => f.id === editor.trigger_config.form_id);
    return form && Array.isArray(form.fields) ? form.fields : [];
});

// Type-filtered views of the selected form's fields, for the action-parameter
// binding pickers (due date → datetime fields, recipient → email fields).
const datetimeFields = computed(() => selectedFormFields.value.filter(f => f.type === 'datetime'));
const emailFields = computed(() => selectedFormFields.value.filter(f => f.type === 'email'));

// Trigger + form-aware placeholder reference for the action editor. Depends only
// on the selected trigger and (for form_submitted) the chosen form — NOT on the
// action's position, so it never re-implements the engine's context accumulation.
const placeholderRef = computed(() => {
    const trigger = editor.trigger_type;

    if (INACTIVE_TRIGGERS.includes(trigger)) {
        return { active: false };
    }

    const hints = {
        active: true,
        systemKeys: TRIGGER_SYSTEM_KEYS[trigger] ?? [],
        formFields: null,
        formNote: null,
        actionNote: ACTION_ADDED_KEYS_NOTE,
    };

    if (trigger === 'form_submitted') {
        if (selectedFormFields.value.length) {
            hints.formFields = selectedFormFields.value;
        } else if (editor.trigger_config.form_id) {
            hints.formNote = 'This form has no fields yet.';
        } else {
            hints.formNote = 'Select a form above to see its fields.';
        }
    } else if (trigger === 'webhook_received') {
        hints.formNote = 'Any top-level field in the JSON body is also available, by the name the caller sends.';
    }

    return hints;
});

function emptyEditor() {
    return {
        name: '',
        description: '',
        is_active: true,
        trigger_type: 'form_submitted',
        trigger_config: {},
        // Empty groups = no gating (Phase 1 treats null/empty as "always run").
        conditions: { logic: 'or', groups: [] },
        actions: [],
    };
}

/* ─── Action builder UI state ─── */
const editingActionIndex = ref(null);
const showActionPicker = ref(false);
function toggleActionEdit(i) {
    editingActionIndex.value = editingActionIndex.value === i ? null : i;
}

function openCreate() {
    editingId.value = null;
    editor.clearErrors();
    Object.assign(editor, emptyEditor());
    editingActionIndex.value = null;
    showActionPicker.value = false;
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
        // Deep-clone so editing the slide-over never mutates the index prop.
        conditions: w.conditions
            ? JSON.parse(JSON.stringify(w.conditions))
            : { logic: 'or', groups: [] },
        actions: w.actions.map(a => ensureBindings({
            action_type: a.action_type,
            config: { ...(a.config || {}) },
        })),
    });
    editingActionIndex.value = null;
    showActionPicker.value = false;
    editorOpen.value = true;
    openMenu.value = null;
}

function setTrigger(type) {
    editor.trigger_type = type;
    editor.trigger_config = {};
}

// Seed sensible config defaults per action type: send_email needs a recipient
// mode up front so its fields render; create_ticket seeds the field-map keys
// (matching the engine handler's defaults) so it saves valid immediately.
function defaultConfigFor(type) {
    if (type === 'send_email') return { recipient_source: { source: 'static', static: '', field_key: '' } };
    if (type === 'create_task') return { due_in_days: 3, due_at_source: { source: 'static', field_key: '' } };
    if (type === 'create_ticket') {
        return { subject_field: 'subject', message_field: 'message', priority: 'medium', name_field: 'name', email_field: 'email', phone_field: 'phone' };
    }
    return {};
}

// Backfill the value-source descriptors when loading an EXISTING action for edit,
// deriving them from the legacy keys so old workflows keep their recipient/due.
function ensureBindings(a) {
    const c = a.config;
    if (a.action_type === 'create_task' && !c.due_at_source) {
        c.due_at_source = { source: 'static', field_key: '' };
        if (c.due_in_days == null) c.due_in_days = 3;
    }
    if (a.action_type === 'send_email' && !c.recipient_source) {
        c.recipient_source = c.to_mode === 'context_field'
            ? { source: 'field', field_key: c.to_field || '', static: '' }
            : { source: 'static', field_key: '', static: c.to_address || '' };
        delete c.to_mode;
        delete c.to_field;
        delete c.to_address;
    }
    return a;
}

function addAction(type = 'create_lead') {
    editor.actions.push({ action_type: type, config: defaultConfigFor(type) });
    editingActionIndex.value = editor.actions.length - 1;
    showActionPicker.value = false;
}
// Reset config when the action type changes, seeding the new type's defaults
// (mirrors addAction).
function resetActionConfig(action) {
    action.config = defaultConfigFor(action.action_type);
}
function removeAction(i) {
    editor.actions.splice(i, 1);
    if (editingActionIndex.value === i) {
        editingActionIndex.value = null;
    } else if (editingActionIndex.value !== null && editingActionIndex.value > i) {
        editingActionIndex.value -= 1;
    }
}
function moveAction(i, delta) {
    const j = i + delta;
    if (j < 0 || j >= editor.actions.length) return;
    const [item] = editor.actions.splice(i, 1);
    editor.actions.splice(j, 0, item);
    editingActionIndex.value = null;
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
            <div v-if="editorOpen" class="slide-over-overlay" v-overlay-dismiss="() => (editorOpen = false)">
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
                            <div class="wf-trigger-grid">
                                <button
                                    v-for="t in trigger_types"
                                    :key="t"
                                    type="button"
                                    :class="['wf-trigger-option', { active: editor.trigger_type === t }]"
                                    @click="setTrigger(t)"
                                >
                                    <component :is="triggerIcon(t)" :size="20" stroke-width="1.75" />
                                    <span>{{ triggerLabel(t) }}</span>
                                </button>
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

                        <section v-if="editor.trigger_type === 'form_submitted'" class="form-section">
                            <div class="form-section-head">
                                <h3 class="form-section-title">Conditions</h3>
                            </div>
                            <p class="muted small wf-cond-hint">Leave empty to always run; otherwise the workflow fires only when the submission matches.</p>
                            <ConditionsEditor
                                v-model="editor.conditions"
                                :fields="selectedFormFields"
                                :operators="operators"
                            />
                        </section>

                        <section class="form-section">
                            <div class="form-section-head">
                                <h3 class="form-section-title">
                                    Actions
                                    <span class="wf-count-badge">{{ editor.actions.length }}</span>
                                </h3>
                            </div>
                            <div v-if="editor.actions.length === 0" class="muted small">
                                No actions yet. A workflow without actions is a no-op.
                            </div>

                            <template v-for="(action, i) in editor.actions" :key="i">
                                <!-- Action card -->
                                <div class="wf-action-card" :class="{ open: editingActionIndex === i }">
                                    <div class="wf-reorder">
                                        <button type="button" class="icon-btn" :disabled="i === 0" title="Move up" @click="moveAction(i, -1)">
                                            <IconChevronUp :size="13" stroke-width="2" />
                                        </button>
                                        <button type="button" class="icon-btn" :disabled="i === editor.actions.length - 1" title="Move down" @click="moveAction(i, 1)">
                                            <IconChevronDown :size="13" stroke-width="2" />
                                        </button>
                                    </div>
                                    <div class="wf-action-num">{{ i + 1 }}</div>
                                    <div class="wf-action-icon" :style="{ background: actionColour(action.action_type) }">
                                        <component :is="actionIcon(action.action_type)" :size="15" stroke-width="2" />
                                    </div>
                                    <div class="wf-action-info" @click="toggleActionEdit(i)">
                                        <span class="wf-action-label">{{ actionLabel(action.action_type) }}</span>
                                        <span class="wf-action-summary">{{ actionSummary(action) }}</span>
                                    </div>
                                    <div class="wf-action-actions">
                                        <button type="button" class="icon-btn" title="Edit" @click="toggleActionEdit(i)">
                                            <IconPencil :size="15" stroke-width="2" />
                                        </button>
                                        <button type="button" class="icon-btn danger" title="Delete" @click="removeAction(i)">
                                            <IconTrash :size="15" stroke-width="2" />
                                        </button>
                                    </div>
                                </div>

                                <!-- Inline config panel -->
                                <div v-if="editingActionIndex === i" class="wf-action-edit-panel">
                                    <div class="form-row">
                                        <label class="small">Action type</label>
                                        <select v-model="action.action_type" @change="resetActionConfig(action)">
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
                                                    <option :value="null">Default — Super Admin</option>
                                                    <option v-for="u in staff" :key="u.id" :value="u.id">{{ u.name }}</option>
                                                </select>
                                                <small class="muted">Left as default, the task is assigned to the primary Super Admin (tasks always have an owner).</small>
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
                                        <div class="grid-2">
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
                                        </div>
                                        <div class="form-row">
                                            <label class="small">Due date</label>
                                            <ValueSourceInput
                                                v-model="action.config.due_at_source"
                                                :fields="datetimeFields"
                                                static-label="Relative"
                                                field-label="Datetime field"
                                            >
                                                <template #static>
                                                    <input v-model.number="action.config.due_in_days" type="number" min="0" max="3650" placeholder="days from now (default 3)" />
                                                </template>
                                            </ValueSourceInput>
                                            <small class="muted">Relative = N days from when it runs (09:00). Datetime field = the submitted value (falls back to the offset if empty/invalid).</small>
                                        </div>
                                        <div class="form-row">
                                            <label class="small">Assigned to</label>
                                            <select v-model.number="action.config.assigned_to">
                                                <option :value="null">Unassigned</option>
                                                <option v-for="u in staff" :key="u.id" :value="u.id">{{ u.name }}</option>
                                            </select>
                                        </div>
                                    </template>

                                    <!-- create_ticket config -->
                                    <template v-if="action.action_type === 'create_ticket'">
                                        <div class="grid-2">
                                            <div class="form-row">
                                                <label class="small">Subject field</label>
                                                <input v-model="action.config.subject_field" type="text" placeholder="subject" />
                                            </div>
                                            <div class="form-row">
                                                <label class="small">Message field</label>
                                                <input v-model="action.config.message_field" type="text" placeholder="message" />
                                            </div>
                                            <div class="form-row">
                                                <label class="small">Name field</label>
                                                <input v-model="action.config.name_field" type="text" placeholder="name" />
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
                                                <label class="small">Priority</label>
                                                <select v-model="action.config.priority">
                                                    <option v-for="p in TICKET_PRIORITIES" :key="p" :value="p">{{ p }}</option>
                                                </select>
                                            </div>
                                        </div>
                                        <small class="muted">Field names map to submitted values; the message field is required.</small>
                                        <PlaceholderHints :reference="placeholderRef" />
                                    </template>

                                    <!-- add_note config -->
                                    <template v-if="action.action_type === 'add_note'">
                                        <div class="form-row">
                                            <label class="small">Note template</label>
                                            <textarea v-model="action.config.content_template" rows="3"
                                                :placeholder="noteContentPlaceholder"></textarea>
                                            <small class="muted">Only fires if a company is in context — lead-only notes aren't supported.</small>
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

                                    <!-- send_email config -->
                                    <template v-if="action.action_type === 'send_email'">
                                        <div class="form-row">
                                            <label class="small">Recipient</label>
                                            <ValueSourceInput
                                                v-model="action.config.recipient_source"
                                                :fields="emailFields"
                                                static-label="Fixed address"
                                                field-label="Email field"
                                            >
                                                <template #static>
                                                    <input v-model="action.config.recipient_source.static" type="email" placeholder="support@whitedash.com" />
                                                </template>
                                            </ValueSourceInput>
                                            <small class="muted">Fixed = a literal address (blank → support inbox). Email field = a submitted field's value.</small>
                                        </div>
                                        <div class="form-row">
                                            <label class="small">Subject template</label>
                                            <input v-model="action.config.subject_template" type="text" :placeholder="emailSubjectPlaceholder" />
                                        </div>
                                        <div class="form-row">
                                            <label class="small">Body template</label>
                                            <textarea v-model="action.config.body_template" rows="5" :placeholder="emailBodyPlaceholder"></textarea>
                                            <small class="muted">Use <code>&#123;&#123;field_key&#125;&#125;</code> placeholders to splice in submitted values.</small>
                                        </div>
                                        <PlaceholderHints :reference="placeholderRef" />
                                    </template>

                                    <button type="button" class="btn btn-ghost btn-sm" @click="editingActionIndex = null">Done</button>
                                </div>
                            </template>

                            <!-- Add-action type picker -->
                            <button type="button" class="btn btn-ghost btn-sm wf-add-btn" @click="showActionPicker = !showActionPicker">
                                <IconPlus :size="14" stroke-width="2" /> Add action
                            </button>
                            <div v-if="showActionPicker" class="wf-action-picker">
                                <button
                                    v-for="t in action_types"
                                    :key="t"
                                    type="button"
                                    class="wf-action-pick"
                                    @click="addAction(t)"
                                >
                                    <span class="wf-pick-icon" :style="{ background: actionColour(t) }">
                                        <component :is="actionIcon(t)" :size="14" stroke-width="2" />
                                    </span>
                                    <span>{{ actionLabel(t) }}</span>
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
