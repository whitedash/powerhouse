<script setup>
import { computed } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: true },
    tone: { type: String, default: 'success' },
});

const styles = computed(() => {
    const map = {
        success: { bg: 'var(--success-bg)', fg: 'var(--success)' },
        warning: { bg: 'var(--warning-bg)', fg: 'var(--warning)' },
        danger: { bg: 'var(--danger-bg)', fg: 'var(--danger)' },
        info: { bg: 'var(--info-bg)', fg: 'var(--info)' },
    };
    return map[props.tone] || map.info;
});
</script>

<template>
    <transition
        enter-active-class="transition ease-out duration-150"
        enter-from-class="translate-y-2 opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition ease-in duration-100"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
    >
        <div
            v-if="open"
            class="fixed bottom-4 right-4 flex items-start gap-2 rounded px-4 py-3 text-sm shadow"
            :style="{
                background: styles.bg,
                color: styles.fg,
                boxShadow: 'var(--shadow-md)',
                borderRadius: 'var(--radius-md)',
            }"
        >
            <slot />
        </div>
    </transition>
</template>
