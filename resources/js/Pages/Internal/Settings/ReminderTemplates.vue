<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    IconDeviceFloppy,
    IconEye,
    IconX,
    IconChevronDown,
} from '@tabler/icons-vue';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';

const props = defineProps({
    templates: { type: Array, default: () => [] },
    available_variables: { type: Array, default: () => [] },
});

// Tone palette is the only place this maps to colours so the
// template-card header can paint chip backgrounds without inlining
// every choice in template.
const TONE_PALETTE = {
    friendly: { bg: 'rgba(16, 185, 129, 0.12)', fg: '#047857' },
    firm: { bg: 'rgba(245, 158, 11, 0.16)', fg: '#B45309' },
    urgent: { bg: 'rgba(239, 68, 68, 0.14)', fg: '#B91C1C' },
    final: { bg: 'rgba(127, 29, 29, 0.18)', fg: '#7F1D1D' },
};
const TIER_PALETTE = {
    due_soon: { bg: 'rgba(16, 185, 129, 0.12)', fg: '#047857' },
    due_today: { bg: 'rgba(99, 102, 241, 0.14)', fg: '#4F46E5' },
    first_reminder: { bg: 'rgba(245, 158, 11, 0.16)', fg: '#B45309' },
    second_reminder: { bg: 'rgba(239, 68, 68, 0.14)', fg: '#B91C1C' },
    final_notice: { bg: 'rgba(127, 29, 29, 0.18)', fg: '#7F1D1D' },
};
const TIER_LABEL = {
    due_soon: 'Due soon',
    due_today: 'Due today',
    first_reminder: 'First reminder',
    second_reminder: 'Second reminder',
    final_notice: 'Final notice',
};

// One useForm per template row keeps `processing` / `errors` scoped
// so a save on row N doesn't disable row M's submit button.
const forms = props.templates.map((t) =>
    useForm({
        id: t.id,
        subject: t.subject,
        body: t.body,
        is_active: !! t.is_active,
    }),
);

function submit(idx) {
    const f = forms[idx];
    f.put(`/settings/reminder-templates/${f.id}`, { preserveScroll: true });
}

const showVarsFor = ref(new Set());
function toggleVars(idx) {
    if (showVarsFor.value.has(idx)) showVarsFor.value.delete(idx);
    else showVarsFor.value.add(idx);
    showVarsFor.value = new Set(showVarsFor.value);
}

// Track the active textarea per template so "click variable to insert"
// drops the token at the caret rather than appending blindly.
const activeBodyRefs = ref([]);
function setBodyRef(idx, el) { activeBodyRefs.value[idx] = el; }

// Vue template can't carry literal {{ — the lexer hijacks it as an
// interpolation opener even inside a JS string. These helpers move
// the brace-construction into JS where it's safe.
function varDisplay(name) {
    return '{' + '{' + name + '}' + '}';
}
function varInsertTitle(name) {
    return 'Insert ' + varDisplay(name);
}

function insertVar(idx, name) {
    const ta = activeBodyRefs.value[idx];
    const placeholder = `{{${name}}}`;
    const f = forms[idx];
    if (! ta) {
        f.body += placeholder;
        return;
    }
    const start = ta.selectionStart ?? f.body.length;
    const end = ta.selectionEnd ?? f.body.length;
    f.body = f.body.slice(0, start) + placeholder + f.body.slice(end);
    // Reset caret to the end of the insertion on next paint.
    setTimeout(() => {
        ta.focus();
        const pos = start + placeholder.length;
        ta.setSelectionRange(pos, pos);
    });
}

/* ─── Preview modal ─── */
const previewing = ref(null); // { name, subject, body, invoice_number, customer_name, note }
const previewLoading = ref(false);

async function openPreview(idx) {
    const t = props.templates[idx];
    previewLoading.value = true;
    try {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        const res = await fetch(`/settings/reminder-templates/${t.id}/preview`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });
        const data = await res.json();
        previewing.value = {
            name: t.name,
            tier: t.tier,
            ...data,
        };
    } catch (e) {
        previewing.value = { name: t.name, subject: '(preview failed)', body: '' };
    } finally {
        previewLoading.value = false;
    }
}
</script>

<template>
    <Head title="Reminder templates" />

    <SettingsLayout title="Reminder templates" active-section="reminder-templates">
        <h1 class="set-title">Reminder templates</h1>
        <p style="color: var(--text-secondary); font: 400 13px/1.6 'Inter', sans-serif; margin-bottom: 18px;">
            The body and subject below are rendered with variable substitution every time a reminder fires. Use the
            <code v-pre>{{variable}}</code> syntax to insert dynamic values. Click <strong>Preview</strong> to see the
            rendered output against the most recent invoice.
        </p>

        <div class="rt-list">
            <article v-for="(t, idx) in templates" :key="t.id" class="rt-card">
                <header class="rt-head">
                    <span
                        class="rt-tier"
                        :style="{ background: TIER_PALETTE[t.tier]?.bg, color: TIER_PALETTE[t.tier]?.fg }"
                    >{{ TIER_LABEL[t.tier] || t.tier }}</span>
                    <div class="rt-title">{{ t.name }}</div>
                    <span
                        class="rt-tone"
                        :style="{ background: TONE_PALETTE[t.tone]?.bg, color: TONE_PALETTE[t.tone]?.fg }"
                    >{{ t.tone }}</span>
                    <label class="rt-active">
                        <input type="checkbox" v-model="forms[idx].is_active">
                        <span>Active</span>
                    </label>
                </header>

                <div class="form-field" style="margin-top: 14px;">
                    <label>Subject</label>
                    <input v-model="forms[idx].subject" type="text" maxlength="255" :class="{ 'has-err': forms[idx].errors.subject }">
                    <div v-if="forms[idx].errors.subject" class="err">{{ forms[idx].errors.subject }}</div>
                </div>

                <div class="form-field" style="margin-top: 12px;">
                    <label>Body</label>
                    <textarea
                        :ref="(el) => setBodyRef(idx, el)"
                        v-model="forms[idx].body"
                        rows="10"
                        maxlength="20000"
                        class="rt-body"
                        :class="{ 'has-err': forms[idx].errors.body }"
                    />
                    <div v-if="forms[idx].errors.body" class="err">{{ forms[idx].errors.body }}</div>
                </div>

                <div class="rt-vars">
                    <button type="button" class="rt-vars-toggle" @click="toggleVars(idx)">
                        <IconChevronDown
                            :size="14"
                            stroke-width="1.75"
                            :class="{ 'rt-chevron-open': showVarsFor.has(idx) }"
                        />
                        Available variables
                    </button>
                    <div v-if="showVarsFor.has(idx)" class="rt-vars-list">
                        <button
                            v-for="v in available_variables"
                            :key="v"
                            type="button"
                            class="rt-var-chip"
                            :title="varInsertTitle(v)"
                            @click="insertVar(idx, v)"
                        >{{ varDisplay(v) }}</button>
                    </div>
                </div>

                <footer class="rt-foot">
                    <button type="button" class="btn btn-secondary btn-sm" :disabled="previewLoading" @click="openPreview(idx)">
                        <IconEye :size="14" stroke-width="1.75" />
                        Preview
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" :disabled="forms[idx].processing" @click="submit(idx)">
                        <IconDeviceFloppy :size="14" stroke-width="1.75" />
                        {{ forms[idx].processing ? 'Saving…' : 'Save changes' }}
                    </button>
                </footer>
            </article>
        </div>

        <!-- Preview modal -->
        <Teleport to="body">
            <div v-if="previewing" class="rt-modal-backdrop" @click.self="previewing = null">
                <div class="rt-modal">
                    <header class="rt-modal-head">
                        <div>
                            <div class="rt-modal-title">{{ previewing.name }} preview</div>
                            <div class="rt-modal-sub">
                                <template v-if="previewing.invoice_number">Rendered against {{ previewing.invoice_number }}<template v-if="previewing.customer_name"> · {{ previewing.customer_name }}</template></template>
                                <template v-else-if="previewing.note">{{ previewing.note }}</template>
                            </div>
                        </div>
                        <button type="button" class="icon-btn" aria-label="Close" @click="previewing = null">
                            <IconX :size="18" stroke-width="1.75" />
                        </button>
                    </header>
                    <div class="rt-modal-body">
                        <div class="rt-preview-field">
                            <div class="rt-preview-label">Subject</div>
                            <div class="rt-preview-subject">{{ previewing.subject }}</div>
                        </div>
                        <div class="rt-preview-field">
                            <div class="rt-preview-label">Body</div>
                            <pre class="rt-preview-body">{{ previewing.body }}</pre>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </SettingsLayout>
</template>
