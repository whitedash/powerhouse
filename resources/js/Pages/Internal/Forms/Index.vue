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
    IconGripVertical,
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
            { label: 'First name', field_key: 'first_name', type: 'text', placeholder: '', default_value: '', options: null, is_required: true },
            { label: 'Email', field_key: 'email', type: 'email', placeholder: '', default_value: '', options: null, is_required: true },
        ],
    };
}

function openCreate() {
    editingId.value = null;
    editor.clearErrors();
    Object.assign(editor, emptyEditor());
    editorOpen.value = true;
}
function openEdit(form) {
    editingId.value = form.id;
    editor.clearErrors();
    Object.assign(editor, formPayloadFromCard(form));
    editor.fields = form.fields.map(f => ({ ...f, options: f.options ? [...f.options] : null }));
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

function addField() {
    editor.fields.push({
        label: '', field_key: '', type: 'text',
        placeholder: '', default_value: '', options: null,
        is_required: false,
    });
}
function removeField(i) {
    editor.fields.splice(i, 1);
}
function moveField(i, delta) {
    const j = i + delta;
    if (j < 0 || j >= editor.fields.length) return;
    const [item] = editor.fields.splice(i, 1);
    editor.fields.splice(j, 0, item);
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

    editor[method](endpoint, {
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
            <div v-if="editorOpen" class="modal-overlay" @click.self="editorOpen = false">
                <aside class="slide-over slide-over-wide" role="dialog">
                    <header class="slide-over-head">
                        <h2>{{ editingId ? 'Edit form' : 'New form' }}</h2>
                        <button type="button" class="icon-btn" @click="editorOpen = false">
                            <IconX :size="18" stroke-width="2" />
                        </button>
                    </header>

                    <form class="slide-over-body" @submit.prevent="save">
                        <!-- Basics -->
                        <section class="form-section">
                            <h3 class="form-section-title">Basics</h3>
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
                        </section>

                        <!-- Fields builder -->
                        <section class="form-section">
                            <div class="form-section-head">
                                <h3 class="form-section-title">Fields</h3>
                                <button type="button" class="btn btn-ghost btn-sm" @click="addField">
                                    <IconPlus :size="14" stroke-width="2" /> Add field
                                </button>
                            </div>
                            <div v-if="editor.fields.length === 0" class="muted small">No fields yet. Add at least one.</div>
                            <div v-for="(field, i) in editor.fields" :key="i" class="field-builder-row">
                                <div class="field-builder-handle">
                                    <button type="button" class="icon-btn" :disabled="i === 0" @click="moveField(i, -1)">
                                        <IconChevronUp :size="14" stroke-width="2" />
                                    </button>
                                    <button type="button" class="icon-btn" :disabled="i === editor.fields.length - 1" @click="moveField(i, 1)">
                                        <IconChevronDown :size="14" stroke-width="2" />
                                    </button>
                                </div>
                                <div class="field-builder-grid">
                                    <div class="form-row">
                                        <label class="small">Label</label>
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
                                    <div v-if="field.type === 'select' || field.type === 'radio'" class="form-row form-row-wide">
                                        <label class="small">Options (one per line)</label>
                                        <textarea
                                            :value="(field.options || []).join('\n')"
                                            rows="3"
                                            @input="field.options = $event.target.value.split('\n').map(s => s.trim()).filter(Boolean)"
                                        ></textarea>
                                    </div>
                                    <label class="checkbox-inline">
                                        <input v-model="field.is_required" type="checkbox" /> Required
                                    </label>
                                </div>
                                <button type="button" class="icon-btn danger" @click="removeField(i)">
                                    <IconTrash :size="14" stroke-width="2" />
                                </button>
                            </div>
                        </section>

                        <!-- After-submit -->
                        <section class="form-section">
                            <h3 class="form-section-title">After submission</h3>
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
                        </section>

                        <!-- GDPR -->
                        <section class="form-section">
                            <h3 class="form-section-title">GDPR consent</h3>
                            <label class="checkbox-inline">
                                <input v-model="editor.gdpr_consent_enabled" type="checkbox" />
                                Require a consent checkbox
                            </label>
                            <div v-if="editor.gdpr_consent_enabled" class="form-row">
                                <label>Consent text</label>
                                <textarea v-model="editor.gdpr_consent_text" rows="2" maxlength="2000"
                                    placeholder="I agree to be contacted about my enquiry."></textarea>
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
