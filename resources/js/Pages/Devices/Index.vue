<script setup>
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    devices: Array,
});

function formatTime(ts) {
    if (!ts) return 'Never';
    return new Date(ts).toLocaleString();
}

function deleteDevice(device) {
    if (!confirm(`Remove device "${device.name}"?`)) return;
    router.delete(`/devices/${device.device_id}`);
}
</script>

<template>
    <AppLayout>
        <h1 class="text-xl font-semibold text-gray-900 mb-6">Devices</h1>

        <div class="bg-white border border-gray-200 rounded-lg">
            <div v-if="!devices?.length" class="px-4 py-12 text-center text-sm text-gray-400">
                No devices registered yet. Install the extension and sign in to register a device.
            </div>

            <div
                v-for="device in devices"
                :key="device.id"
                class="flex items-center justify-between px-4 py-4 border-b border-gray-100 last:border-b-0"
            >
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center">
                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                            <line x1="8" y1="21" x2="16" y2="21"/>
                            <line x1="12" y1="17" x2="12" y2="21"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900">{{ device.name }}</p>
                        <p class="text-xs text-gray-400">
                            {{ device.type }} · {{ device.os }}
                        </p>
                        <p class="text-xs text-gray-400">
                            Last synced: {{ formatTime(device.last_sync_at) }}
                        </p>
                    </div>
                </div>
                <button
                    @click="deleteDevice(device)"
                    class="text-xs text-red-500 hover:text-red-700 font-medium"
                >
                    Remove
                </button>
            </div>
        </div>
    </AppLayout>
</template>
