<script setup>
defineProps({
    title: { type: String, default: null },
    padded: { type: Boolean, default: true },
});
</script>

<template>
    <section
        class="rounded-lg"
        style="
            background: var(--card-bg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
            border-radius: var(--radius-lg);
        "
    >
        <header
            v-if="title || $slots.header"
            class="flex items-center justify-between border-b px-4 py-3"
            style="border-color: var(--border-soft)"
        >
            <h2 class="text-sm font-medium" style="color: var(--text-primary)">
                <slot name="header">{{ title }}</slot>
            </h2>
            <div><slot name="actions" /></div>
        </header>
        <div :class="padded ? 'p-4' : ''"><slot /></div>
    </section>
</template>
