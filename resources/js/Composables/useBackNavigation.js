import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

/**
 * Global "Back" navigation.
 *
 * Returns the user to wherever they actually came from, using the
 * `previous_url` prop shared by HandleInertiaRequests (which reads the
 * Referer header server-side). This beats hardcoded back targets: a task
 * opened from My Work returns to My Work, the same task opened from a
 * customer returns to that customer.
 *
 * @param {string} fallback  Where to go when there's no usable previous
 *                           URL (direct link / refresh / self-referer).
 */
export function useBackNavigation(fallback = '/') {
    const page = usePage();

    const previousUrl = computed(() => page.props.previous_url ?? null);

    const goBack = () => {
        const previous = previousUrl.value;

        // Don't bounce to the page we're already on (a refresh or a
        // direct link leaves Referer === current URL).
        if (previous && previous !== window.location.href) {
            router.visit(previous);
        } else {
            router.visit(fallback);
        }
    };

    return { goBack, previousUrl };
}
