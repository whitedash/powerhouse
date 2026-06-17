<script setup>
/**
 * Trigger + form-aware placeholder reference for the workflow action editor.
 * Shows which {{keys}} are available based on the workflow's selected trigger
 * (and, for form_submitted, the chosen form's real field_keys). It is NOT
 * per-action — it deliberately does not re-implement the engine's per-action
 * context accumulation; keys added by earlier actions are shown as a static
 * note instead. The `reference` object is computed by the parent.
 */
defineProps({
    reference: { type: Object, required: true },
});
</script>

<template>
    <div class="ph-ref">
        <p v-if="!reference.active" class="muted small ph-note">
            This trigger isn't active yet — it doesn't fire workflows, so no placeholders are available.
        </p>
        <template v-else>
            <p class="muted small ph-syntax">
                Use <code>&#123;&#123;key&#125;&#125;</code> in subject/body templates; type the <strong>bare key</strong> (no braces) in the field-map inputs.
            </p>

            <div class="ph-group">
                <span class="ph-label">System keys</span>
                <span v-for="k in reference.systemKeys" :key="k" class="ph-chip">{{ k }}</span>
            </div>

            <div v-if="reference.formFields && reference.formFields.length" class="ph-group">
                <span class="ph-label">Form fields</span>
                <span v-for="f in reference.formFields" :key="f.key" class="ph-chip" :title="f.label">{{ f.key }}</span>
            </div>
            <p v-else-if="reference.formNote" class="muted small ph-note">{{ reference.formNote }}</p>

            <p class="muted small ph-note">{{ reference.actionNote }}</p>
        </template>
    </div>
</template>

<style scoped>
.ph-ref { display: flex; flex-direction: column; gap: 8px; margin-top: 4px; }
.ph-syntax { margin: 0; }
.ph-note { margin: 0; }
.ph-group { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
.ph-label { font-size: 11px; font-weight: 600; color: var(--text-secondary); margin-right: 2px; }
.ph-chip {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 11.5px;
    color: var(--text-primary);
    background: var(--neutral-bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 1px 6px;
}
</style>
