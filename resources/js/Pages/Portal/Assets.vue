<script setup>
/**
 * Customer-facing READ-ONLY asset overview (Stage 4): Websites (+ hosting),
 * Domains and Services. PortalLayout exposes only the #desktop / #mobile named
 * slots (no default slot), so the content must live in both.
 */
import { Head } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import AssetSections from '@/Components/Portal/AssetSections.vue';

defineProps({
    websites: { type: Array, default: () => [] },
    domains: { type: Array, default: () => [] },
    services: { type: Array, default: () => [] },
});

const counts = { invoices: undefined, support: undefined };
</script>

<template>
    <Head title="My Assets" />

    <PortalLayout active-nav="assets" :counts="counts">
        <!-- ═══════════ DESKTOP ═══════════ -->
        <template #desktop>
            <div class="content">
                <header class="page-head">
                    <h1>My assets</h1>
                    <p>Your websites, domains and services with us.</p>
                </header>
                <AssetSections :websites="websites" :domains="domains" :services="services" />
            </div>
        </template>

        <!-- ═══════════ MOBILE ═══════════ -->
        <template #mobile>
            <div class="m-appbar">
                <div class="title">My assets</div>
                <div class="spacer" />
            </div>
            <div class="m-body is-pb">
                <AssetSections :websites="websites" :domains="domains" :services="services" />
            </div>
        </template>
    </PortalLayout>
</template>

<style scoped>
.page-head h1 { font: 700 22px/1.2 'Inter', sans-serif; margin: 0 0 4px; }
.page-head p { color: var(--text-secondary, #64748b); margin: 0 0 18px; font-size: 14px; }
</style>
