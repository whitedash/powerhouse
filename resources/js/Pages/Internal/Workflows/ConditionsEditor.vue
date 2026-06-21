<script setup>
import { IconPlus, IconTrash } from '@tabler/icons-vue';

const props = defineProps({
    // Phase 1's stored shape: { logic, groups: [ { logic, conditions: [ { field_key, operator, value } ] } ] }.
    modelValue: { type: Object, required: true },
    // The selected form's fields ([{ key, label }]) or [] — empty => free-text field entry.
    fields: { type: Array, default: () => [] },
    // Operator allowlist (server prop, single-sourced with WorkflowConditionEvaluator::OPERATORS).
    operators: { type: Array, default: () => [] },
});
defineEmits(['update:modelValue']);

// Display labels for the operator <select>, keyed by the server's operator values.
const OPERATOR_LABEL = {
    equals: 'equals',
    not_equals: 'does not equal',
    contains: 'contains',
    not_contains: 'does not contain',
    starts_with: 'starts with',
    ends_with: 'ends with',
    is_empty: 'is empty',
    is_not_empty: 'is not empty',
    greater_than: 'greater than',
    less_than: 'less than',
    greater_than_or_equal: 'greater than or equal',
    less_than_or_equal: 'less than or equal',
};
// Operators that carry no value — the value input is hidden and the value cleared.
const VALUELESS = ['is_empty', 'is_not_empty'];
const needsValue = (op) => ! VALUELESS.includes(op);

function blankCondition() {
    return { field_key: '', operator: props.operators[0] ?? 'equals', value: '' };
}

// Structural helpers mirror the parent's addAction/removeAction: they mutate the
// shared editor.conditions object in place (same reactive ref passed via v-model),
// exactly as the actions list mutates editor.actions.
function addGroup() {
    props.modelValue.groups.push({ logic: 'and', conditions: [blankCondition()] });
}
function addCondition(gi) {
    props.modelValue.groups[gi].conditions.push(blankCondition());
}
function removeCondition(gi, ci) {
    const group = props.modelValue.groups[gi];
    group.conditions.splice(ci, 1);
    // An emptied group gates on nothing, so drop it.
    if (group.conditions.length === 0) {
        props.modelValue.groups.splice(gi, 1);
    }
}

// is_empty / is_not_empty take no value; clear it (restore '' when leaving them).
function onOperatorChange(cond) {
    cond.value = needsValue(cond.operator) ? (cond.value ?? '') : null;
}
</script>

<template>
    <div class="wf-cond">
        <template v-for="(group, gi) in modelValue.groups" :key="gi">
            <div v-if="gi > 0" class="wf-cond-join">OR</div>

            <div class="wf-cond-group">
                <template v-for="(cond, ci) in group.conditions" :key="ci">
                    <div v-if="ci > 0" class="wf-cond-and">AND</div>

                    <div class="wf-cond-row">
                        <!-- Field picker: dropdown of the form's fields, else free text. -->
                        <select v-if="fields.length" v-model="cond.field_key" class="wf-cond-field">
                            <option value="">Select field…</option>
                            <option v-for="f in fields" :key="f.key" :value="f.key">{{ f.label }} ({{ f.key }})</option>
                        </select>
                        <input v-else v-model="cond.field_key" type="text" class="wf-cond-field" placeholder="field_key" />

                        <select v-model="cond.operator" class="wf-cond-op" @change="onOperatorChange(cond)">
                            <option v-for="op in operators" :key="op" :value="op">{{ OPERATOR_LABEL[op] || op }}</option>
                        </select>

                        <!-- Value: hidden for the valueless operators. -->
                        <input v-if="needsValue(cond.operator)" v-model="cond.value" type="text" class="wf-cond-value" placeholder="value" />
                        <span v-else class="wf-cond-value wf-cond-na">—</span>

                        <button type="button" class="icon-btn danger" title="Remove condition" @click="removeCondition(gi, ci)">
                            <IconTrash :size="15" />
                        </button>
                    </div>
                </template>

                <div class="wf-cond-group-foot">
                    <button type="button" class="btn btn-ghost btn-sm" @click="addCondition(gi)">
                        <IconPlus :size="14" /> Add condition
                    </button>
                </div>
            </div>
        </template>

        <button type="button" class="btn btn-ghost btn-sm wf-cond-add-group" @click="addGroup">
            <IconPlus :size="14" /> Add condition group
        </button>
    </div>
</template>
