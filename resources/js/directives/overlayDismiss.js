/**
 * v-overlay-dismiss="closeFn"
 *
 * Drop-in replacement for `@click.self="closeFn"` on a full-screen overlay that
 * WRAPS its panel (the "Pattern C" slide-overs: `.slide-over-overlay` /
 * `*-modal-overlay`, where the panel is nested INSIDE the clickable overlay).
 *
 * Fixes the drag-release-close bug (Issue A): a native `click` fires on the
 * common ancestor of mousedown + mouseup, so pressing inside the panel (e.g.
 * drag-selecting text in a field) and releasing on the overlay made
 * `@click.self` match — closing the panel and losing unsaved work. This closes
 * ONLY when the press (pointerdown) AND the release (click) both land on the
 * overlay element itself — i.e. the drag did not originate inside the panel.
 * That is the same press-target guard headlessui's Dialog uses internally
 * (Patterns A and B are already immune and are deliberately left untouched).
 *
 * Also closes on Escape while the overlay is mounted — Pattern B/C panels had
 * no Escape handler, so this brings them in line with headlessui Dialog
 * (Pattern A). Since the overlay is `v-if`-gated, the keydown listener exists
 * only while the panel is open and is removed on unmount.
 *
 * The bound value is invoked for BOTH surfaces (genuine overlay click + Escape),
 * so a panel passes either a plain `() => (show = false)` or a dirty-guarded
 * `attemptClose` (see useDirtyClose) — this directive is agnostic to which.
 */
const state = new WeakMap();

export const overlayDismiss = {
    mounted(el, binding) {
        const s = { cb: binding.value, pressedOnOverlay: false };

        // Record whether the press STARTED on the overlay itself. A press that
        // began inside the panel must never dismiss, however far the drag goes.
        s.onPointerDown = (e) => {
            s.pressedOnOverlay = e.target === el;
        };
        // Close only when press AND release both resolved to the overlay.
        s.onClick = (e) => {
            if (e.target === el && s.pressedOnOverlay && typeof s.cb === 'function') {
                s.cb(e);
            }
        };
        s.onKeydown = (e) => {
            if (e.key === 'Escape' && typeof s.cb === 'function') {
                s.cb(e);
            }
        };

        el.addEventListener('pointerdown', s.onPointerDown);
        el.addEventListener('click', s.onClick);
        document.addEventListener('keydown', s.onKeydown);
        state.set(el, s);
    },
    // Keep the callback current across re-renders (binding.value may change).
    updated(el, binding) {
        const s = state.get(el);
        if (s) s.cb = binding.value;
    },
    unmounted(el) {
        const s = state.get(el);
        if (!s) return;
        el.removeEventListener('pointerdown', s.onPointerDown);
        el.removeEventListener('click', s.onClick);
        document.removeEventListener('keydown', s.onKeydown);
        state.delete(el);
    },
};

export default overlayDismiss;
