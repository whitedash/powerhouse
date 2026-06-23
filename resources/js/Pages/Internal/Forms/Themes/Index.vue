<script setup>
/**
 * Form themes — the design editor.
 *
 * Reusable visual themes for embeddable forms. Each theme is a partial set
 * of design tokens; a form picks one (or "Default") in the form builder.
 * The widget renders defaults overlaid with the theme's overrides
 * (App\Support\FormThemeTokens), so leaving a control at its default value
 * keeps the built-in look.
 *
 * custom_css is raw CSS injected into the widget's shadow root — the server
 * only sends/accepts it for super_admin (can.manage_custom_css).
 */
import { ref, reactive, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    IconPalette, IconPlus, IconX, IconTrash, IconPencil, IconArrowLeft,
    IconChevronLeft, IconChevronRight, IconAlertTriangle,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';
import { useDirtyClose } from '@/Composables/useDirtyClose';

const props = defineProps({
    themes: { type: Object, required: true },        // paginator
    default_tokens: { type: Object, required: true },
    can: { type: Object, default: () => ({ manage_custom_css: false }) },
});

const breadcrumbs = [
    { label: 'Forms', href: '/forms' },
    { label: 'Themes' },
];

/* ─── Token field definitions ─── */
// Colour tokens — rendered as a swatch (type=color) + a text field (the
// text field is authoritative so values like "transparent" / rgba() work).
const COLOR_FIELDS = [
    { key: 'accent', label: 'Brand / accent (focus)' },
    { key: 'button_bg', label: 'Button background' },
    { key: 'button_bg_hover', label: 'Button hover' },
    { key: 'button_text', label: 'Button text' },
    { key: 'background', label: 'Form background' },
    { key: 'surface', label: 'Input background' },
    { key: 'text', label: 'Text' },
    { key: 'label', label: 'Label' },
    { key: 'border', label: 'Input border' },
    { key: 'focus_ring', label: 'Focus ring' },
    { key: 'error', label: 'Error' },
    { key: 'success_bg', label: 'Success background' },
    { key: 'success_border', label: 'Success border' },
    { key: 'success_text', label: 'Success text' },
];

const editorOpen = ref(false);
const editingId = ref(null);

const editor = useForm({
    name: '',
    tokens: { ...props.default_tokens },
});

function openCreate() {
    editor.clearErrors();
    editingId.value = null;
    editor.name = '';
    // Start from the canonical defaults so every control has a value.
    editor.tokens = { ...props.default_tokens };
    editorOpen.value = true;
}

function openEdit(theme) {
    editor.clearErrors();
    editingId.value = theme.id;
    editor.name = theme.name;
    // Overlay the theme's stored overrides on top of the defaults.
    editor.tokens = { ...props.default_tokens, ...theme.tokens };
    editorOpen.value = true;
}

function save() {
    const endpoint = editingId.value ? `/forms/themes/${editingId.value}` : '/forms/themes';
    const method = editingId.value ? 'put' : 'post';
    editor[method](endpoint, {
        preserveScroll: true,
        onSuccess: () => { editorOpen.value = false; },
    });
}

/* ─── Colour helpers ─── */
function isHex(v) {
    return typeof v === 'string' && /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(v.trim());
}
function swatchValue(key) {
    // type=color needs a hex; fall back to black for non-hex values
    // (transparent / rgba) so the picker still renders.
    return isHex(editor.tokens[key]) ? editor.tokens[key] : '#000000';
}
function setSwatch(key, value) {
    editor.tokens[key] = value;
}

/* ─── Delete ─── */
const confirmDelete = ref(false);
const deleteTarget = ref(null);
function askDelete(theme) {
    deleteTarget.value = theme;
    confirmDelete.value = true;
}
function doDelete() {
    if (! deleteTarget.value) return;
    router.delete(`/forms/themes/${deleteTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => { confirmDelete.value = false; deleteTarget.value = null; },
    });
}

function go(url) {
    if (url) router.get(url, {}, { preserveState: true, preserveScroll: true });
}

const deleteMessage = computed(() => {
    const n = deleteTarget.value?.forms_count ?? 0;
    return n > 0
        ? `${n} form${n === 1 ? '' : 's'} use this theme — they will revert to the default look. This can't be undone.`
        : "This theme isn't used by any form. This can't be undone.";
});

/* ─── Unsaved-changes discard guard (Issue B). Route every close surface
 *     (overlay dismiss, X, Escape, Cancel) through editorGuard.attemptClose. ─── */
const editorGuard = useDirtyClose(() => editor.isDirty, () => { editorOpen.value = false; });
</script>

<template>
    <Head title="Form themes" />

    <InternalLayout title="Form themes" :breadcrumbs="breadcrumbs" active-nav="form-themes">
        <template #topbar-actions>
            <Link href="/forms" class="btn btn-ghost btn-sm">
                <IconArrowLeft :size="14" stroke-width="1.75" /> Back to forms
            </Link>
            <button type="button" class="btn btn-primary btn-sm" @click="openCreate">
                <IconPlus :size="14" stroke-width="2" /> New theme
            </button>
        </template>

        <div class="ft-wrap">
            <p class="muted ft-intro">
                Reusable visual themes for your embeddable forms. A form picks a theme (or “Default”)
                in the builder. <strong>Editing a theme restyles every form using it</strong>; embedded
                forms pick up changes within ~5&nbsp;minutes (the embed script is cached).
            </p>

            <section class="table-card">
                <table v-if="themes.data.length" class="tbl">
                    <thead>
                        <tr>
                            <th>Theme</th>
                            <th style="width: 120px;">Used by</th>
                            <th style="width: 160px;">Created by</th>
                            <th style="width: 110px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="t in themes.data" :key="t.id">
                            <td>
                                <button type="button" class="ft-name" @click="openEdit(t)">
                                    <span class="ft-swatch" :style="{ background: t.tokens.accent || default_tokens.accent }"></span>
                                    {{ t.name }}
                                </button>
                            </td>
                            <td>{{ t.forms_count }} form{{ t.forms_count === 1 ? '' : 's' }}</td>
                            <td class="muted">{{ t.created_by ?? '—' }}</td>
                            <td class="ft-actions">
                                <button type="button" class="icon-btn" title="Edit" @click="openEdit(t)">
                                    <IconPencil :size="15" stroke-width="1.75" />
                                </button>
                                <button type="button" class="icon-btn danger" title="Delete" @click="askDelete(t)">
                                    <IconTrash :size="15" stroke-width="1.75" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div v-else class="ft-empty">
                    <IconPalette :size="28" stroke-width="1.5" />
                    <p>No themes yet. Create one to give your forms a custom look.</p>
                    <button type="button" class="btn btn-primary btn-sm" @click="openCreate">
                        <IconPlus :size="14" stroke-width="2" /> New theme
                    </button>
                </div>

                <div v-if="themes.data.length" class="tbl-foot">
                    <div class="info">Showing <strong>{{ themes.from ?? 0 }} – {{ themes.to ?? 0 }}</strong> of <strong>{{ themes.total }}</strong></div>
                    <div class="right">
                        <button type="button" class="pg-btn" :disabled="! themes.prev_page_url" @click="go(themes.prev_page_url)">
                            <IconChevronLeft :size="14" stroke-width="1.75" /> Previous
                        </button>
                        <button type="button" class="pg-btn" :disabled="! themes.next_page_url" @click="go(themes.next_page_url)">
                            Next <IconChevronRight :size="14" stroke-width="1.75" />
                        </button>
                    </div>
                </div>
            </section>
        </div>

        <!-- Editor slide-over -->
        <div v-if="editorOpen" class="slide-over-overlay" v-overlay-dismiss="editorGuard.attemptClose">
            <div class="slide-over slide-over-wide">
                <div class="slide-over-head">
                    <h2>{{ editingId ? 'Edit theme' : 'New theme' }}</h2>
                    <button type="button" class="icon-btn" @click="editorGuard.attemptClose">
                        <IconX :size="18" stroke-width="1.75" />
                    </button>
                </div>

                <form class="slide-over-body" @submit.prevent="save">
                    <section class="form-section">
                        <div class="form-row">
                            <label>Name <span class="req">*</span></label>
                            <input v-model="editor.name" type="text" maxlength="255" required placeholder="e.g. Ocean, Brand 2026" />
                            <div v-if="editor.errors.name" class="err">{{ editor.errors.name }}</div>
                        </div>
                    </section>

                    <section class="form-section">
                        <div class="form-section-head"><h3 class="form-section-title">Colours</h3></div>
                        <div class="ft-grid">
                            <div v-for="f in COLOR_FIELDS" :key="f.key" class="ft-color">
                                <label>{{ f.label }}</label>
                                <div class="ft-color-row">
                                    <input
                                        type="color"
                                        :value="swatchValue(f.key)"
                                        aria-label="Pick colour"
                                        @input="setSwatch(f.key, $event.target.value)"
                                    />
                                    <input v-model="editor.tokens[f.key]" type="text" maxlength="64" spellcheck="false" />
                                </div>
                            </div>
                        </div>
                        <p class="muted small">Text values like <code>transparent</code> or <code>rgba(…)</code> are allowed; the swatch only sets hex.</p>
                    </section>

                    <section class="form-section">
                        <div class="form-section-head"><h3 class="form-section-title">Typography &amp; shape</h3></div>
                        <div class="form-row">
                            <label>Font family</label>
                            <input v-model="editor.tokens.font_family" type="text" maxlength="255" spellcheck="false" />
                        </div>
                        <div class="ft-grid">
                            <div class="form-row"><label>Base font size</label><input v-model="editor.tokens.font_size" type="text" maxlength="20" placeholder="14px" /></div>
                            <div class="form-row"><label>Corner radius</label><input v-model="editor.tokens.radius" type="text" maxlength="20" placeholder="6px" /></div>
                            <div class="form-row"><label>Border width</label><input v-model="editor.tokens.border_width" type="text" maxlength="20" placeholder="1px" /></div>
                        </div>
                    </section>

                    <section class="form-section">
                        <div class="form-section-head"><h3 class="form-section-title">Form container</h3></div>
                        <div class="ft-grid">
                            <div class="form-row"><label>Padding</label><input v-model="editor.tokens.form_padding" type="text" maxlength="40" placeholder="0" /></div>
                            <div class="form-row"><label>Border width</label><input v-model="editor.tokens.form_border_width" type="text" maxlength="20" placeholder="0" /></div>
                            <div class="form-row"><label>Border radius</label><input v-model="editor.tokens.form_border_radius" type="text" maxlength="20" placeholder="0" /></div>
                            <div class="ft-color">
                                <label>Border colour</label>
                                <div class="ft-color-row">
                                    <input type="color" :value="swatchValue('form_border_color')" aria-label="Pick colour" @input="setSwatch('form_border_color', $event.target.value)" />
                                    <input v-model="editor.tokens.form_border_color" type="text" maxlength="64" spellcheck="false" />
                                </div>
                            </div>
                        </div>
                        <p class="muted small">Styles the box around the whole form. Defaults are 0 (no padding / no border) — identical to an un-styled form.</p>
                    </section>

                    <section class="form-section">
                        <div class="form-section-head"><h3 class="form-section-title">Button</h3></div>
                        <div class="ft-grid">
                            <div class="form-row">
                                <label>Style</label>
                                <select v-model="editor.tokens.button_style">
                                    <option value="solid">Solid</option>
                                    <option value="outline">Outline</option>
                                </select>
                            </div>
                            <div class="form-row">
                                <label>Hover effect</label>
                                <select v-model="editor.tokens.button_hover">
                                    <option value="none">None</option>
                                    <option value="lift">Lift</option>
                                    <option value="glow">Glow</option>
                                    <option value="shine">Shine</option>
                                    <option value="fill">Fill</option>
                                </select>
                            </div>
                            <div class="form-row">
                                <label>Icon</label>
                                <select v-model="editor.tokens.button_icon">
                                    <option value="none">None</option>
                                    <option value="arrow">Arrow</option>
                                    <option value="send">Send</option>
                                    <option value="chevron">Chevron</option>
                                    <option value="check">Check</option>
                                    <option value="download">Download</option>
                                </select>
                            </div>
                            <div class="form-row">
                                <label>Icon position</label>
                                <select v-model="editor.tokens.button_icon_position" :disabled="editor.tokens.button_icon === 'none'">
                                    <option value="leading">Leading</option>
                                    <option value="trailing">Trailing</option>
                                </select>
                            </div>
                            <div class="form-row form-row-check">
                                <label><input v-model="editor.tokens.full_width" type="checkbox" /> Full-width button</label>
                            </div>
                        </div>
                    </section>

                    <section class="form-section">
                        <div class="form-section-head"><h3 class="form-section-title">Header (optional)</h3></div>
                        <div class="form-row">
                            <label>Logo URL</label>
                            <input v-model="editor.tokens.logo_url" type="url" maxlength="500" placeholder="https://…/logo.png" spellcheck="false" />
                            <div v-if="editor.errors['tokens.logo_url']" class="err">{{ editor.errors['tokens.logo_url'] }}</div>
                        </div>
                        <div class="form-row">
                            <label>Heading</label>
                            <input v-model="editor.tokens.heading" type="text" maxlength="120" placeholder="e.g. Get in touch" />
                        </div>
                    </section>

                    <section v-if="can.manage_custom_css" class="form-section">
                        <div class="form-section-head"><h3 class="form-section-title">Custom CSS</h3></div>
                        <div class="ft-css-warn">
                            <IconAlertTriangle :size="15" stroke-width="1.75" />
                            <span>Raw CSS injected into the form widget. Super-admin only. Scoped to the widget’s shadow root.</span>
                        </div>
                        <div class="form-row">
                            <textarea v-model="editor.tokens.custom_css" rows="6" maxlength="5000" spellcheck="false"
                                placeholder=".pw-form button { letter-spacing: .02em; }"></textarea>
                            <div v-if="editor.errors['tokens.custom_css']" class="err">{{ editor.errors['tokens.custom_css'] }}</div>
                        </div>
                    </section>

                    <div class="slide-over-foot">
                        <button type="button" class="btn btn-ghost" @click="editorGuard.attemptClose">Cancel</button>
                        <button type="submit" class="btn btn-primary" :disabled="editor.processing">
                            {{ editor.processing ? 'Saving…' : (editingId ? 'Save theme' : 'Create theme') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <ConfirmModal
            v-model:show="confirmDelete"
            title="Delete theme?"
            :message="deleteMessage"
            confirm-label="Delete"
            variant="danger"
            @confirm="doDelete"
        />

        <!-- Unsaved-changes discard confirmation (Issue B) -->
        <ConfirmModal
            :show="editorGuard.confirmingDiscard"
            variant="warning"
            title="Discard changes?"
            message="You have unsaved changes. Discard them?"
            confirm-label="Discard"
            cancel-label="Keep editing"
            @confirm="editorGuard.confirmDiscard"
            @cancel="editorGuard.cancelDiscard"
        />
    </InternalLayout>
</template>

<style scoped>
.ft-wrap { display: flex; flex-direction: column; gap: 16px; }
.ft-intro { max-width: 70ch; line-height: 1.5; }
.ft-name { display: inline-flex; align-items: center; gap: 10px; background: none; border: none; cursor: pointer; font: inherit; color: var(--accent); font-weight: 600; padding: 0; }
.ft-swatch { width: 16px; height: 16px; border-radius: 4px; border: 1px solid var(--border); display: inline-block; }
.ft-actions { text-align: right; white-space: nowrap; }
.ft-empty { display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 48px 16px; color: var(--text-secondary); text-align: center; }
.ft-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px 20px; }
.ft-color label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px; color: var(--text-secondary); }
.ft-color-row { display: flex; gap: 8px; align-items: center; }
.ft-color-row input[type="color"] { width: 38px; height: 34px; padding: 2px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); cursor: pointer; flex: none; }
.ft-color-row input[type="text"] { flex: 1; min-width: 0; }
.ft-css-warn { display: flex; align-items: flex-start; gap: 8px; font-size: 12.5px; color: var(--text-secondary); background: var(--neutral-bg); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 10px 12px; margin-bottom: 10px; }
.ft-css-warn svg { color: var(--warning, #b45309); flex: none; margin-top: 1px; }
@media (max-width: 640px) {
    .ft-grid { grid-template-columns: 1fr; }
}
</style>
