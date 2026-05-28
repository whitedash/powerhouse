<script setup>
defineProps({
    tone: {
        type: String,
        default: 'neutral',
        validator: (v) =>
            ['neutral', 'success', 'warning', 'danger', 'info', 'accent'].includes(v),
    },
});

const toneMap = {
    neutral: { bg: 'var(--neutral-bg)', fg: 'var(--text-secondary)' },
    success: { bg: 'var(--success-bg)', fg: 'var(--success)' },
    warning: { bg: 'var(--warning-bg)', fg: 'var(--warning)' },
    danger: { bg: 'var(--danger-bg)', fg: 'var(--danger)' },
    info: { bg: 'var(--info-bg)', fg: 'var(--info)' },
    accent: { bg: 'var(--warning-bg)', fg: 'var(--accent)' },
};
</script>

<template>
    <span
        class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
        :style="{ background: toneMap[tone].bg, color: toneMap[tone].fg }"
    >
        <slot />
    </span>
</template>
