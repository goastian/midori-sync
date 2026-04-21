<script setup>
import { ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const page = usePage();
const user = page.props.auth?.user;

const props = defineProps({
    quotaUsed: Number,
    quotaTotal: Number,
});

const confirmDelete = ref(false);

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

function deleteAllData() {
    if (!confirm('Are you sure? This will permanently delete ALL your sync data.')) return;
    router.delete('/settings/data');
}
</script>

<template>
    <AppLayout>
        <h1 class="text-xl font-semibold text-gray-900 mb-6">Settings</h1>

        <!-- Account -->
        <div class="bg-white border border-gray-200 rounded-lg p-4 mb-4">
            <h2 class="text-sm font-semibold text-gray-900 mb-3">Account</h2>
            <div class="space-y-2">
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">Name</span>
                    <span class="text-gray-900 font-medium">{{ user?.name }}</span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">Email</span>
                    <span class="text-gray-900">{{ user?.email }}</span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">Provider</span>
                    <span class="text-gray-900">Authentik</span>
                </div>
            </div>
        </div>

        <!-- Storage -->
        <div class="bg-white border border-gray-200 rounded-lg p-4 mb-4">
            <h2 class="text-sm font-semibold text-gray-900 mb-3">Storage</h2>
            <div class="mb-2">
                <div class="w-full bg-gray-100 rounded-full h-2">
                    <div
                        class="bg-primary-600 h-2 rounded-full transition-all"
                        :style="{ width: Math.min((quotaUsed / quotaTotal) * 100, 100) + '%' }"
                    />
                </div>
            </div>
            <p class="text-xs text-gray-500">
                {{ formatBytes(quotaUsed) }} used of {{ formatBytes(quotaTotal) }}
            </p>
        </div>

        <!-- Danger -->
        <div class="bg-red-50 border border-red-200 rounded-lg p-4">
            <h2 class="text-sm font-semibold text-red-900 mb-2">Danger Zone</h2>
            <p class="text-xs text-red-700 mb-3">
                Permanently delete all your sync data from the server. This does not affect your local browser data.
            </p>
            <button
                @click="deleteAllData"
                class="px-4 py-1.5 bg-red-600 text-white text-xs font-medium rounded-md hover:bg-red-700 transition-colors"
            >
                Delete All Sync Data
            </button>
        </div>
    </AppLayout>
</template>
