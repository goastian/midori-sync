<script setup>
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    collection: Object,
    meta: Object,
});

const labels = {
    bookmarks: 'Bookmarks', history: 'History', tabs: 'Open tabs', passwords: 'Passwords',
    'browser-settings': 'Preferences', 'form-history': 'Form data', addresses: 'Addresses',
};

function formatTime(ts) {
    if (!ts) return '—';
    return new Date(ts * 1000).toLocaleString();
}

function deleteAll() {
    if (!confirm(`Delete ALL records in "${props.collection.name}"? This cannot be undone.`)) return;
    router.delete(`/collections/${props.collection.name}`);
}
</script>

<template>
    <AppLayout>
        <div class="flex items-center justify-between mb-6 gap-3">
            <div class="min-w-0">
                <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ labels[collection.name] || collection.name }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ collection.description }}</p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <button
                    @click="deleteAll"
                    class="text-xs text-red-500 hover:text-red-700 font-medium px-3 py-1.5 border border-red-200 dark:border-red-900 rounded-md hover:bg-red-50 dark:hover:bg-red-950/40 transition-colors"
                >
                    Delete All
                </button>
            </div>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-3 gap-4 mb-6">
            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg p-3">
                <p class="text-xs text-gray-500 dark:text-gray-400">Records</p>
                <p class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ meta?.total ?? 0 }}</p>
            </div>
            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg p-3">
                <p class="text-xs text-gray-500 dark:text-gray-400">Size</p>
                <p class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ meta?.size_display ?? '0 B' }}</p>
            </div>
            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg p-3">
                <p class="text-xs text-gray-500 dark:text-gray-400">Last Modified</p>
                <p class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ formatTime(meta?.last_modified) }}</p>
            </div>
        </div>

        <p class="text-sm text-gray-500 dark:text-gray-400">
            This data is end-to-end encrypted. Open Midori on your device to see and manage its contents.
        </p>
    </AppLayout>
</template>
