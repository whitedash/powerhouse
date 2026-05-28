<script setup>
import InternalLayout from '@/Layouts/InternalLayout.vue';
import Card from '@/Components/UI/Card.vue';

defineProps({ id: [String, Number] });
</script>

<template>
    <InternalLayout>
        <template #title>Customer · {{ id }}</template>
        <Card :title="`Customer #${id}`"><p style="color: var(--text-secondary)">Placeholder.</p></Card>
    </InternalLayout>
</template>
