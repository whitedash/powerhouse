<script setup>
/**
 * Shared long-form prose wrapper for the public legal pages (Privacy, Terms,
 * Cookies). Holds ONLY the body typography so the three pages don't triplicate
 * it — each page still owns its own <section class="card"> wrapper (the
 * audit:sections rule applies to Pages/Public/). Drop body copy into the
 * default slot.
 */
</script>

<template>
    <div class="legal-prose">
        <slot />
    </div>
</template>

<style scoped>
.legal-prose { color: var(--text-secondary); line-height: 1.7; font-size: 14.5px; }
.legal-prose :deep(h2) { font-size: 17px; font-weight: 700; color: var(--text-primary); margin: 28px 0 10px; }
.legal-prose :deep(h2:first-child) { margin-top: 0; }
.legal-prose :deep(p) { margin: 0 0 14px; }
.legal-prose :deep(ul) { margin: 0 0 14px; padding-left: 20px; }
.legal-prose :deep(li) { margin: 0 0 6px; }
.legal-prose :deep(a) { color: var(--accent); text-decoration: none; }
.legal-prose :deep(a:hover) { text-decoration: underline; }
</style>
