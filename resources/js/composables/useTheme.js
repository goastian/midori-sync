import { ref, watch } from 'vue';

const stored = (() => {
    try { return localStorage.getItem('midori.theme'); } catch (_) { return null; }
})();

const prefersDark = typeof window !== 'undefined' && window.matchMedia
    ? window.matchMedia('(prefers-color-scheme: dark)').matches
    : false;

const isDark = ref(stored ? stored === 'dark' : prefersDark);

function apply(dark) {
    if (typeof document !== 'undefined') {
        document.documentElement.classList.toggle('dark', dark);
    }
}

watch(isDark, (v) => {
    apply(v);
    try { localStorage.setItem('midori.theme', v ? 'dark' : 'light'); } catch (_) { /* no-op */ }
}, { immediate: true });

export function useTheme() {
    return {
        isDark,
        toggle: () => { isDark.value = !isDark.value; },
    };
}
