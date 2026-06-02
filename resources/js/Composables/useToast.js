/**
 * Imperative toast trigger for non-Inertia code paths.
 *
 * The global <ToastContainer> normally bridges server-side flash
 * (`$page.props.flash`) into toasts. Code that surfaces a message WITHOUT
 * a round-trip through the flash bag — `fetch()` handlers, client-side
 * try/catch — has no flash key to set, which is why those spots used to
 * fall back to window.alert (forbidden by CLAUDE.md).
 *
 * This dispatches a window CustomEvent that <ToastContainer> listens for,
 * so imperative toasts render through the one existing renderer rather
 * than a parallel notification system.
 */
export function showToast(type, message, duration = 5000) {
    if (typeof window === 'undefined' || ! message) {
        return;
    }

    window.dispatchEvent(
        new CustomEvent('app:toast', {
            detail: { type, message, duration },
        }),
    );
}

export function useToast() {
    return { showToast };
}
