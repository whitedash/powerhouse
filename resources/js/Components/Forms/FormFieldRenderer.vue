<script setup>
/**
 * Shared single-field renderer for the builder live-preview (readonly) and the
 * Portal respondent page (two-way bound). The embed widget stays vanilla JS and
 * does NOT use this component.
 *
 * modelValue === null is "preview mode" — nothing is bound, inputs sit empty
 * and the required asterisk is shown. In fill mode the parent binds a value.
 *
 * Styling is component-scoped and token-only (no global `.input`/`.radio-group`
 * primitive exists; inputs are styled contextually elsewhere) so the field looks
 * identical inside the preview modal and the `.wd` Portal scope. This is a field
 * renderer, not a panel — it carries NO card wrapper (audit:sections).
 */
import { computed } from 'vue';
import DOMPurify from 'dompurify';

const props = defineProps({
    field: { type: Object, required: true },
    // null = read-only/preview mode. Number included for number-type answers.
    modelValue: { type: [String, Number, Array, Boolean], default: null },
    readonly: { type: Boolean, default: false },
});
defineEmits(['update:modelValue']);

// Same type branches as the original builder-preview inline renderer.
const TEXT_INPUT_TYPES = ['text', 'email', 'phone', 'number', 'date'];

const inputType = computed(() => (props.field.type === 'phone' ? 'tel' : props.field.type));

const widthClass = computed(() => ({
    full: 'col-span-12',
    half: 'col-span-6',
    third: 'col-span-4',
}[props.field.width] ?? 'col-span-12'));

const options = computed(() => props.field.options ?? []);

// Asterisk only in preview mode (modelValue null = nothing bound).
const showRequiredMark = computed(() => props.field.is_required && props.modelValue === null);

// Output sanitisation for placeholder/text-block content — second barrier
// (the builder also sanitises on input). Same allowlist as the deleted
// mews/purifier 'placeholder' profile.
const PLACEHOLDER_SANITISE_CONFIG = {
    ALLOWED_TAGS: ['p', 'br', 'strong', 'em', 'u', 'ul', 'ol', 'li', 'a'],
    ALLOWED_ATTR: ['href', 'target', 'rel'],
    ALLOWED_URI_REGEXP: /^(https?:|mailto:)/i,
};
const sanitisedContent = computed(() =>
    DOMPurify.sanitize(props.field.content || '', PLACEHOLDER_SANITISE_CONFIG),
);
</script>

<template>
    <div v-if="field.type !== 'hidden'" class="ffr-field" :class="widthClass">
        <label v-if="field.type !== 'placeholder'" class="ffr-label">
            {{ field.label }}
            <span v-if="showRequiredMark" class="ffr-req">*</span>
        </label>

        <!-- Placeholder: content sanitised by DOMPurify at input (builder) and output (here) -->
        <div v-if="field.type === 'placeholder'" class="ffr-placeholder-text" v-html="sanitisedContent"></div>

        <input
            v-if="TEXT_INPUT_TYPES.includes(field.type)"
            class="ffr-input"
            :type="inputType"
            :value="modelValue ?? ''"
            :placeholder="field.placeholder"
            :disabled="readonly"
            @input="$emit('update:modelValue', $event.target.value)"
        />

        <textarea
            v-else-if="field.type === 'textarea'"
            class="ffr-input ffr-textarea"
            :value="modelValue ?? ''"
            :placeholder="field.placeholder"
            :disabled="readonly"
            @input="$emit('update:modelValue', $event.target.value)"
        ></textarea>

        <select
            v-else-if="field.type === 'select'"
            class="ffr-input ffr-select"
            :value="modelValue ?? ''"
            :disabled="readonly"
            @change="$emit('update:modelValue', $event.target.value)"
        >
            <option value="">{{ field.placeholder || 'Choose…' }}</option>
            <option v-for="(opt, i) in options" :key="i" :value="opt">{{ opt }}</option>
        </select>

        <div v-else-if="field.type === 'radio'" class="ffr-radio-group">
            <label v-for="(opt, i) in options" :key="i" class="ffr-radio">
                <input
                    type="radio"
                    :name="field.field_key"
                    :value="opt"
                    :checked="modelValue === opt"
                    :disabled="readonly"
                    @change="$emit('update:modelValue', opt)"
                />
                <span>{{ opt }}</span>
            </label>
        </div>

        <label v-else-if="field.type === 'checkbox'" class="ffr-check">
            <input
                type="checkbox"
                :checked="!!modelValue"
                :disabled="readonly"
                @change="$emit('update:modelValue', $event.target.checked)"
            />
            <span>{{ field.placeholder || field.label }}</span>
        </label>
    </div>
</template>

<style scoped>
.ffr-field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
.ffr-label { font: 500 12px/1.4 'Inter', sans-serif; color: var(--text-secondary); }
.ffr-req { color: var(--danger); margin-left: 2px; }
.ffr-input {
    width: 100%; font: 400 14px/1.4 'Inter', sans-serif; color: var(--text-primary);
    background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius-md);
    padding: 9px 12px; outline: 0; transition: border-color .15s, box-shadow .15s;
}
.ffr-input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 18%, transparent); }
.ffr-input:disabled { background: var(--neutral-bg); color: var(--text-secondary); cursor: not-allowed; }
.ffr-textarea { min-height: 80px; resize: vertical; }
.ffr-select { appearance: none; }
.ffr-radio-group { display: flex; flex-direction: column; gap: 8px; }
.ffr-radio, .ffr-check { display: flex; align-items: center; gap: 8px; font: 400 14px/1.4 'Inter', sans-serif; color: var(--text-primary); cursor: pointer; }
.ffr-radio input, .ffr-check input { width: auto; margin: 0; }
.ffr-placeholder-text { color: var(--text-secondary); font-size: .9375rem; line-height: 1.6; padding: .25rem 0; }
.ffr-placeholder-text :deep(a) { color: var(--accent); text-decoration: underline; }
.ffr-placeholder-text :deep(ul), .ffr-placeholder-text :deep(ol) { padding-left: 1.25rem; margin: .25rem 0; }
.ffr-placeholder-text :deep(p) { margin: 0 0 .5rem; }
.ffr-placeholder-text :deep(p:last-child) { margin-bottom: 0; }
.col-span-12 { grid-column: span 12; }
.col-span-6 { grid-column: span 6; }
.col-span-4 { grid-column: span 4; }
</style>
