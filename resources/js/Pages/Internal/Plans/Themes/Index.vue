<script setup>
/**
 * Plan themes — the Plans-widget design editor. Mirrors the Forms theme
 * editor's structure and interactions (table-card list, slide-over editor,
 * swatch+text colour rows, dirty-close guard, gated custom CSS) on the
 * plans token vocabulary, PLUS a live pricing-card preview — new here:
 * the Forms editor has no preview to reuse, so this drives a mock card
 * from the reactive token form directly.
 *
 * custom_css is raw CSS injected into the widget's shadow root — the
 * server only sends/accepts it for holders of products.custom_css
 * (can.manage_custom_css).
 */
import { ref, computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    IconPalette, IconPlus, IconX, IconTrash, IconPencil,
    IconChevronLeft, IconChevronRight, IconAlertTriangle,
} from '@tabler/icons-vue';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';
import { useDirtyClose } from '@/Composables/useDirtyClose';

const props = defineProps({
    themes: { type: Object, required: true },        // paginator
    default_tokens: { type: Object, required: true },
    can: { type: Object, default: () => ({ manage_custom_css: false }) },
});

/* ─── Token field definitions (plan vocabulary) ─── */
const COLOR_FIELDS = [
    { key: 'card_bg', label: 'Card background' },
    { key: 'card_border', label: 'Card border' },
    { key: 'price_color', label: 'Price' },
    { key: 'feature_check', label: 'Feature check (✓)' },
    { key: 'muted', label: 'Muted text' },
    { key: 'button_bg', label: 'Button background' },
    { key: 'button_bg_hover', label: 'Button hover' },
    { key: 'button_text', label: 'Button text' },
    { key: 'background', label: 'Widget background' },
    { key: 'surface', label: 'Input background' },
    { key: 'text', label: 'Text' },
    { key: 'accent', label: 'Accent' },
    { key: 'border', label: 'Input border' },
    { key: 'error', label: 'Error' },
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
    editor.tokens = { ...props.default_tokens };
    editorOpen.value = true;
}

function openEdit(theme) {
    editor.clearErrors();
    editingId.value = theme.id;
    editor.name = theme.name;
    editor.tokens = { ...props.default_tokens, ...theme.tokens };
    editorOpen.value = true;
}

function save() {
    const endpoint = editingId.value ? `/settings/plan-themes/${editingId.value}` : '/settings/plan-themes';
    const method = editingId.value ? 'put' : 'post';
    editor[method](endpoint, {
        preserveScroll: true,
        onSuccess: () => { editorOpen.value = false; },
    });
}

/* ─── Colour helpers (forms' swatch idiom) ─── */
function isHex(v) {
    return typeof v === 'string' && /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(v.trim());
}
function swatchValue(key) {
    return isHex(editor.tokens[key]) ? editor.tokens[key] : '#000000';
}
function setSwatch(key, value) {
    editor.tokens[key] = value;
}

/* ─── Live pricing-card preview ───
 * Driven straight off the reactive token form — the same values the
 * widget's :host --pw-* variables receive, expressed as inline styles on
 * a mock card, so every keystroke restyles the preview. custom_css is
 * NOT previewed (it targets the widget's shadow classes). */
const pv = computed(() => {
    const t = editor.tokens;
    return {
        wrap: { background: t.background, fontFamily: t.font_family, color: t.text, padding: '18px', borderRadius: '8px' },
        card: { background: t.card_bg, border: `${t.border_width} solid ${t.card_border}`, borderRadius: t.card_radius, padding: '22px', display: 'flex', flexDirection: 'column', maxWidth: '270px' },
        desc: { fontSize: '13px', color: t.muted, margin: '0 0 14px', lineHeight: 1.5 },
        feature: { fontSize: '13px', padding: '3px 0 3px 20px', position: 'relative' },
        check: { position: 'absolute', left: 0, color: t.feature_check },
        priceRow: { display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', gap: '8px', padding: '8px 0', borderTop: `1px solid ${t.card_border}`, marginTop: 'auto' },
        amount: { fontSize: '18px', fontWeight: 700, color: t.price_color },
        interval: { fontSize: '12px', color: '#94a3b8' },
        btn: { border: 0, borderRadius: t.radius, background: t.button_bg, color: t.button_text, fontSize: t.font_size, fontWeight: 600, padding: '8px 14px', cursor: 'default' },
    };
});

/* ─── Delete ─── */
const confirmDelete = ref(false);
const deleteTarget = ref(null);
function askDelete(theme) {
    deleteTarget.value = theme;
    confirmDelete.value = true;
}
function doDelete() {
    if (! deleteTarget.value) return;
    router.delete(`/settings/plan-themes/${deleteTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => { confirmDelete.value = false; deleteTarget.value = null; },
    });
}

function go(url) {
    if (url) router.get(url, {}, { preserveState: true, preserveScroll: true });
}

const deleteMessage = computed(() => {
    const n = deleteTarget.value?.products_count ?? 0;
    return n > 0
        ? `${n} product${n === 1 ? '' : 's'} use this theme — their widgets will revert to the default look. This can't be undone.`
        : "This theme isn't used by any product. This can't be undone.";
});

/* ─── Unsaved-changes discard guard (forms' idiom) ─── */
const editorGuard = useDirtyClose(() => editor.isDirty, () => { editorOpen.value = false; });
</script>

<template>
    <Head title="Plan themes" />

    <SettingsLayout title="Plan themes" active-section="plan-themes">
        <template #topbar-actions>
            <button type="button" class="btn btn-primary btn-sm" @click="openCreate">
                <IconPlus :size="14" stroke-width="2" /> New theme
            </button>
        </template>

        <div class="pt-wrap">
            <p class="muted pt-intro">
                Reusable visual themes for the embeddable Plans widget. A product picks a theme (or “Default”)
                on Settings → Products; the same tokens also brand the Stripe payment step.
                <strong>Editing a theme restyles every product using it</strong>; embeds pick up changes
                within ~5&nbsp;minutes (the embed script is cached).
            </p>

            <section class="table-card">
                <table v-if="themes.data.length" class="tbl">
                    <thead>
                        <tr>
                            <th>Theme</th>
                            <th style="width: 130px;">Used by</th>
                            <th style="width: 160px;">Created by</th>
                            <th style="width: 110px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="t in themes.data" :key="t.id">
                            <td>
                                <button type="button" class="pt-name" @click="openEdit(t)">
                                    <span class="pt-swatch" :style="{ background: t.tokens.button_bg || default_tokens.button_bg }"></span>
                                    {{ t.name }}
                                </button>
                            </td>
                            <td>{{ t.products_count }} product{{ t.products_count === 1 ? '' : 's' }}</td>
                            <td class="muted">{{ t.created_by ?? '—' }}</td>
                            <td class="pt-actions">
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

                <div v-else class="pt-empty">
                    <IconPalette :size="28" stroke-width="1.5" />
                    <p>No themes yet. Create one to give a product's pricing widget a custom look.</p>
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

        <!-- Editor slide-over: token form + live pricing-card preview -->
        <div v-if="editorOpen" class="slide-over-overlay" v-overlay-dismiss="editorGuard.attemptClose">
            <div class="slide-over slide-over-wide">
                <div class="slide-over-head">
                    <h2>{{ editingId ? 'Edit theme' : 'New theme' }}</h2>
                    <button type="button" class="icon-btn" @click="editorGuard.attemptClose">
                        <IconX :size="18" stroke-width="1.75" />
                    </button>
                </div>

                <form class="slide-over-body" @submit.prevent="save">
                    <div class="pt-editor-grid">
                        <div class="pt-fields">
                            <section class="form-section">
                                <div class="form-row">
                                    <label>Name <span class="req">*</span></label>
                                    <input v-model="editor.name" type="text" maxlength="255" required placeholder="e.g. Ocean, Brand 2026" />
                                    <div v-if="editor.errors.name" class="err">{{ editor.errors.name }}</div>
                                </div>
                            </section>

                            <section class="form-section">
                                <div class="form-section-head"><h3 class="form-section-title">Colours</h3></div>
                                <div class="pt-grid">
                                    <div v-for="f in COLOR_FIELDS" :key="f.key" class="pt-color">
                                        <label>{{ f.label }}</label>
                                        <div class="pt-color-row">
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
                                <p class="muted small">Text values like <code>transparent</code> or <code>rgba(…)</code> are allowed; the swatch only sets hex. The Stripe payment step only inherits hex colours.</p>
                            </section>

                            <section class="form-section">
                                <div class="form-section-head"><h3 class="form-section-title">Typography &amp; shape</h3></div>
                                <div class="form-row">
                                    <label>Font family</label>
                                    <input v-model="editor.tokens.font_family" type="text" maxlength="255" spellcheck="false" />
                                </div>
                                <div class="pt-grid">
                                    <div class="form-row"><label>Base font size</label><input v-model="editor.tokens.font_size" type="text" maxlength="20" placeholder="14px" /></div>
                                    <div class="form-row"><label>Corner radius</label><input v-model="editor.tokens.radius" type="text" maxlength="20" placeholder="8px" /></div>
                                    <div class="form-row"><label>Card radius</label><input v-model="editor.tokens.card_radius" type="text" maxlength="20" placeholder="12px" /></div>
                                    <div class="form-row"><label>Border width</label><input v-model="editor.tokens.border_width" type="text" maxlength="20" placeholder="1px" /></div>
                                </div>
                                <p class="muted small">Corner radius also drives the Stripe payment step's corner style (sharp &lt; 4px, pill ≥ 16px, rounded between).</p>
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
                                    <input v-model="editor.tokens.heading" type="text" maxlength="120" placeholder="e.g. Choose your plan" />
                                </div>
                            </section>

                            <section v-if="can.manage_custom_css" class="form-section">
                                <div class="form-section-head"><h3 class="form-section-title">Custom CSS</h3></div>
                                <div class="pt-css-warn">
                                    <IconAlertTriangle :size="15" stroke-width="1.75" />
                                    <span>Raw CSS injected into the Plans widget. Requires the products.custom_css permission. Scoped to the widget’s shadow root; not shown in the preview.</span>
                                </div>
                                <div class="form-row">
                                    <textarea v-model="editor.tokens.custom_css" rows="6" maxlength="5000" spellcheck="false"
                                        placeholder=".pw-plan { letter-spacing: .02em; }"></textarea>
                                    <div v-if="editor.errors['tokens.custom_css']" class="err">{{ editor.errors['tokens.custom_css'] }}</div>
                                </div>
                            </section>
                        </div>

                        <!-- Live preview: a mock plan card driven by the reactive tokens. -->
                        <aside class="pt-preview">
                            <div class="pt-preview-label">Live preview</div>
                            <div :style="pv.wrap">
                                <img v-if="editor.tokens.logo_url" :src="editor.tokens.logo_url" alt="" style="max-height: 40px; display: block; margin: 0 0 12px;" />
                                <p v-if="editor.tokens.heading" style="font-size: 18px; font-weight: 700; margin: 0 0 14px;">{{ editor.tokens.heading }}</p>
                                <div :style="pv.card">
                                    <h3 style="margin: 0 0 6px; font-size: 17px;">Pro</h3>
                                    <p :style="pv.desc">Everything in Starter, plus room to grow.</p>
                                    <ul style="list-style: none; margin: 0 0 16px; padding: 0;">
                                        <li v-for="f in ['Unlimited venues', 'Priority support', 'API access']" :key="f" :style="pv.feature">
                                            <span :style="pv.check">✓</span>{{ f }}
                                        </li>
                                    </ul>
                                    <div :style="pv.priceRow">
                                        <span>
                                            <span :style="pv.amount">£49.00</span>
                                            <span :style="pv.interval"> Monthly</span>
                                        </span>
                                        <button type="button" :style="pv.btn" tabindex="-1">Choose</button>
                                    </div>
                                </div>
                            </div>
                        </aside>
                    </div>

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
    </SettingsLayout>
</template>

<style scoped>
.pt-wrap { display: flex; flex-direction: column; gap: 16px; }
.pt-intro { max-width: 70ch; line-height: 1.5; }
.pt-name { display: inline-flex; align-items: center; gap: 10px; background: none; border: none; cursor: pointer; font: inherit; color: var(--accent); font-weight: 600; padding: 0; }
.pt-swatch { width: 16px; height: 16px; border-radius: 4px; border: 1px solid var(--border); display: inline-block; }
.pt-actions { text-align: right; white-space: nowrap; }
.pt-empty { display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 48px 16px; color: var(--text-secondary); text-align: center; }
.pt-editor-grid { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 24px; align-items: start; }
.pt-preview { position: sticky; top: 0; border: 1px dashed var(--border); border-radius: var(--radius-md); padding: 14px; background: var(--neutral-bg); }
.pt-preview-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--text-tertiary); margin-bottom: 10px; }
.pt-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px 20px; }
.pt-color label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px; color: var(--text-secondary); }
.pt-color-row { display: flex; gap: 8px; align-items: center; }
.pt-color-row input[type="color"] { width: 38px; height: 34px; padding: 2px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); cursor: pointer; flex: none; }
.pt-color-row input[type="text"] { flex: 1; min-width: 0; }
.pt-css-warn { display: flex; align-items: flex-start; gap: 8px; font-size: 12.5px; color: var(--text-secondary); background: var(--neutral-bg); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 10px 12px; margin-bottom: 10px; }
.pt-css-warn svg { color: var(--warning, #b45309); flex: none; margin-top: 1px; }
@media (max-width: 900px) {
    .pt-editor-grid { grid-template-columns: 1fr; }
    .pt-preview { position: static; }
}
@media (max-width: 640px) {
    .pt-grid { grid-template-columns: 1fr; }
}
</style>
