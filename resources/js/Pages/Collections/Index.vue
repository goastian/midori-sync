<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    collections: Array,
    quota: Object,
});

function formatTime(ts) {
    if (!ts) return 'Never';
    return new Date(ts).toLocaleString();
}
</script>

<template>
    <AppLayout>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100 mb-6">Collections</h1>

        <!-- Storage quota overview -->
        <div v-if="quota" class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg p-4 mb-6">
            <div class="flex items-baseline justify-between gap-3 mb-2 flex-wrap">
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Storage</p>
                    <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-gray-100">
                        {{ quota.used_display }}
                        <span class="text-sm font-normal text-gray-500 dark:text-gray-400">/ {{ quota.quota_display }}</span>
                    </p>
                </div>
                <div class="text-right">
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ quota.percent }}% used</p>
                    <p class="text-xs text-gray-400 dark:text-gray-500">{{ quota.free_display }} free</p>
                </div>
            </div>
            <div class="w-full bg-gray-100 dark:bg-gray-800 rounded-full h-2 overflow-hidden">
                <div
                    class="h-2 rounded-full transition-all"
                    :class="[
                        quota.percent >= 90 ? 'bg-red-500' :
                        quota.percent >= 75 ? 'bg-amber-500' :
                        'bg-primary-600 dark:bg-primary-500'
                    ]"
                    :style="{ width: Math.min(quota.percent, 100) + '%' }"
                />
            </div>
        </div>

        <!-- Per-collection cards with usage -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div
                v-for="col in collections"
                :key="col.id"
                class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg p-4 hover:border-primary-300 dark:hover:border-primary-700 transition-colors"
            >
                <div class="flex items-start justify-between gap-3 mb-3">
                    <Link :href="`/collections/${col.name}`" class="flex-1 min-w-0">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-1">{{ col.name }}</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ col.description || 'No description' }}</p>
                    </Link>
                    <a
                        :href="`/collections/${col.name}/export`"
                        class="shrink-0 text-xs text-primary-600 dark:text-primary-400 hover:text-primary-700 dark:hover:text-primary-300 font-medium"
                        title="Export collection as JSON"
                    >
                        Export
                    </a>
                </div>

                <!-- Usage bar (share of total used storage) -->
                <div class="mb-2">
                    <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400 mb-1">
                        <span class="font-mono">{{ col.size_display }}</span>
                        <span>{{ col.percent_of_used }}% of used</span>
                    </div>
                    <div class="w-full bg-gray-100 dark:bg-gray-800 rounded-full h-1.5 overflow-hidden">
                        <div
                            class="bg-primary-500/70 dark:bg-primary-400/70 h-1.5 rounded-full transition-all"
                            :style="{ width: Math.min(col.percent_of_used, 100) + '%' }"
                        />
                    </div>
                </div>

                <Link
                    :href="`/collections/${col.name}`"
                    class="flex items-center justify-between text-xs text-gray-400 dark:text-gray-500 mt-3"
                >
                    <span>{{ col.record_count ?? 0 }} records</span>
                    <span>{{ col.percent_of_quota }}% of quota</span>
                </Link>
                <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">
                    Last modified: {{ formatTime(col.last_modified) }}
                </p>
            </div>
        </div>

        <div v-if="!collections?.length" class="text-center py-12 text-sm text-gray-400 dark:text-gray-500">
            No collections available
        </div>
    </AppLayout>
</template>
