// Real-time notification groundwork — uncomment once Pusher is configured.
// import './bootstrap';
// Tabler icon webfont — the portal redesign (handoff) renders icons as
// <i class="ti ti-*"> sized via portal.css; this provides the glyphs.
import '@tabler/icons-webfont/dist/tabler-icons.min.css';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { ZiggyVue } from '../../vendor/tightenco/ziggy/dist/index.esm.js';
import { overlayDismiss } from './directives/overlayDismiss';

createInertiaApp({
    title: (title) => (title ? `${title} · Powerhouse` : 'Powerhouse'),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        return pages[`./Pages/${name}.vue`];
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .directive('overlay-dismiss', overlayDismiss)
            .mount(el);
    },
    progress: { color: '#F59E0B' },
});
