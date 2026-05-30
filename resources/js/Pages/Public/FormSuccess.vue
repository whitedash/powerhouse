<script setup>
/**
 * Public success page shown when a form submission lands via a
 * full-page POST (i.e. the form was used outside the embed
 * widget, which redirects in-place). Branded but minimal — no
 * sidebar, no auth chrome, just a confirmation card.
 */
import { Head } from '@inertiajs/vue3';
import { IconCircleCheck } from '@tabler/icons-vue';

defineProps({
    message: { type: String, required: true },
    form_name: { type: String, default: null },
});
</script>

<template>
    <Head title="Submission received" />

    <div class="public-form-success">
        <main class="pfs-card">
            <div class="pfs-checkmark">
                <IconCircleCheck :size="64" stroke-width="1.6" />
            </div>

            <h1>Thank you</h1>
            <p class="pfs-lead">{{ message }}</p>
            <p v-if="form_name" class="muted small pfs-form">{{ form_name }}</p>
        </main>
    </div>
</template>

<style scoped>
.public-form-success {
    min-height: 100vh;
    display: grid;
    place-items: center;
    background: #f9fafb;
    padding: 40px 16px;
}
.pfs-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 48px 40px;
    max-width: 480px;
    width: 100%;
    text-align: center;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
}
.pfs-checkmark {
    color: #10b981;
    margin-bottom: 16px;
}
.pfs-card h1 {
    font-size: 24px;
    margin: 0 0 12px;
    color: #111827;
}
.pfs-lead {
    color: #374151;
    line-height: 1.55;
    margin: 0 0 16px;
}
.pfs-form {
    margin-top: 24px;
}
</style>
