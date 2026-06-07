<script setup>
/**
 * Saved-cards list (Billing P1). Display + actions only — never any secret or
 * full PAN, just the safe meta the controller serialised. Emits to the parent
 * page, which owns the add-card modal + the router calls.
 */
defineProps({
    paymentMethods: { type: Array, default: () => [] },
});

defineEmits(['add', 'set-default', 'remove']);
</script>

<template>
    <section class="pm-wrap">
        <header class="pm-head">
            <div>
                <h2>Payment methods</h2>
                <p>Saved cards for paying invoices. We never store your full card number.</p>
            </div>
            <button type="button" class="btn btn-primary" @click="$emit('add')"><i class="ti ti-plus" />Add a card</button>
        </header>

        <div v-if="paymentMethods.length === 0" class="card card-pad pm-empty">
            No saved cards yet. Add one to make paying invoices quicker.
        </div>

        <div v-else class="pm-list">
            <div v-for="pm in paymentMethods" :key="pm.id" class="card card-pad pm-row">
                <div class="pm-icon"><i class="ti ti-credit-card" /></div>
                <div class="pm-info">
                    <div class="pm-label">{{ pm.label }}<span v-if="pm.is_default" class="badge badge-active pm-default">Default</span></div>
                </div>
                <div class="pm-actions">
                    <button v-if="! pm.is_default" type="button" class="ghost-link" @click="$emit('set-default', pm.id)">Make default</button>
                    <button type="button" class="ghost-link pm-remove" @click="$emit('remove', pm.id)">Remove</button>
                </div>
            </div>
        </div>
    </section>
</template>

<style scoped>
.pm-wrap { max-width: 760px; }
.pm-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.pm-head h2 { font: 700 18px/1.2 'Inter', sans-serif; margin: 0 0 4px; }
.pm-head p { font: 400 13px/1.45 'Inter', sans-serif; color: var(--text-secondary, #64748b); margin: 0; }
.pm-empty { color: var(--text-secondary, #64748b); font-size: 14px; }
.pm-list { display: flex; flex-direction: column; gap: 12px; }
.pm-row { display: flex; align-items: center; gap: 14px; }
.pm-icon { font-size: 20px; color: var(--text-tertiary, #94a3b8); }
.pm-info { flex: 1; }
.pm-label { font: 600 14px/1.3 'Inter', sans-serif; display: flex; align-items: center; gap: 8px; }
.pm-default { font-size: 11px; }
.pm-actions { display: flex; align-items: center; gap: 14px; }
.pm-remove { color: var(--danger, #ef4444); }
</style>
