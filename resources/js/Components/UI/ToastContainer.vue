<script setup>
import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import {
    IconCheck,
    IconX,
    IconInfoCircle,
    IconAlertTriangle,
} from '@tabler/icons-vue';

/*
 * Global flash → toast bridge.
 *
 * Reads $page.props.flash directly and converts every key it
 * recognises into a stacking toast. Auto-dismisses after 5 seconds
 * (15s for credentials handoffs, where the operator needs time to
 * copy the value).
 *
 * The watcher uses { deep: true } so Inertia partial reloads that
 * refresh `flash` without changing the parent ref still trip the
 * conversion. Each emission gets a monotonic id so the
 * TransitionGroup can identify it for enter/leave animations even
 * after dedupe runs.
 */
const page = usePage();
const toasts = ref([]);
let nextId = 0;

function addToast(type, message, duration = 5000) {
    const id = ++nextId;
    toasts.value.push({ id, type, message });
    if (duration > 0) {
        setTimeout(() => removeToast(id), duration);
    }
}

function removeToast(id) {
    toasts.value = toasts.value.filter((t) => t.id !== id);
}

/*
 * Track keys we've already surfaced so a stale flash that survives
 * a partial reload doesn't fire a second toast. The key is the
 * concrete value because flash values are short-lived strings and
 * a duplicate value usually means a duplicate event.
 */
const seen = new Set();

function maybeFire(type, value, duration = 5000) {
    if (value === null || value === undefined || value === '') return;
    const key = `${type}::${value}`;
    if (seen.has(key)) return;
    seen.add(key);
    addToast(type, value, duration);
}

watch(
    () => page.props.flash,
    (flash) => {
        if (! flash) return;
        if (flash.success) maybeFire('success', flash.success);
        if (flash.error) maybeFire('error', flash.error);
        if (flash.info) maybeFire('info', flash.info);
        if (flash.warning) maybeFire('warning', flash.warning);

        // Credentials handoff: longer dwell so staff have time to
        // copy. Surface the email + password as a single readable
        // line; the full credentials card on the source page handles
        // the structured display.
        if (flash.temp_password && ! seen.has(`temp::${flash.temp_password}`)) {
            seen.add(`temp::${flash.temp_password}`);
            const who = flash.temp_password_name ? `for ${flash.temp_password_name}` : '';
            addToast(
                'info',
                `Temporary password ${who} generated. Share securely — it won't be shown again.`,
                15000,
            );
        }
    },
    { deep: true, immediate: true },
);
</script>

<template>
    <Teleport to="body">
        <div class="toast-container">
            <TransitionGroup name="toast">
                <div
                    v-for="toast in toasts"
                    :key="toast.id"
                    :class="['toast', `toast-${toast.type}`]"
                    role="status"
                >
                    <div class="toast-icon">
                        <IconCheck v-if="toast.type === 'success'" :size="16" stroke-width="2" />
                        <IconX v-else-if="toast.type === 'error'" :size="16" stroke-width="2" />
                        <IconAlertTriangle v-else-if="toast.type === 'warning'" :size="16" stroke-width="2" />
                        <IconInfoCircle v-else :size="16" stroke-width="2" />
                    </div>
                    <span class="toast-message">{{ toast.message }}</span>
                    <button
                        type="button"
                        class="toast-close"
                        aria-label="Dismiss"
                        @click="removeToast(toast.id)"
                    >
                        <IconX :size="14" stroke-width="2" />
                    </button>
                </div>
            </TransitionGroup>
        </div>
    </Teleport>
</template>
