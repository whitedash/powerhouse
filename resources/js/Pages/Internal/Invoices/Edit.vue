<script setup>
import Create from './Create.vue';

defineProps({
    invoice: { type: Object, required: true },
    customers: { type: Array, default: () => [] },
    billing_entities: { type: Array, default: () => [] },
    next_number: { type: String, required: true },
    today: { type: String, required: true },
    default_due_date: { type: String, required: true },
    vat_rates: { type: Array, default: () => [0, 5, 20] },
    payment_terms: { type: Array, default: () => [] },
    types: { type: Array, default: () => [] },
    preselected_customer_id: { type: Number, default: null },
});
</script>

<template>
    <Create mode="edit" v-bind="$props" />
</template>
