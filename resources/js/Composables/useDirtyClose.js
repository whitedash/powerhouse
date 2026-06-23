import { ref } from 'vue';

/**
 * useDirtyClose — unsaved-changes discard safeguard for a panel (Issue B).
 *
 * Route EVERY close surface (overlay dismiss, X button, Escape, Cancel button)
 * through `attemptClose`. If the form is dirty, `attemptClose` raises a discard
 * confirmation (`confirmingDiscard` flips true → render a `ConfirmModal` bound to
 * it); a clean panel closes immediately with no prompt. "Discard" runs the real
 * close, "Keep editing" leaves the panel and its data intact.
 *
 * Pairs with the `v-overlay-dismiss` directive (pass `attemptClose` as its value
 * so overlay-click + Escape are guarded too).
 *
 *   const edit = useDirtyClose(() => form.isDirty, () => { showEdit.value = false });
 *   // template: v-overlay-dismiss="edit.attemptClose", X/Cancel @click="edit.attemptClose",
 *   //           <ConfirmModal :show="edit.confirmingDiscard" @confirm="edit.confirmDiscard" @cancel="edit.cancelDiscard" ... />
 *
 * @param {() => boolean} isDirty  unsaved-changes predicate, e.g. `() => form.isDirty`
 * @param {() => void}    close    performs the real close, e.g. `() => { show.value = false }`
 */
export function useDirtyClose(isDirty, close) {
    const confirmingDiscard = ref(false);

    function attemptClose() {
        if (isDirty()) {
            confirmingDiscard.value = true;
        } else {
            close();
        }
    }

    function confirmDiscard() {
        confirmingDiscard.value = false;
        close();
    }

    function cancelDiscard() {
        confirmingDiscard.value = false;
    }

    return { confirmingDiscard, attemptClose, confirmDiscard, cancelDiscard };
}

export default useDirtyClose;
