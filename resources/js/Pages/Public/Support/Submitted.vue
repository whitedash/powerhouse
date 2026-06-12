<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { IconCircleCheck, IconArrowRight } from '@tabler/icons-vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';

defineProps({
    reference: { type: [Number, String], required: true },
});
</script>

<template>
    <Head title="Request received · Whitedash" />

    <PublicLayout>
        <section class="done">
            <section class="done-card card">
                <div class="done-check"><IconCircleCheck :size="48" stroke-width="1.5" /></div>
                <h1>Thanks — we've got it</h1>
                <p class="done-lead">
                    Your request has been logged and our team will reply by email.
                    There's nothing more you need to do.
                </p>
                <div class="done-ref">
                    <span class="done-ref-label">Your reference</span>
                    <span class="done-ref-value">#{{ reference }}</span>
                </div>
                <p class="done-note">We've also emailed you a copy. You can reply to that email to add anything to your ticket.</p>
                <Link href="/kb" class="done-link">Browse the knowledge base <IconArrowRight :size="14" stroke-width="1.75" /></Link>
            </section>
        </section>
    </PublicLayout>
</template>

<style scoped>
.done { max-width: 560px; margin: 0 auto; padding: 64px 32px; }
.done-card { padding: 40px 32px; text-align: center; }
.done-check { color: var(--success); margin-bottom: 8px; }
.done-card h1 { font-size: 26px; font-weight: 700; margin: 0 0 10px; }
.done-lead { color: var(--text-secondary); line-height: 1.6; margin: 0 0 20px; }
.done-ref {
    display: inline-flex; flex-direction: column; gap: 2px; padding: 14px 28px; margin: 0 0 20px;
    background: var(--neutral-bg); border-radius: var(--radius-lg);
}
.done-ref-label { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-secondary); }
.done-ref-value { font-size: 22px; font-weight: 700; color: var(--text-primary); }
.done-note { font-size: 13px; color: var(--text-tertiary); line-height: 1.55; margin: 0 0 20px; }
.done-link { display: inline-flex; align-items: center; gap: 6px; font-size: 13.5px; font-weight: 600; color: var(--accent); text-decoration: none; }
.done-link:hover { text-decoration: underline; }
</style>
