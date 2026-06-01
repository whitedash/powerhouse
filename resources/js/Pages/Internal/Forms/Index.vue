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
    IconDots, IconTrash, IconEdit, IconArrowRight, IconChevronUp, IconChevronDown,
    IconPencil,
    IconLetterT, IconMail, IconPhone, IconAlignLeft, IconCheckbox, IconCircleDot,
    IconNumbers, IconCalendar,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const props = defineProps({
    forms: { type: Array, required: true },
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
};
function fieldIcon(type) {
    return FIELD_ICONS[type] ?? IconLetterT;
}
function fieldTypeLabel(type) {
    return FIELD_TYPES.find(t => t.value === type)?.label ?? type;
}
// Types whose `options` list is meaningful.
const OPTION_TYPES = ['select', 'radio', 'checkbox'];

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
        fields: card.fields.map((f, i) => ({
            label: f.label,
            field_key: f.field_key,
            type: f.type,
            placeholder: f.placeholder,
            default_value: f.default_value,
            options: f.options,
            is_required: f.is_required,
        })),
    };
}

/* ─── Builder slide-over ─── */
const editorOpen = ref(false);
const editingId = ref(null);
const editor = useForm(emptyEditor());

/* ─── Live preview modal ─── */
// Renders the current (unsaved) builder state exactly as the embedded
// form would appear. Reads editor.fields directly so it tracks edits.
const showPreview = ref(false);
const previewFields = computed(() => editor.fields ?? []);

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
    return {
        label: '', field_key: '', type,
        placeholder: '', default_value: '',
        options: null, options_raw: '', is_required: false,
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
        fields: [
            { label: 'First name', field_key: 'first_name', type: 'text', placeholder: '', default_value: '', options: null, options_raw: '', is_required: true },
            { label: 'Email', field_key: 'email', type: 'email', placeholder: '', default_value: '', options: null, options_raw: '', is_required: true },
        ],
    };
}

function openCreate() {
    editingId.value = null;
    editor.clearErrors();
    Object.assign(editor, emptyEditor());
    editingFieldIndex.value = null;
    editorOpen.value = true;
}
function openEdit(form) {
    editingId.value = form.id;
    editor.clearErrors();
    Object.assign(editor, formPayloadFromCard(form));
    // Seed each field's options_raw from the stored array so the textarea
    // shows existing options, one per line.
    editor.fields = form.fields.map(f => ({
        ...f,
        options: f.options ? [...f.options] : null,
        options_raw: (f.options ?? []).join('\n'),
    }));
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
    editor.fields.push(blankField(type));
    // Open the new field for editing straight away.
    editingFieldIndex.value = editor.fields.length - 1;
}
function removeField(i) {
    editor.fields.splice(i, 1);
    if (editingFieldIndex.value === i) {
        editingFieldIndex.value = null;
    } else if (editingFieldIndex.value !== null && editingFieldIndex.value > i) {
        editingFieldIndex.value -= 1;
    }
}
function moveField(i, delta) {
    const j = i + delta;
    if (j < 0 || j >= editor.fields.length) return;
    const [item] = editor.fields.splice(i, 1);
    editor.fields.splice(j, 0, item);
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
    // which would strip a newline the moment Enter is pressed).
    editor
        .transform((data) => ({
            ...data,
            fields: data.fields.map((f) => ({
                label: f.label,
                field_key: f.field_key,
                type: f.type,
                placeholder: f.placeholder,
                default_value: f.default_value,
                is_required: f.is_required,
                options: OPTION_TYPES.includes(f.type)
                    ? (f.options_raw ?? '')
                        .split('\n')
                        .map((o) => o.trim())
                        .filter((o) => o.length > 0)
                    : null,
            })),
        }))
        [method](endpoint, {
            preserveScroll: true,
            onSuccess: () => { editorOpen.value = false; },
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
                            <button type="button" class="btn btn-ghost btn-sm" @click="showPreview = true">
                                <IconEye :size="14" stroke-width="2" /> Preview
                            </button>
                            <button type="button" class="icon-btn" @click="editorOpen = false">
                                <IconX :size="18" stroke-width="2" />
                            </button>
                        </div>
                    </header>

                    <form class="slide-over-body" @submit.prevent="save">
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
                            </div>
                        </section>

                        <!-- Fields builder -->
                        <section class="form-section">
                            <div class="form-section-head">
                                <h3 class="form-section-title">
                                    Fields
                                    <span class="fb-count-badge">{{ editor.fields.length }}</span>
                                </h3>
                            </div>

                            <div v-if="editor.fields.length === 0" class="muted small">No fields yet. Pick a type below to add one.</div>

                            <template v-for="(field, i) in editor.fields" :key="i">
                                <!-- Field card -->
                                <div class="fb-field-card" :class="{ open: editingFieldIndex === i }">
                                    <div class="fb-reorder">
                                        <button type="button" class="icon-btn" :disabled="i === 0" title="Move up" @click="moveField(i, -1)">
                                            <IconChevronUp :size="13" stroke-width="2" />
                                        </button>
                                        <button type="button" class="icon-btn" :disabled="i === editor.fields.length - 1" title="Move down" @click="moveField(i, 1)">
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

                                    <div class="fb-toggle-row">
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
                            <div class="fb-add-label muted small">Add a field</div>
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

                        <div class="fp-preview-form">
                            <div
                                v-for="(field, i) in previewFields"
                                :key="i"
                                class="fp-field"
                            >
                                <label class="fp-label">
                                    {{ field.label }}
                                    <span v-if="field.is_required" class="fp-required">*</span>
                                </label>

                                <input
                                    v-if="['text', 'email', 'phone', 'number', 'date'].includes(field.type)"
                                    :type="field.type === 'phone' ? 'tel' : field.type"
                                    :placeholder="field.placeholder"
                                    class="fp-input"
                                    disabled
                                />

                                <textarea
                                    v-else-if="field.type === 'textarea'"
                                    :placeholder="field.placeholder"
                                    class="fp-input fp-textarea"
                                    disabled
                                ></textarea>

                                <select
                                    v-else-if="field.type === 'select' || field.type === 'radio'"
                                    class="fp-input"
                                    disabled
                                >
                                    <option value="">{{ field.placeholder || 'Select…' }}</option>
                                    <option v-for="(opt, oi) in fieldOptions(field)" :key="oi">{{ opt }}</option>
                                </select>

                                <label
                                    v-else-if="field.type === 'checkbox'"
                                    class="fp-check-label"
                                >
                                    <input type="checkbox" disabled />
                                    {{ field.placeholder || field.label }}
                                </label>
                            </div>

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
    </InternalLayout>
</template>
