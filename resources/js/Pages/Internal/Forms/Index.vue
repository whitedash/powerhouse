<script setup>
/**
 * Forms — embeddable lead-capture forms.
 *
 * Each form card shows name + status + counters + an integration
 * panel (embed snippet, webhook URL, webhook secret reveal).
 *
 * The slide-over is the form BUILDER:
 *   - top: name + slug + description + status
 *   - middle: ordered fields list with type/label/key/required
 *   - bottom: messaging (submit copy, success, redirect) + GDPR
 */
import { ref, computed, reactive, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    IconPlus, IconForms, IconX, IconCopy, IconCheck, IconEye, IconEyeOff,
    IconDots, IconTrash, IconEdit, IconArrowLeft, IconArrowRight, IconChevronUp, IconChevronDown,
    IconPencil,
    IconLetterT, IconMail, IconPhone, IconAlignLeft, IconCheckbox, IconCircleDot,
    IconNumbers, IconCalendar,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';
import FormFieldRenderer from '@/Components/Forms/FormFieldRenderer.vue';

const props = defineProps({
    forms: { type: Array, required: true },
    themes: { type: Array, default: () => [] },
});

const FIELD_TYPES = [
    { value: 'text', label: 'Text' },
    { value: 'email', label: 'Email' },
    { value: 'phone', label: 'Phone' },
    { value: 'textarea', label: 'Long text' },
    { value: 'select', label: 'Dropdown' },
    { value: 'radio', label: 'Radio' },
    { value: 'checkbox', label: 'Checkbox' },
    { value: 'number', label: 'Number' },
    { value: 'date', label: 'Date' },
    { value: 'hidden', label: 'Hidden' },
    { value: 'placeholder', label: 'Text block' },
];

// Server-emits-a-type, client-maps-to-a-component (matches the rest of
// the app — there is no Tabler webfont loaded, only @tabler/icons-vue).
const FIELD_ICONS = {
    text: IconLetterT,
    email: IconMail,
    phone: IconPhone,
    textarea: IconAlignLeft,
    select: IconChevronDown,
    radio: IconCircleDot,
    checkbox: IconCheckbox,
    number: IconNumbers,
    date: IconCalendar,
    hidden: IconEyeOff,
    placeholder: IconAlignLeft,
};
function fieldIcon(type) {
    return FIELD_ICONS[type] ?? IconLetterT;
}
function fieldTypeLabel(type) {
    return FIELD_TYPES.find(t => t.value === type)?.label ?? type;
}
// Types whose `options` list is meaningful.
const OPTION_TYPES = ['select', 'radio', 'checkbox'];

// Per-field layout width (12-col grid in the embed widget).
const FIELD_WIDTHS = [
    { value: 'full', label: 'Full' },
    { value: 'half', label: 'Half' },
    { value: 'third', label: 'Third' },
];

// Live options for previews — derive from the textarea string so unsaved
// edits show immediately, falling back to the stored array.
function fieldOptions(field) {
    if (field.options_raw) {
        return field.options_raw.split('\n').map(o => o.trim()).filter(Boolean);
    }
    return field.options ?? [];
}

/* ─── Integration panel state ─── */
// Per-form id: is integration panel open?
const openIntegrations = reactive({});
const revealedSecrets = reactive({});
const copiedKey = ref('');

function toggleIntegration(id) {
    openIntegrations[id] = !openIntegrations[id];
}
function toggleSecret(id) {
    revealedSecrets[id] = !revealedSecrets[id];
}
async function copy(text, key) {
    try {
        await navigator.clipboard.writeText(text);
        copiedKey.value = key;
        setTimeout(() => { if (copiedKey.value === key) copiedKey.value = ''; }, 1500);
    } catch {}
}

/* ─── Row menu ─── */
const openMenu = ref(null);
function toggleMenu(id) { openMenu.value = openMenu.value === id ? null : id; }

/* ─── Delete confirm ─── */
const confirmDelete = ref(false);
const deleteTarget = ref(null);
function askDelete(form) {
    deleteTarget.value = form;
    confirmDelete.value = true;
}
function doDelete() {
    if (!deleteTarget.value) return;
    router.delete(`/forms/${deleteTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => { confirmDelete.value = false; deleteTarget.value = null; },
    });
}

/* ─── Status toggle ─── */
function toggleStatus(form) {
    const next = form.status === 'active' ? 'inactive' : 'active';
    // Pull the existing payload because update wants the full form
    // (same payload as create) — the controller does a wipe/recreate
    // of fields, so we must hand back the fields it stored.
    router.put(`/forms/${form.id}`, {
        ...formPayloadFromCard(form),
        status: next,
    }, {
        preserveScroll: true,
        onSuccess: () => { openMenu.value = null; },
    });
}
// Card → step list. Multi-step cards carry `steps`; legacy single-step cards
// (or any without steps) fall back to wrapping their flat `fields` in one step.
function cardSteps(card) {
    return (card.steps && card.steps.length)
        ? card.steps
        : [{ label: 'Step 1', sort_order: 0, fields: card.fields ?? [] }];
}

// Backend-ready payload for a card (used by the status toggle, which PUTs the
// whole form). Emits the steps-based shape the controller now expects.
function formPayloadFromCard(card) {
    return {
        name: card.name,
        description: card.description,
        slug: card.slug,
        status: card.status,
        submit_button_text: card.submit_button_text,
        success_message: card.success_message,
        redirect_url: card.redirect_url,
        gdpr_consent_enabled: card.gdpr_consent_enabled,
        gdpr_consent_text: card.gdpr_consent_text,
        theme_id: card.theme_id ?? null,
        steps: cardSteps(card).map((step, si) => ({
            label: step.label,
            sort_order: si,
            fields: (step.fields ?? []).map((f, fi) => ({
                label: f.label,
                content: f.content ?? null,
                field_key: f.field_key,
                type: f.type,
                placeholder: f.placeholder || null,
                default_value: f.default_value || null,
                options: f.options ?? null,
                is_required: !!f.is_required,
                width: f.width || 'full',
                sort_order: fi,
            })),
        })),
    };
}

// Card → editor steps (builder shape): each field gains the textarea-backed
// options_raw so existing options show one-per-line while editing.
function cardToEditorSteps(card) {
    return cardSteps(card).map((step, si) => ({
        id: step.id ?? null,
        label: step.label || `Step ${si + 1}`,
        sort_order: si,
        fields: (step.fields ?? []).map(f => ({
            ...f,
            content: f.content ?? '',
            options: f.options ? [...f.options] : null,
            options_raw: (f.options ?? []).join('\n'),
            width: f.width ?? 'full',
        })),
    }));
}

/* ─── Builder slide-over ─── */
const editorOpen = ref(false);
const editingId = ref(null);
const editor = useForm(emptyEditor());

/* ─── Step state ─── */
// Fields are nested inside steps; the builder edits one step at a time.
const activeStepIndex = ref(0);
const activeStepFields = computed(() => editor.steps[activeStepIndex.value]?.fields ?? []);
const totalFieldCount = computed(() => editor.steps.reduce((n, s) => n + s.fields.length, 0));

/* ─── Remove-step confirm ─── */
const confirmRemoveStep = ref(false);
const stepToRemove = ref(null);

/* ─── Live preview modal ─── */
// Renders the current (unsaved) builder state exactly as the embedded form
// would appear. Reads editor.steps directly so it tracks edits. Per-step by
// default; "All steps" flattens every step's fields (today's flat behaviour).
const showPreview = ref(false);
const stepPreviewIndex = ref(0);
const showAllSteps = ref(false);
const previewFields = computed(() => {
    const fields = showAllSteps.value
        ? editor.steps.flatMap(s => s.fields)
        : (editor.steps[stepPreviewIndex.value]?.fields ?? []);
    // Inject the live options (from the unsaved options_raw textarea) so the
    // renderer's select/radio reflect edits before save.
    return fields.map(f => ({ ...f, options: fieldOptions(f) }));
});
function openPreview() {
    stepPreviewIndex.value = Math.min(activeStepIndex.value, editor.steps.length - 1);
    showAllSteps.value = false;
    showPreview.value = true;
}

/* ─── Builder UI state ─── */
// Which field card is expanded for inline editing (accordion).
const editingFieldIndex = ref(null);
function toggleFieldEdit(i) {
    editingFieldIndex.value = editingFieldIndex.value === i ? null : i;
}
// Collapsible sections — details open, settings collapsed.
const openSections = reactive({ details: true, settings: false });

// A blank field shaped for the builder. options_raw is the textarea-backed
// string; it's split into the `options` array only at save time so typing
// a newline isn't stripped mid-edit.
function blankField(type = 'text') {
    if (type === 'placeholder') {
        // Display-only text block: `content` holds the rich-text body (label
        // stays empty); field_key is left empty for the backend to synthesise.
        return {
            label: '', content: '', field_key: '', type: 'placeholder', placeholder: null,
            default_value: null, options: null, options_raw: '', is_required: false, width: 'full',
        };
    }
    return {
        label: '', field_key: '', type,
        placeholder: '', default_value: '',
        options: null, options_raw: '', is_required: false, width: 'full',
    };
}

function emptyEditor() {
    return {
        name: '',
        description: '',
        slug: '',
        status: 'draft',
        submit_button_text: 'Submit',
        success_message: "Thank you! We'll be in touch soon.",
        redirect_url: '',
        gdpr_consent_enabled: false,
        gdpr_consent_text: '',
        theme_id: null,
        // Fields are nested inside steps; a new form starts with one step that
        // carries the default first-name/email seed.
        steps: [
            {
                id: null,
                label: 'Step 1',
                sort_order: 0,
                fields: [
                    { label: 'First name', field_key: 'first_name', type: 'text', placeholder: '', default_value: '', options: null, options_raw: '', is_required: true, width: 'full' },
                    { label: 'Email', field_key: 'email', type: 'email', placeholder: '', default_value: '', options: null, options_raw: '', is_required: true, width: 'full' },
                ],
            },
        ],
    };
}

function openCreate() {
    editingId.value = null;
    editor.clearErrors();
    Object.assign(editor, emptyEditor());
    activeStepIndex.value = 0;
    editingFieldIndex.value = null;
    editorOpen.value = true;
}
function openEdit(form) {
    editingId.value = form.id;
    editor.clearErrors();
    Object.assign(editor, formPayloadFromCard(form));
    // Rebuild steps in the builder shape (each field gains options_raw so the
    // textarea shows existing options, one per line).
    editor.steps = cardToEditorSteps(form);
    activeStepIndex.value = 0;
    editingFieldIndex.value = null;
    editorOpen.value = true;
    openMenu.value = null;
}

/* Auto-slug from name (only when creating). */
watch(() => editor.name, (n) => {
    if (editingId.value !== null) return;
    editor.slug = (n || '')
        .toLowerCase()
        .replace(/[^a-z0-9-\s]/g, '')
        .replace(/\s+/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '');
});

function addField(type = 'text') {
    const fields = editor.steps[activeStepIndex.value].fields;
    fields.push(blankField(type));
    // Open the new field for editing straight away.
    editingFieldIndex.value = fields.length - 1;
}
function removeField(i) {
    editor.steps[activeStepIndex.value].fields.splice(i, 1);
    if (editingFieldIndex.value === i) {
        editingFieldIndex.value = null;
    } else if (editingFieldIndex.value !== null && editingFieldIndex.value > i) {
        editingFieldIndex.value -= 1;
    }
}
function moveField(i, delta) {
    const fields = editor.steps[activeStepIndex.value].fields;
    const j = i + delta;
    if (j < 0 || j >= fields.length) return;
    const [item] = fields.splice(i, 1);
    fields.splice(j, 0, item);
    editingFieldIndex.value = null;
}

/* ─── Steps ─── */
function selectStep(index) {
    activeStepIndex.value = index;
    editingFieldIndex.value = null;
}
function addStep() {
    editor.steps.push({
        id: null,
        label: 'Step ' + (editor.steps.length + 1),
        sort_order: editor.steps.length,
        fields: [],
    });
    activeStepIndex.value = editor.steps.length - 1;
    editingFieldIndex.value = null;
}
// Guarded by ConfirmModal — the last step can never be removed.
function askRemoveStep(index) {
    if (editor.steps.length === 1) return;
    stepToRemove.value = index;
    confirmRemoveStep.value = true;
}
function doRemoveStep() {
    const index = stepToRemove.value;
    if (index === null || editor.steps.length === 1) return;
    editor.steps.splice(index, 1);
    if (activeStepIndex.value >= editor.steps.length) {
        activeStepIndex.value = editor.steps.length - 1;
    }
    editingFieldIndex.value = null;
    confirmRemoveStep.value = false;
    stepToRemove.value = null;
}
// Move the currently-edited field out of the active step onto another.
function moveFieldToStep(fieldIndex, targetStepIndex) {
    const fields = editor.steps[activeStepIndex.value].fields;
    const [item] = fields.splice(fieldIndex, 1);
    editor.steps[targetStepIndex].fields.push(item);
    editingFieldIndex.value = null;
}
function autoKey(field) {
    if (!field.field_key && field.label) {
        field.field_key = field.label
            .toLowerCase()
            .replace(/[^a-z0-9_\s]/g, '')
            .trim()
            .replace(/\s+/g, '_');
    }
}

function save() {
    const endpoint = editingId.value
        ? `/forms/${editingId.value}`
        : '/forms';
    const method = editingId.value ? 'put' : 'post';

    // Split options_raw → options array at submit time (not per-keystroke,
    // which would strip a newline the moment Enter is pressed). Emit the nested
    // steps shape the backend expects; drop the legacy flat fields key.
    editor
        .transform((data) => ({
            ...data,
            steps: editor.steps.map((step, si) => ({
                label: step.label,
                sort_order: si,
                fields: step.fields.map((field, fi) => ({
                    label: field.label,
                    // Placeholder body — sanitised client-side (belt-and-braces;
                    // onUpdate already cleans it). null for other types.
                    content: field.type === 'placeholder' ? (field.content || '') : null,
                    field_key: field.field_key,
                    type: field.type,
                    placeholder: field.placeholder || null,
                    default_value: field.default_value || null,
                    is_required: !!field.is_required,
                    width: field.width || 'full',
                    sort_order: fi,
                    options: OPTION_TYPES.includes(field.type)
                        ? (field.options_raw ?? '')
                            .split('\n')
                            .map((o) => o.trim())
                            .filter((o) => o.length > 0)
                        : null,
                })),
            })),
            fields: undefined,
        }))
        [method](endpoint, {
            preserveScroll: true,
            onSuccess: () => { editorOpen.value = false; },
            onError: () => {
                // Validation failed — errors are now in editor.errors and the
                // banner at the top of the slide-over surfaces them. Keep the
                // slide-over open so the user can fix and retry.
            },
        });
}

const formStatuses = ['active', 'inactive', 'draft'];
function statusLabel(s) {
    return s === 'active' ? 'Active' : s === 'inactive' ? 'Inactive' : 'Draft';
}

const totalSubmissions = computed(() =>
    props.forms.reduce((sum, f) => sum + (f.submission_count || 0), 0),
);
</script>

<template>
    <InternalLayout title="Forms" active-nav="forms">
        <Head title="Forms" />

        <div class="forms-index page-shell">
            <div class="page-head">
                <div>
                    <h1>Forms</h1>
                    <p class="muted">Embeddable lead-capture forms with workflow automation.</p>
                </div>
                <button type="button" class="btn btn-primary" @click="openCreate">
                    <IconPlus :size="16" stroke-width="2" /> New form
                </button>
            </div>

            <div class="forms-summary">
                <div class="forms-summary-pill">
                    <span class="muted small">Forms</span>
                    <strong>{{ forms.length }}</strong>
                </div>
                <div class="forms-summary-pill">
                    <span class="muted small">Active</span>
                    <strong>{{ forms.filter(f => f.status === 'active').length }}</strong>
                </div>
                <div class="forms-summary-pill">
                    <span class="muted small">Submissions (total)</span>
                    <strong>{{ totalSubmissions }}</strong>
                </div>
            </div>

            <div v-if="forms.length === 0" class="empty-card">
                <IconForms :size="40" stroke-width="1.4" />
                <h3>No forms yet</h3>
                <p class="muted">Create your first form to start capturing leads from your site.</p>
                <button type="button" class="btn btn-primary" @click="openCreate">
                    <IconPlus :size="16" stroke-width="2" /> New form
                </button>
            </div>

            <div v-else class="forms-list">
                <article v-for="form in forms" :key="form.id" class="form-card">
                    <header class="form-card-head">
                        <div class="form-card-title">
                            <h3>{{ form.name }}</h3>
                            <span :class="['status-chip', 'sc-' + form.status]">{{ statusLabel(form.status) }}</span>
                        </div>
                        <div class="form-card-actions">
                            <Link :href="`/forms/${form.id}/submissions`" class="btn btn-ghost btn-sm">
                                <IconArrowRight :size="14" stroke-width="2" /> Submissions
                            </Link>
                            <div class="menu-wrap">
                                <button type="button" class="icon-btn" @click="toggleMenu(form.id)">
                                    <IconDots :size="16" stroke-width="2" />
                                </button>
                                <div v-if="openMenu === form.id" class="row-menu">
                                    <button type="button" @click="openEdit(form)">
                                        <IconEdit :size="14" stroke-width="2" /> Edit
                                    </button>
                                    <button type="button" @click="toggleStatus(form)">
                                        <IconCheck :size="14" stroke-width="2" />
                                        {{ form.status === 'active' ? 'Deactivate' : 'Activate' }}
                                    </button>
                                    <button type="button" class="danger" @click="askDelete(form)">
                                        <IconTrash :size="14" stroke-width="2" /> Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                    </header>

                    <p v-if="form.description" class="form-card-desc muted">{{ form.description }}</p>

                    <div class="form-card-meta">
                        <span class="muted small">{{ form.fields_count }} field{{ form.fields_count === 1 ? '' : 's' }}</span>
                        <span class="muted small">·</span>
                        <span class="muted small">{{ form.submission_count }} submission{{ form.submission_count === 1 ? '' : 's' }}</span>
                        <span class="muted small">·</span>
                        <span class="muted small">/{{ form.slug }}</span>
                    </div>

                    <button type="button" class="form-card-integration-toggle" @click="toggleIntegration(form.id)">
                        <IconChevronDown v-if="!openIntegrations[form.id]" :size="14" stroke-width="2" />
                        <IconChevronUp v-else :size="14" stroke-width="2" />
                        Integration
                    </button>

                    <div v-if="openIntegrations[form.id]" class="form-integration">
                        <div class="form-integration-block">
                            <label>Embed snippet</label>
                            <pre>{{ form.embed_snippet }}</pre>
                            <button type="button" class="btn btn-ghost btn-sm" @click="copy(form.embed_snippet, 'embed-' + form.id)">
                                <IconCheck v-if="copiedKey === 'embed-' + form.id" :size="14" />
                                <IconCopy v-else :size="14" />
                                {{ copiedKey === 'embed-' + form.id ? 'Copied' : 'Copy snippet' }}
                            </button>
                        </div>

                        <div class="form-integration-block">
                            <label>Webhook URL</label>
                            <code>{{ form.webhook_url }}</code>
                            <button type="button" class="btn btn-ghost btn-sm" @click="copy(form.webhook_url, 'wh-' + form.id)">
                                <IconCheck v-if="copiedKey === 'wh-' + form.id" :size="14" />
                                <IconCopy v-else :size="14" />
                                {{ copiedKey === 'wh-' + form.id ? 'Copied' : 'Copy URL' }}
                            </button>
                        </div>

                        <div class="form-integration-block">
                            <label>Webhook secret (HMAC SHA-256)</label>
                            <code v-if="revealedSecrets[form.id]" class="form-secret">{{ form.webhook_secret }}</code>
                            <code v-else class="form-secret">•••••••••••••••••••••••••••••••</code>
                            <div class="form-integration-actions">
                                <button type="button" class="btn btn-ghost btn-sm" @click="toggleSecret(form.id)">
                                    <IconEye v-if="!revealedSecrets[form.id]" :size="14" />
                                    <IconEyeOff v-else :size="14" />
                                    {{ revealedSecrets[form.id] ? 'Hide' : 'Reveal' }}
                                </button>
                                <button v-if="revealedSecrets[form.id]" type="button" class="btn btn-ghost btn-sm" @click="copy(form.webhook_secret, 'sec-' + form.id)">
                                    <IconCheck v-if="copiedKey === 'sec-' + form.id" :size="14" />
                                    <IconCopy v-else :size="14" />
                                    {{ copiedKey === 'sec-' + form.id ? 'Copied' : 'Copy secret' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </article>
            </div>
        </div>

        <!-- Builder slide-over -->
        <Teleport to="body">
            <div v-if="editorOpen" class="slide-over-overlay" @click.self="editorOpen = false">
                <aside class="slide-over slide-over-wide" role="dialog">
                    <header class="slide-over-head">
                        <h2>{{ editingId ? 'Edit form' : 'New form' }}</h2>
                        <div class="slide-over-head-actions">
                            <button type="button" class="btn btn-ghost btn-sm" @click="openPreview">
                                <IconEye :size="14" stroke-width="2" /> Preview
                            </button>
                            <button type="button" class="icon-btn" @click="editorOpen = false">
                                <IconX :size="18" stroke-width="2" />
                            </button>
                        </div>
                    </header>

                    <form class="slide-over-body" @submit.prevent="save">
                        <!-- Validation error banner — surfaces every editor.errors
                             key (incl. step-scoped field errors) so a 422 is never silent. -->
                        <div v-if="Object.keys(editor.errors).length" class="fb-error-banner" role="alert">
                            <p class="fb-error-banner-title">Please fix the following before saving:</p>
                            <ul class="fb-error-banner-list">
                                <li v-for="(msg, key) in editor.errors" :key="key">{{ msg }}</li>
                            </ul>
                        </div>

                        <!-- Form details (collapsible) -->
                        <section class="form-section">
                            <button type="button" class="fb-section-head" @click="openSections.details = !openSections.details">
                                <component :is="openSections.details ? IconChevronUp : IconChevronDown" :size="15" stroke-width="2" />
                                <span class="form-section-title">Form details</span>
                            </button>
                            <div v-show="openSections.details" class="fb-section-body">
                                <div class="form-row">
                                    <label>Form name <span class="req">*</span></label>
                                    <input v-model="editor.name" type="text" maxlength="255" required />
                                    <div v-if="editor.errors.name" class="err">{{ editor.errors.name }}</div>
                                </div>
                                <div class="form-row">
                                    <label>Slug <span class="req">*</span></label>
                                    <input v-model="editor.slug" type="text" pattern="[a-z0-9-]+" maxlength="100" required />
                                    <small class="muted">Used in /forms/{slug}/embed.js and /webhooks/{slug}. Lowercase, hyphens only.</small>
                                    <div v-if="editor.errors.slug" class="err">{{ editor.errors.slug }}</div>
                                </div>
                                <div class="form-row">
                                    <label>Description</label>
                                    <textarea v-model="editor.description" rows="2" maxlength="2000"></textarea>
                                </div>
                                <div class="form-row">
                                    <label>Status</label>
                                    <select v-model="editor.status">
                                        <option v-for="s in formStatuses" :key="s" :value="s">{{ statusLabel(s) }}</option>
                                    </select>
                                </div>
                                <div class="form-row">
                                    <label>Theme</label>
                                    <select v-model="editor.theme_id">
                                        <option :value="null">Default (built-in look)</option>
                                        <option v-for="t in themes" :key="t.id" :value="t.id">{{ t.name }}</option>
                                    </select>
                                    <small class="muted">
                                        <Link href="/forms/themes" class="fb-link">Manage themes</Link>
                                        — editing a theme restyles every form using it; embedded forms
                                        pick up changes within ~5&nbsp;minutes (embed cache).
                                    </small>
                                    <div v-if="editor.errors.theme_id" class="err">{{ editor.errors.theme_id }}</div>
                                </div>
                            </div>
                        </section>

                        <!-- Fields builder (per-step) -->
                        <section class="form-section">
                            <div class="form-section-head">
                                <h3 class="form-section-title">
                                    Fields
                                    <span class="fb-count-badge">{{ editor.steps.length }} step{{ editor.steps.length === 1 ? '' : 's' }} · {{ totalFieldCount }} field{{ totalFieldCount === 1 ? '' : 's' }}</span>
                                </h3>
                            </div>

                            <!-- Step tabs -->
                            <div class="fb-step-tabs">
                                <div class="tabs">
                                    <button
                                        v-for="(step, si) in editor.steps"
                                        :key="si"
                                        type="button"
                                        class="tab"
                                        :class="{ active: si === activeStepIndex }"
                                        @click="selectStep(si)"
                                    >
                                        {{ step.label || 'Step ' + (si + 1) }}
                                        <span class="count">{{ step.fields.length }}</span>
                                        <span
                                            class="fb-step-x"
                                            :class="{ 'is-disabled': editor.steps.length === 1 }"
                                            title="Remove step"
                                            @click.stop="askRemoveStep(si)"
                                        >
                                            <IconX :size="12" stroke-width="2" />
                                        </span>
                                    </button>
                                </div>
                                <button type="button" class="btn btn-ghost btn-sm fb-step-add" @click="addStep">
                                    <IconPlus :size="14" stroke-width="2" /> Step
                                </button>
                            </div>

                            <!-- Active step label -->
                            <div class="form-row fb-step-label-row">
                                <label class="small">Step label <span class="fb-help-inline">— shown in the respondent progress indicator</span></label>
                                <input
                                    v-model="editor.steps[activeStepIndex].label"
                                    type="text"
                                    maxlength="60"
                                    placeholder="Step label…"
                                />
                            </div>

                            <!-- Empty step -->
                            <div v-if="activeStepFields.length === 0" class="empty-state fb-step-empty">
                                <div class="empty-icon"><IconForms :size="40" stroke-width="1.4" /></div>
                                <h3>No fields on this step yet</h3>
                                <p>Add a field below, or move one here from another step.</p>
                            </div>

                            <template v-for="(field, i) in activeStepFields" :key="i">
                                <!-- Field card -->
                                <div class="fb-field-card" :class="{ open: editingFieldIndex === i }">
                                    <div class="fb-reorder">
                                        <button type="button" class="icon-btn" :disabled="i === 0" title="Move up" @click="moveField(i, -1)">
                                            <IconChevronUp :size="13" stroke-width="2" />
                                        </button>
                                        <button type="button" class="icon-btn" :disabled="i === activeStepFields.length - 1" title="Move down" @click="moveField(i, 1)">
                                            <IconChevronDown :size="13" stroke-width="2" />
                                        </button>
                                    </div>
                                    <div class="fb-field-icon">
                                        <component :is="fieldIcon(field.type)" :size="16" stroke-width="2" />
                                    </div>
                                    <div class="fb-field-info" @click="toggleFieldEdit(i)">
                                        <span class="fb-field-label">{{ field.label || 'Untitled field' }}</span>
                                        <span class="fb-field-meta">
                                            <span class="fb-type-badge">{{ fieldTypeLabel(field.type) }}</span>
                                            <span v-if="field.is_required" class="fb-required-badge">Required</span>
                                        </span>
                                    </div>
                                    <div class="fb-field-actions">
                                        <button type="button" class="icon-btn" title="Edit" @click="toggleFieldEdit(i)">
                                            <IconPencil :size="15" stroke-width="2" />
                                        </button>
                                        <button type="button" class="icon-btn danger" title="Delete" @click="removeField(i)">
                                            <IconTrash :size="15" stroke-width="2" />
                                        </button>
                                    </div>
                                </div>

                                <!-- Inline edit panel -->
                                <div v-if="editingFieldIndex === i" class="fb-field-edit-panel">
                                    <!-- Placeholder: display-only text block. Content (held in
                                         `label`) + Type only; no key/width/placeholder/options/required. -->
                                    <template v-if="field.type === 'placeholder'">
                                        <div v-if="field.type === 'placeholder'" class="fb-field-row">
                                            <label class="fb-label">Content</label>
                                            <textarea
                                                v-model="field.content"
                                                class="fb-textarea"
                                                rows="4"
                                                placeholder="Enter your text block content…"
                                                maxlength="5000"
                                            ></textarea>
                                        </div>
                                        <div class="form-row">
                                            <label class="small">Type</label>
                                            <select v-model="field.type">
                                                <option v-for="t in FIELD_TYPES" :key="t.value" :value="t.value">{{ t.label }}</option>
                                            </select>
                                        </div>
                                    </template>

                                    <!-- Input fields: full attribute grid + options. -->
                                    <template v-else>
                                        <div class="fb-edit-grid">
                                            <div class="form-row">
                                                <label class="small">Label <span class="req">*</span></label>
                                                <input v-model="field.label" type="text" maxlength="255" @blur="autoKey(field)" />
                                            </div>
                                            <div class="form-row">
                                                <label class="small">Key (POST field name)</label>
                                                <input v-model="field.field_key" type="text" pattern="[a-z][a-z0-9_]*" maxlength="100" />
                                            </div>
                                            <div class="form-row">
                                                <label class="small">Type</label>
                                                <select v-model="field.type">
                                                    <option v-for="t in FIELD_TYPES" :key="t.value" :value="t.value">{{ t.label }}</option>
                                                </select>
                                            </div>
                                            <div class="form-row">
                                                <label class="small">Width</label>
                                                <select v-model="field.width">
                                                    <option v-for="w in FIELD_WIDTHS" :key="w.value" :value="w.value">{{ w.label }}</option>
                                                </select>
                                            </div>
                                            <div class="form-row">
                                                <label class="small">Placeholder</label>
                                                <input v-model="field.placeholder" type="text" maxlength="255" />
                                            </div>
                                        </div>

                                        <div v-if="OPTION_TYPES.includes(field.type)" class="form-row">
                                            <label class="small">Options <span class="fb-help-inline">— one per line</span></label>
                                            <textarea
                                                v-model="field.options_raw"
                                                @keydown.enter.stop
                                                rows="4"
                                                placeholder="Option A&#10;Option B&#10;Option C"
                                            ></textarea>
                                        </div>
                                    </template>

                                    <!-- Move this field to another step (multi-step only) -->
                                    <div v-if="editor.steps.length > 1" class="form-row">
                                        <label class="small">Move to step</label>
                                        <select :value="activeStepIndex" @change="moveFieldToStep(i, Number($event.target.value))">
                                            <option v-for="(s, si) in editor.steps" :key="si" :value="si" :disabled="si === activeStepIndex">
                                                {{ s.label || 'Step ' + (si + 1) }}{{ si === activeStepIndex ? ' (current)' : '' }}
                                            </option>
                                        </select>
                                    </div>

                                    <div v-if="field.type !== 'placeholder'" class="fb-toggle-row">
                                        <div>
                                            <div class="fb-toggle-label">Required</div>
                                            <div class="field-help">Show a validation error if left empty</div>
                                        </div>
                                        <button type="button" :class="['toggle', { on: field.is_required }]" @click="field.is_required = !field.is_required"></button>
                                    </div>

                                    <button type="button" class="btn btn-ghost btn-sm" @click="editingFieldIndex = null">Done</button>
                                </div>
                            </template>

                            <!-- Add-field type picker -->
                            <div class="fb-add-label muted small">Add a field to this step</div>
                            <div class="fb-type-grid">
                                <button
                                    v-for="t in FIELD_TYPES"
                                    :key="t.value"
                                    type="button"
                                    class="fb-type-option"
                                    @click="addField(t.value)"
                                >
                                    <component :is="fieldIcon(t.value)" :size="18" stroke-width="2" />
                                    <span>{{ t.label }}</span>
                                </button>
                            </div>
                        </section>

                        <!-- Settings (collapsible) -->
                        <section class="form-section">
                            <button type="button" class="fb-section-head" @click="openSections.settings = !openSections.settings">
                                <component :is="openSections.settings ? IconChevronUp : IconChevronDown" :size="15" stroke-width="2" />
                                <span class="form-section-title">Settings</span>
                            </button>
                            <div v-show="openSections.settings" class="fb-section-body">
                                <div class="form-row">
                                    <label>Submit button text</label>
                                    <input v-model="editor.submit_button_text" type="text" maxlength="100" />
                                </div>
                                <div class="form-row">
                                    <label>Success message</label>
                                    <textarea v-model="editor.success_message" rows="2" maxlength="1000"></textarea>
                                    <small class="muted">Shown after submission. Ignored if a redirect URL is set.</small>
                                </div>
                                <div class="form-row">
                                    <label>Redirect URL (optional)</label>
                                    <input v-model="editor.redirect_url" type="url" maxlength="500" placeholder="https://example.com/thanks" />
                                </div>
                                <label class="checkbox-inline">
                                    <input v-model="editor.gdpr_consent_enabled" type="checkbox" />
                                    Require a GDPR consent checkbox
                                </label>
                                <div v-if="editor.gdpr_consent_enabled" class="form-row">
                                    <label>Consent text</label>
                                    <textarea v-model="editor.gdpr_consent_text" rows="2" maxlength="2000"
                                        placeholder="I agree to be contacted about my enquiry."></textarea>
                                </div>
                            </div>
                        </section>

                        <footer class="slide-over-foot">
                            <button type="button" class="btn btn-ghost" @click="editorOpen = false">Cancel</button>
                            <button type="submit" class="btn btn-primary" :disabled="editor.processing">
                                {{ editingId ? 'Save changes' : 'Create form' }}
                            </button>
                        </footer>
                    </form>
                </aside>
            </div>
        </Teleport>

        <!-- Live form preview -->
        <Teleport to="body">
            <div
                v-if="showPreview"
                class="fp-preview-overlay"
                @click.self="showPreview = false"
            >
                <div class="fp-preview-modal">
                    <div class="fp-preview-head">
                        <h3>{{ editor.name || 'Form preview' }}</h3>
                        <button type="button" class="icon-btn" @click="showPreview = false">
                            <IconX :size="18" stroke-width="2" />
                        </button>
                    </div>

                    <div class="fp-preview-body">
                        <p class="fp-preview-note">
                            This is how your form will appear when embedded.
                        </p>

                        <!-- Step selector + All-steps toggle (multi-step only) -->
                        <div v-if="editor.steps.length > 1" class="fp-preview-bar">
                            <div class="fp-preview-steps">
                                <button type="button" class="btn btn-ghost btn-sm" :disabled="showAllSteps || stepPreviewIndex === 0" @click="stepPreviewIndex--">
                                    <IconArrowLeft :size="14" stroke-width="2" /> Prev
                                </button>
                                <span class="fp-preview-steplabel">
                                    <template v-if="showAllSteps">All steps</template>
                                    <template v-else>Step {{ stepPreviewIndex + 1 }} of {{ editor.steps.length }}<template v-if="editor.steps[stepPreviewIndex]?.label"> — {{ editor.steps[stepPreviewIndex].label }}</template></template>
                                </span>
                                <button type="button" class="btn btn-ghost btn-sm" :disabled="showAllSteps || stepPreviewIndex >= editor.steps.length - 1" @click="stepPreviewIndex++">
                                    Next <IconArrowRight :size="14" stroke-width="2" />
                                </button>
                            </div>
                            <button type="button" class="btn btn-ghost btn-sm" @click="showAllSteps = !showAllSteps">
                                {{ showAllSteps ? 'Per step' : 'All steps' }}
                            </button>
                        </div>

                        <div class="fp-preview-form">
                            <FormFieldRenderer
                                v-for="(field, i) in previewFields"
                                :key="i"
                                :field="field"
                                :readonly="true"
                            />

                            <button type="button" class="fp-submit-btn" disabled>
                                {{ editor.submit_button_text || 'Submit' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <ConfirmModal
            v-model:show="confirmDelete"
            variant="danger"
            :title="`Delete ${deleteTarget?.name}?`"
            message="This form will be permanently removed. Forms with submissions can't be deleted — set them to inactive instead."
            confirm-label="Delete"
            @confirm="doDelete"
        />

        <ConfirmModal
            v-model:show="confirmRemoveStep"
            variant="danger"
            title="Remove step?"
            message="Fields on this step will also be removed."
            confirm-label="Remove"
            @confirm="doRemoveStep"
        />
    </InternalLayout>
</template>

<style scoped>
/* Step tabs — built on the shared .tabs/.tab primitive; only the per-step
   remove (×), the rail, and the "+ Step" affordance are new. */
.fb-step-tabs { display: flex; align-items: center; gap: 8px; border-bottom: 1px solid var(--border-soft); margin-bottom: 14px; }
.fb-step-tabs .tabs { flex: 1; flex-wrap: wrap; }
.fb-step-x { display: inline-grid; place-items: center; width: 16px; height: 16px; margin-left: 4px; border-radius: 4px; color: var(--text-tertiary); }
.fb-step-x:hover { background: var(--neutral-bg); color: var(--danger); }
.fb-step-x.is-disabled { opacity: .35; pointer-events: none; }
.fb-step-add { flex-shrink: 0; }
.fb-step-label-row { margin-bottom: 14px; }
.fb-step-empty { margin-bottom: 14px; }

/* Validation error banner — red-tinted, surfaces 422 errors in the slide-over. */
.fb-error-banner { background: var(--danger-bg); border: 1px solid var(--danger); border-radius: var(--radius-md); padding: 12px 14px; margin-bottom: 16px; }
.fb-error-banner-title { font-weight: 700; margin: 0 0 6px; color: var(--danger); font-size: .875rem; }
.fb-error-banner-list { margin: 0; padding-left: 1rem; font-size: .875rem; color: var(--text-primary); }

/* Preview step-selector bar. */
.fp-preview-bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--border-soft); }
.fp-preview-steps { display: flex; align-items: center; gap: 10px; }
.fp-preview-steplabel { font: 600 13px/1.3 'Inter', sans-serif; color: var(--text-primary); white-space: nowrap; }
</style>
