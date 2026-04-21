<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    stats: Object,
    devices: Array,
    recentActivity: Array,
});

function formatBytes(bytes) {
    if (!bytes) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    let val = bytes;
    while (val >= 1024 && i < units.length - 1) {
        val /= 1024;
        i++;
    }
    return `${val.toFixed(i > 0 ? 1 : 0)} ${units[i]}`;
}

function formatTime(ts) {
    if (!ts) return 'Never';
    const date = new Date(ts);
    const diff = Date.now() - date.getTime();
    if (diff < 60000) return 'Just now';
    if (diff < 3600000) return `${Math.floor(diff / 60000)}m ago`;
    if (diff < 86400000) return `${Math.floor(diff / 3600000)}h ago`;
    return date.toLocaleDateString();
}
</script>

<template>
    <AppLayout>
        <h1 class="text-xl font-semibold text-gray-900 mb-6">Dashboard</h1>

        <!-- Stats cards -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
            <div class="bg-white border border-gray-200 rounded-lg p-4">
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Devices</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900">{{ stats?.device_count ?? 0 }}</p>
            </div>
            <div class="bg-white border border-gray-200 rounded-lg p-4">
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Records</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900">{{ stats?.total_records ?? 0 }}</p>
            </div>
            <div class="bg-white border border-gray-200 rounded-lg p-4">
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Storage</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900">{{ formatBytes(stats?.storage_used) }}</p>
                <div class="mt-2 w-full bg-gray-100 rounded-full h-1.5">
                    <div
                        class="bg-primary-600 h-1.5 rounded-full transition-all"
                        :style="{ width: Math.min((stats?.storage_used / stats?.storage_quota) * 100, 100) + '%' }"
                    />
                </div>
                <p class="mt-1 text-xs text-gray-400">
                    {{ formatBytes(stats?.storage_used) }} / {{ formatBytes(stats?.storage_quota) }}
                </p>
            </div>
        </div>

        <!-- Devices -->
        <div class="mb-8">
            <h2 class="text-sm font-semibold text-gray-900 mb-3">Connected Devices</h2>
            <div class="bg-white border border-gray-200 rounded-lg divide-y divide-gray-100">
                <div v-if="!devices?.length" class="px-4 py-8 text-center text-sm text-gray-400">
                    No devices connected yet
                </div>
                <div
                    v-for="device in devices"
                    :key="device.id"
                    class="flex items-center justify-between px-4 py-3"
                >
                    <div>
                        <p class="text-sm font-medium text-gray-900">{{ device.name }}</p>
                        <p class="text-xs text-gray-400">{{ device.os }} · {{ device.type }}</p>
                    </div>
                    <span class="text-xs text-gray-400">{{ formatTime(device.last_sync_at) }}</span>
                </div>
            </div>
        </div>

        <!-- Recent activity -->
        <div>
            <h2 class="text-sm font-semibold text-gray-900 mb-3">Recent Sync Activity</h2>
            <div class="bg-white border border-gray-200 rounded-lg divide-y divide-gray-100">
                <div v-if="!recentActivity?.length" class="px-4 py-8 text-center text-sm text-gray-400">
                    No sync activity yet
                </div>
                <div
                    v-for="activity in recentActivity"
                    :key="activity.id"
                    class="flex items-center justify-between px-4 py-3"
                >
                    <div>
                        <p class="text-sm text-gray-700">
                            <span class="font-medium">{{ activity.collection }}</span>
                            — {{ activity.action }}
                        </p>
                    </div>
                    <span class="text-xs text-gray-400">{{ formatTime(activity.timestamp) }}</span>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
