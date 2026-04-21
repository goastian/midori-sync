import '../css/app.css';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { ZiggyVue } from 'ziggy-js';

// Apply persisted theme before mount to avoid FOUC.
(() => {
    try {
        const stored = localStorage.getItem('midori.theme');
        const prefersDark = window.matchMedia?.('(prefers-color-scheme: dark)').matches;
        const dark = stored ? stored === 'dark' : !!prefersDark;
        document.documentElement.classList.toggle('dark', dark);
    } catch (_) { /* no-op */ }
})();

createInertiaApp({
    title: (title) => title ? `${title} — Midori Sync` : 'Midori Sync',
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        return pages[`./Pages/${name}.vue`];
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#22c55e',
    },
});
