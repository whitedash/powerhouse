<script setup>
/**
 * A reusable "value source" control for an action parameter: the value comes
 * either from a Static input (provided by the parent via the #static slot) or
 * from a Form field (a dual-mode picker mirroring ConditionsEditor — a <select>
 * over the type-filtered fields when present, else a free-text key input).
 *
 * v-model is the descriptor { source: 'static'|'field', static, field_key }.
 * Mutated in place (same idiom as ConditionsEditor / the actions list).
 */
const props = defineProps({
    modelValue: { type: Object, required: true },
    // Type-filtered selectedFormFields ([{ key, label, type }]); empty => free text.
    fields: { type: Array, default: () => [] },
    staticLabel: { type: String, default: 'Static' },
    fieldLabel: { type: String, default: 'Form field' },
});
defineEmits(['update:modelValue']);

function setSource(s) {
    props.modelValue.source = s;
}
</script>

<template>
    <div class="wf-vs">
        <div class="wf-vs-toggle">
            <button type="button" :class="['wf-vs-tab', { active: (modelValue.source || 'static') !== 'field' }]" @click="setSource('static')">{{ staticLabel }}</button>
            <button type="button" :class="['wf-vs-tab', { active: modelValue.source === 'field' }]" @click="setSource('field')">{{ fieldLabel }}</button>
        </div>

        <div v-if="modelValue.source === 'field'" class="wf-vs-body">
            <select v-if="fields.length" v-model="modelValue.field_key" class="wf-vs-field">
                <option value="">Select field…</option>
                <option v-for="f in fields" :key="f.key" :value="f.key">{{ f.label }} ({{ f.key }})</option>
            </select>
            <input v-else v-model="modelValue.field_key" type="text" class="wf-vs-field" placeholder="field_key" />
        </div>
        <div v-else class="wf-vs-body">
            <slot name="static" />
        </div>
    </div>
</template>
