<script setup>
import { computed } from 'vue';
import {
    Dialog,
    DialogPanel,
    DialogTitle,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import {
    IconAlertTriangle,
    IconAlertCircle,
    IconInfoCircle,
    IconLoader2,
} from '@tabler/icons-vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, required: true },
    message: { type: String, default: '' },
    confirmLabel: { type: String, default: 'Confirm' },
    cancelLabel: { type: String, default: 'Cancel' },
    variant: {
        type: String,
        default: 'danger',
        validator: (v) => ['danger', 'warning', 'primary'].includes(v),
    },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(['confirm', 'cancel', 'update:show']);

/* ─── Icon by variant ─── */
const ICONS = {
    danger: IconAlertTriangle,
    warning: IconAlertCircle,
    primary: IconInfoCircle,
};
const iconComponent = computed(() => ICONS[props.variant]);

/* ─── Confirm button class by variant ─── */
const confirmButtonClass = computed(() => `confirm-btn-${props.variant}`);

function onCancel() {
    if (props.loading) return;
    emit('cancel');
    emit('update:show', false);
}

function onConfirm() {
    if (props.loading) return;
    emit('confirm');
}
</script>

<template>
    <TransitionRoot as="template" :show="show">
        <Dialog as="div" class="confirm-modal-root" @close="onCancel">
            <TransitionChild
                as="template"
                enter="transition-opacity ease-out duration-200"
                enter-from="opacity-0"
                enter-to="opacity-100"
                leave="transition-opacity ease-in duration-150"
                leave-from="opacity-100"
                leave-to="opacity-0"
            >
                <div class="confirm-modal-backdrop" />
            </TransitionChild>

            <div class="confirm-modal-shell">
                <TransitionChild
                    as="template"
                    enter="transition ease-out duration-200"
                    enter-from="opacity-0 scale-95"
                    enter-to="opacity-100 scale-100"
                    leave="transition ease-in duration-150"
                    leave-from="opacity-100 scale-100"
                    leave-to="opacity-0 scale-95"
                >
                    <DialogPanel class="confirm-modal">
                        <header class="confirm-modal-header">
                            <div class="confirm-modal-icon" :class="variant">
                                <component :is="iconComponent" :size="20" stroke-width="1.75" />
                            </div>
                            <DialogTitle class="confirm-modal-title">{{ title }}</DialogTitle>
                        </header>

                        <div v-if="message || $slots.default" class="confirm-modal-body">
                            <p v-if="message" class="confirm-modal-message">{{ message }}</p>
                            <slot />
                        </div>

                        <footer class="confirm-modal-footer">
                            <button
                                type="button"
                                class="btn btn-secondary"
                                :disabled="loading"
                                @click="onCancel"
                            >
                                {{ cancelLabel }}
                            </button>
                            <button
                                type="button"
                                :class="confirmButtonClass"
                                :disabled="loading"
                                @click="onConfirm"
                            >
                                <IconLoader2
                                    v-if="loading"
                                    :size="15"
                                    stroke-width="2"
                                    class="confirm-spinner"
                                />
                                {{ confirmLabel }}
                            </button>
                        </footer>
                    </DialogPanel>
                </TransitionChild>
            </div>
        </Dialog>
    </TransitionRoot>
</template>
