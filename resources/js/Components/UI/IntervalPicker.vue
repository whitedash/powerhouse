<script setup>
import { computed } from 'vue';

const props = defineProps({
    modelValue: {
        type: Object,
        required: true,
        // Shape: { count: Number, unit: 'day' | 'week' | 'month' | 'year' | 'one_time' }
    },
    // Optional whitelist — when set, restricts the unit dropdown
    // and presets to the supplied subset. Used by the recurring
    // invoice picker, which only supports week / month / year.
    allowedUnits: {
        type: Array,
        default: null,
    },
});

const emit = defineEmits(['update:modelValue']);

const ALL_UNITS = [
    { value: 'day', label: 'Day(s)' },
    { value: 'week', label: 'Week(s)' },
    { value: 'month', label: 'Month(s)' },
    { value: 'year', label: 'Year(s)' },
    { value: 'one_time', label: 'One time' },
];
const UNITS = computed(() => {
    if (! props.allowedUnits || ! props.allowedUnits.length) return ALL_UNITS;
    return ALL_UNITS.filter((u) => props.allowedUnits.includes(u.value));
});

const ALL_PRESETS = [
    { key: 'monthly',  label: 'Monthly',   count: 1,  unit: 'month' },
    { key: 'quarterly', label: 'Quarterly', count: 3,  unit: 'month' },
    { key: 'biannual', label: 'Bi-annual', count: 6,  unit: 'month' },
    { key: 'annual',   label: 'Annual',    count: 12, unit: 'month' },
];
const PRESETS = computed(() => {
    if (! props.allowedUnits || ! props.allowedUnits.length) return ALL_PRESETS;
    return ALL_PRESETS.filter((p) => props.allowedUnits.includes(p.unit));
});

function update(patch) {
    emit('update:modelValue', { ...props.modelValue, ...patch });
}

function applyPreset(preset) {
    emit('update:modelValue', { count: preset.count, unit: preset.unit });
}

function isPresetActive(preset) {
    return props.modelValue.count === preset.count && props.modelValue.unit === preset.unit;
}

const previewText = computed(() => {
    const v = props.modelValue;
    if (v.unit === 'one_time') return 'One-time payment.';
    if (! v.count || v.count < 1) return 'Pick a count.';

    const unitMap = {
        day: ['day', 'days'],
        week: ['week', 'weeks'],
        month: ['month', 'months'],
        year: ['year', 'years'],
    };
    const [singular, plural] = unitMap[v.unit] ?? [v.unit, v.unit];
    const labelUnit = v.count === 1 ? singular : `${v.count} ${plural}`;

    if (v.count === 1) {
        return `Bills every ${labelUnit}.`;
    }
    return `Bills every ${labelUnit}.`;
});

const isOneTime = computed(() => props.modelValue.unit === 'one_time');
</script>

<template>
    <div class="interval-picker">
        <div class="interval-inputs">
            <input
                v-if="! isOneTime"
                :value="modelValue.count"
                type="number"
                min="1"
                max="365"
                class="interval-count"
                @input="update({ count: Math.max(1, parseInt($event.target.value, 10) || 1) })"
            >
            <select
                :value="modelValue.unit"
                class="interval-unit"
                @change="update({ unit: $event.target.value, count: $event.target.value === 'one_time' ? 1 : modelValue.count })"
            >
                <option v-for="u in UNITS" :key="u.value" :value="u.value">{{ u.label }}</option>
            </select>
        </div>
        <div class="interval-presets">
            <button
                v-for="p in PRESETS"
                :key="p.key"
                type="button"
                class="interval-preset"
                :class="{ active: isPresetActive(p) }"
                @click="applyPreset(p)"
            >
                {{ p.label }}
            </button>
        </div>
        <div class="interval-preview">{{ previewText }}</div>
    </div>
</template>
