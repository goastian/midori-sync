<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    stats: Object,
    devices: Array,
    recentActivity: Array,
    activitySeries: { type: Array, default: () => [] },
    activityRange: { type: Number, default: 7 },
});

const maxActivity = computed(() => {
    return Math.max(1, ...(props.activitySeries || []).map((d) => d.count || 0));
});

const totalActivity = computed(() => {
    return (props.activitySeries || []).reduce((sum, d) => sum + (d.count || 0), 0);
});

function setRange(days) {
    if (days === props.activityRange) return;
    router.get('/dashboard', { range: days }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        only: ['activitySeries', 'activityRange'],
    });
}

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
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100 mb-6">Dashboard</h1>

        <!-- Stats cards -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg p-4">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Devices</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ stats?.device_count ?? 0 }}</p>
            </div>
            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg p-4">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Records</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ stats?.total_records ?? 0 }}</p>
            </div>
            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg p-4">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Storage</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ formatBytes(stats?.storage_used) }}</p>
                <div class="mt-2 w-full bg-gray-100 dark:bg-gray-800 rounded-full h-1.5">
                    <div
                        class="bg-primary-600 dark:bg-primary-500 h-1.5 rounded-full transition-all"
                        :style="{ width: Math.min((stats?.storage_used / stats?.storage_quota) * 100, 100) + '%' }"
                    />
                </div>
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                    {{ formatBytes(stats?.storage_used) }} / {{ formatBytes(stats?.storage_quota) }}
                </p>
            </div>
        </div>

        <!-- Activity chart -->
        <div class="mb-8">
            <div class="flex items-center justify-between mb-3 gap-3">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                    Sync Activity — Last {{ activityRange }} Days
                </h2>
                <div class="flex items-center gap-3">
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ totalActivity }} changes</span>
                    <div class="inline-flex rounded-md border border-gray-200 dark:border-gray-800 overflow-hidden text-xs">
                        <button
                            type="button"
                            @click="setRange(7)"
                            :class="[
                                'px-2.5 py-1 transition-colors',
                                activityRange === 7
                                    ? 'bg-primary-600 text-white dark:bg-primary-500'
                                    : 'bg-white dark:bg-gray-900 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800'
                            ]"
                        >7d</button>
                        <button
                            type="button"
                            @click="setRange(30)"
                            :class="[
                                'px-2.5 py-1 border-l border-gray-200 dark:border-gray-800 transition-colors',
                                activityRange === 30
                                    ? 'bg-primary-600 text-white dark:bg-primary-500'
                                    : 'bg-white dark:bg-gray-900 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800'
                            ]"
                        >30d</button>
                    </div>
                </div>
            </div>
            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg p-4">
                <div class="flex items-end justify-between gap-1 h-32">
                    <div
                        v-for="day in activitySeries"
                        :key="day.date"
                        class="flex-1 flex flex-col items-center justify-end gap-2 group min-w-0"
                    >
                        <span class="text-[10px] text-gray-400 dark:text-gray-500 font-mono opacity-0 group-hover:opacity-100 transition-opacity">
                            {{ day.count }}
                        </span>
                        <div
                            class="w-full rounded-t-md bg-primary-500/80 dark:bg-primary-400/80 group-hover:bg-primary-600 dark:group-hover:bg-primary-300 transition-colors"
                            :style="{ height: (day.count > 0 ? Math.max((day.count / maxActivity) * 100, 4) : 2) + '%' }"
                            :title="`${day.date} — ${day.count} changes`"
                        />
                        <span class="text-[10px] text-gray-500 dark:text-gray-400 truncate w-full text-center">{{ day.label }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Devices -->
        <div class="mb-8">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">Connected Devices</h2>
            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg divide-y divide-gray-100 dark:divide-gray-800">
                <div v-if="!devices?.length" class="px-4 py-8 text-center text-sm text-gray-400 dark:text-gray-500">
                    No devices connected yet
                </div>
                <div
                    v-for="device in devices"
                    :key="device.id"
                    class="flex items-center justify-between px-4 py-3"
                >
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ device.name }}</p>
                        <p class="text-xs text-gray-400 dark:text-gray-500">{{ device.os }} · {{ device.type }}</p>
                    </div>
                    <span class="text-xs text-gray-400 dark:text-gray-500">{{ formatTime(device.last_sync_at) }}</span>
                </div>
            </div>
        </div>

        <!-- Recent activity -->
        <div>
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">Recent Sync Activity</h2>
            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg divide-y divide-gray-100 dark:divide-gray-800">
                <div v-if="!recentActivity?.length" class="px-4 py-8 text-center text-sm text-gray-400 dark:text-gray-500">
                    No sync activity yet
                </div>
                <div
                    v-for="activity in recentActivity"
                    :key="activity.id"
                    class="flex items-center justify-between px-4 py-3"
                >
                    <div>
                        <p class="text-sm text-gray-700 dark:text-gray-300">
                            <span class="font-medium">{{ activity.collection }}</span>
                            — {{ activity.action }}
                        </p>
                    </div>
                    <span class="text-xs text-gray-400 dark:text-gray-500">{{ formatTime(activity.timestamp) }}</span>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
