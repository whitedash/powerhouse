<script setup>
import InternalLayout from '@/Layouts/InternalLayout.vue';

const breadcrumbs = [
    { label: 'Powerhouse', href: '/' },
    { label: 'Workspace' },
    { label: 'Overview' },
];
</script>

<template>
    <InternalLayout title="Overview" :breadcrumbs="breadcrumbs" active-nav="overview">
        <div class="card shadow-sm" style="padding: 24px;">
            <h2 style="margin: 0 0 6px; font: 600 16px/1.2 'Inter', sans-serif;">Dashboard placeholder</h2>
            <p style="margin: 0; color: var(--text-secondary); font-size: 13px;">
                The InternalLayout (sidebar + topbar) is wired against the design system.
                Real overview panels land in the next phase.
            </p>
        </div>
    </InternalLayout>
</template>
