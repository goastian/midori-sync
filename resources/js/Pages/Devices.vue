<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    devices: {
        type: Array,
        required: true,
    },
});

/**
 * Format a date to a human-readable relative string.
 */
function formatDate(dateStr) {
    if (!dateStr) return 'Never';
    const date = new Date(dateStr);
    const now = new Date();
    const diff = Math.floor((now - date) / 1000);

    if (diff < 60) return 'Just now';
    if (diff < 3600) return Math.floor(diff / 60) + ' min ago';
    if (diff < 86400) return Math.floor(diff / 3600) + ' hours ago';
    if (diff < 604800) return Math.floor(diff / 86400) + ' days ago';
    return date.toLocaleDateString();
}

/**
 * Get an icon for the device type.
 */
function deviceIcon(type) {
    switch (type) {
        case 'desktop': return '🖥️';
        case 'mobile': return '📱';
        case 'tablet': return '📟';
        default: return '💻';
    }
}

/**
 * Remove a connected device.
 */
function removeDevice(deviceId) {
    if (confirm('Are you sure you want to disconnect this device?')) {
        router.delete(route('devices.remove', deviceId));
    }
}
</script>

<template>
    <Head title="Devices" />
    <AppLayout>
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Connected Devices</h1>
                <p class="mt-1 text-sm text-gray-500">
                    Manage the devices connected to your Midori Sync account.
                </p>
            </div>

            <!-- Devices list -->
            <div class="bg-white rounded-xl border border-gray-200">
                <div v-if="devices.length === 0" class="p-12 text-center">
                    <div class="text-4xl mb-4">📱</div>
                    <h3 class="text-lg font-medium text-gray-900">No devices connected</h3>
                    <p class="mt-2 text-sm text-gray-500 max-w-sm mx-auto">
                        Connect your Midori browser to start syncing. Check the
                        <Link :href="route('settings')" class="text-midori-600 hover:text-midori-700">Settings</Link>
                        page for setup instructions.
                    </p>
                </div>
                <div v-else class="divide-y divide-gray-100">
                    <div
                        v-for="device in devices"
                        :key="device.id"
                        class="flex items-center justify-between px-5 py-4"
                    >
                        <div class="flex items-center gap-4">
                            <div class="text-2xl">
                                {{ deviceIcon(device.type) }}
                            </div>
                            <div>
                                <div class="text-sm font-medium text-gray-900">
                                    {{ device.name || 'Unknown Device' }}
                                </div>
                                <div class="text-xs text-gray-500">
                                    {{ device.type ? device.type.charAt(0).toUpperCase() + device.type.slice(1) : 'Unknown' }}
                                    &middot;
                                    Last synced: {{ formatDate(device.last_sync_at) }}
                                </div>
                            </div>
                        </div>
                        <button
                            @click="removeDevice(device.id)"
                            class="text-sm text-red-600 hover:text-red-700 font-medium px-3 py-1.5 rounded-lg hover:bg-red-50 transition-colors"
                        >
                            Disconnect
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
