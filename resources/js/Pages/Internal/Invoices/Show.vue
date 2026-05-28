<script setup>
import InternalLayout from '@/Layouts/InternalLayout.vue';
import Card from '@/Components/UI/Card.vue';

defineProps({ id: [String, Number] });
</script>

<template>
    <InternalLayout>
        <template #title>Invoice · {{ id }}</template>
        <Card :title="`Invoice #${id}`"><p style="color: var(--text-secondary)">Placeholder.</p></Card>
    </InternalLayout>
</template>
