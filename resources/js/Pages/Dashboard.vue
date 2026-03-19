<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    stats: {
        type: Object,
        required: true,
    },
});

/**
 * Format bytes to a human-readable string.
 */
function formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

/**
 * Calculate quota usage percentage.
 */
function quotaPercent() {
    if (!props.stats.quota.total) return 0;
    return Math.min(100, Math.round((props.stats.quota.used / props.stats.quota.total) * 100));
}

const collectionIcons = {
    bookmarks: '🔖',
    history: '🕐',
    tabs: '📑',
    passwords: '🔒',
    forms: '📝',
    addons: '🧩',
    prefs: '⚙️',
    clients: '💻',
    crypto: '🔑',
    meta: '📋',
    addresses: '📫',
    creditcards: '💳',
};
</script>

<template>
    <Head title="Dashboard" />
    <AppLayout>
        <div class="space-y-8">
            <!-- Page header -->
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Dashboard</h1>
                <p class="mt-1 text-sm text-gray-500">Overview of your Midori Sync data.</p>
            </div>

            <!-- Stats cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <div class="text-sm font-medium text-gray-500">Total Items</div>
                    <div class="mt-1 text-2xl font-bold text-gray-900">{{ stats.totalItems.toLocaleString() }}</div>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <div class="text-sm font-medium text-gray-500">Collections</div>
                    <div class="mt-1 text-2xl font-bold text-gray-900">{{ Object.keys(stats.collections).length }}</div>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <div class="text-sm font-medium text-gray-500">Connected Devices</div>
                    <div class="mt-1 text-2xl font-bold text-gray-900">{{ stats.devices }}</div>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <div class="text-sm font-medium text-gray-500">Storage Used</div>
                    <div class="mt-1 text-2xl font-bold text-gray-900">{{ formatBytes(stats.quota.used) }}</div>
                    <div class="mt-2 text-xs text-gray-400">of {{ formatBytes(stats.quota.total) }}</div>
                </div>
            </div>

            <!-- Quota bar -->
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <div class="flex justify-between items-center mb-2">
                    <span class="text-sm font-medium text-gray-700">Storage Quota</span>
                    <span class="text-sm text-gray-500">{{ quotaPercent() }}%</span>
                </div>
                <div class="w-full bg-gray-100 rounded-full h-2.5">
                    <div
                        class="h-2.5 rounded-full transition-all duration-500"
                        :class="quotaPercent() > 90 ? 'bg-red-500' : quotaPercent() > 70 ? 'bg-yellow-500' : 'bg-midori-500'"
                        :style="{ width: quotaPercent() + '%' }"
                    ></div>
                </div>
            </div>

            <!-- Collections breakdown -->
            <div class="bg-white rounded-xl border border-gray-200">
                <div class="px-5 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900">Collections</h2>
                </div>
                <div v-if="Object.keys(stats.collections).length === 0" class="p-8 text-center text-gray-500">
                    <p>No sync data yet. Connect your Midori browser to start syncing.</p>
                </div>
                <div v-else class="divide-y divide-gray-100">
                    <div
                        v-for="(count, name) in stats.collections"
                        :key="name"
                        class="flex items-center justify-between px-5 py-3 hover:bg-gray-50"
                    >
                        <div class="flex items-center gap-3">
                            <span class="text-lg">{{ collectionIcons[name] || '📦' }}</span>
                            <span class="text-sm font-medium text-gray-900 capitalize">{{ name }}</span>
                        </div>
                        <div class="flex items-center gap-4">
                            <span class="text-sm text-gray-500">{{ count }} items</span>
                            <span class="text-xs text-gray-400">{{ formatBytes(stats.usage[name] || 0) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
