<script setup>
import {
    Dialog,
    DialogPanel,
    DialogTitle,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';

defineProps({
    open: { type: Boolean, required: true },
    title: { type: String, default: null },
});

const emit = defineEmits(['close']);
</script>

<template>
    <TransitionRoot as="template" :show="open">
        <Dialog as="div" class="relative z-50" @close="emit('close')">
            <TransitionChild
                as="template"
                enter="ease-out duration-150"
                enter-from="opacity-0"
                enter-to="opacity-100"
                leave="ease-in duration-100"
                leave-from="opacity-100"
                leave-to="opacity-0"
            >
                <div class="fixed inset-0 bg-black/40" />
            </TransitionChild>

            <div class="fixed inset-0 flex items-center justify-center p-4">
                <DialogPanel
                    class="w-full max-w-lg overflow-hidden"
                    style="
                        background: var(--card-bg);
                        border-radius: var(--radius-xl);
                        box-shadow: var(--shadow-lg);
                    "
                >
                    <DialogTitle
                        v-if="title"
                        class="border-b px-5 py-3 text-sm font-medium"
                        style="border-color: var(--border-soft); color: var(--text-primary)"
                    >
                        {{ title }}
                    </DialogTitle>
                    <div class="px-5 py-4"><slot /></div>
                    <footer
                        v-if="$slots.footer"
                        class="flex justify-end gap-2 border-t px-5 py-3"
                        style="border-color: var(--border-soft)"
                    >
                        <slot name="footer" />
                    </footer>
                </DialogPanel>
            </div>
        </Dialog>
    </TransitionRoot>
</template>
