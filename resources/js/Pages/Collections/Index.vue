<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    collections: Array,
});

function formatTime(ts) {
    if (!ts) return 'Never';
    return new Date(ts).toLocaleString();
}
</script>

<template>
    <AppLayout>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100 mb-6">Collections</h1>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div
                v-for="col in collections"
                :key="col.id"
                class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg p-4 hover:border-primary-300 dark:hover:border-primary-700 transition-colors"
            >
                <div class="flex items-start justify-between gap-3">
                    <Link :href="`/collections/${col.name}`" class="flex-1 min-w-0">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-1">{{ col.name }}</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ col.description || 'No description' }}</p>
                    </Link>
                    <a
                        :href="`/collections/${col.name}/export`"
                        class="shrink-0 text-xs text-primary-600 dark:text-primary-400 hover:text-primary-700 dark:hover:text-primary-300 font-medium"
                        title="Export collection as JSON"
                    >
                        Export
                    </a>
                </div>
                <Link
                    :href="`/collections/${col.name}`"
                    class="flex items-center justify-between text-xs text-gray-400 dark:text-gray-500"
                >
                    <span>{{ col.record_count ?? 0 }} records</span>
                    <span>Last modified: {{ formatTime(col.last_modified) }}</span>
                </Link>
            </div>
        </div>

        <div v-if="!collections?.length" class="text-center py-12 text-sm text-gray-400 dark:text-gray-500">
            No collections available
        </div>
    </AppLayout>
</template>
