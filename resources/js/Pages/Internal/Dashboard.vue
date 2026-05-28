<script setup>
import InternalLayout from '@/Layouts/InternalLayout.vue';
import Card from '@/Components/UI/Card.vue';
</script>

<template>
    <InternalLayout>
        <template #title>Dashboard</template>
        <Card title="Powerhouse Overview">
            <p style="color: var(--text-secondary)">
                Welcome — this is the staff dashboard placeholder.
            </p>
        </Card>
    </InternalLayout>
</template>
