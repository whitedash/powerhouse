<script setup>
defineProps({
    variant: {
        type: String,
        default: 'primary',
        validator: (v) => ['primary', 'secondary', 'danger', 'ghost'].includes(v),
    },
    size: { type: String, default: 'md' },
    type: { type: String, default: 'button' },
});

const styles = {
    primary: 'background: var(--accent); color: white;',
    secondary:
        'background: var(--card-bg); color: var(--text-primary); border: 1px solid var(--border);',
    danger: 'background: var(--danger); color: white;',
    ghost: 'background: transparent; color: var(--text-secondary);',
};

const sizes = {
    sm: 'px-2.5 py-1 text-xs',
    md: 'px-3 py-1.5 text-sm',
    lg: 'px-4 py-2 text-base',
};
</script>

<template>
    <button
        :type="type"
        :class="['inline-flex items-center justify-center gap-2 rounded font-medium transition hover:opacity-90', sizes[size]]"
        :style="styles[variant]"
    >
        <slot />
    </button>
</template>
