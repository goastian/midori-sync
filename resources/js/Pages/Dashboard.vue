<script setup>
import { onBeforeUnmount, onMounted } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    tiles: { type: Array, default: () => [] },
});

const REFRESH_MS = 15000;
let timer = null;

function refresh() {
    if (document.visibilityState === 'visible') {
        router.reload({ only: ['tiles'], preserveScroll: true });
    }
}

onMounted(() => {
    timer = setInterval(refresh, REFRESH_MS);
    document.addEventListener('visibilitychange', refresh);
});

onBeforeUnmount(() => {
    clearInterval(timer);
    document.removeEventListener('visibilitychange', refresh);
});
</script>

<template>
    <AppLayout>
        <h1 class="text-3xl font-semibold text-gray-900 dark:text-gray-100">Your synchronized content</h1>
        <p class="mt-4 text-sm text-gray-700 dark:text-gray-300">
            Midori encrypts your data on your devices, so only the totals are shown here.
            Sign in with Astian Account on your devices to connect them automatically.
        </p>

        <Link href="/devices?connect=android" class="mt-6 inline-flex rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">Connect Android</Link>

        <div class="mt-10 grid grid-cols-2 lg:grid-cols-4 gap-5">
            <component
                :is="tile.href ? Link : 'div'"
                v-for="tile in tiles"
                :key="tile.name"
                :href="tile.href || undefined"
                :data-tile="tile.name"
                :class="[
                    'relative flex flex-col items-center justify-center h-36 rounded-lg border text-center px-3 transition-colors',
                    tile.href
                        ? 'border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 hover:border-primary-400 hover:shadow-sm text-gray-900 dark:text-gray-100'
                        : 'border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 text-gray-400 dark:text-gray-500',
                ]"
            >
                <span
                    v-if="!tile.browsable"
                    class="absolute top-3 right-3 w-5 h-5 rounded-full bg-gray-400 dark:bg-gray-600 text-white text-xs font-bold flex items-center justify-center"
                    title="Only visible in your browser"
                    aria-label="Only visible in your browser"
                >i</span>
                <span class="text-3xl font-medium">{{ tile.count }}</span>
                <span class="mt-2 text-lg">{{ tile.label }}</span>
                <span v-if="tile.caption" class="text-xs text-gray-400 dark:text-gray-500">{{ tile.caption }}</span>
            </component>
        </div>
    </AppLayout>
</template>
