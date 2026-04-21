<script setup>
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTheme } from '@/composables/useTheme';

const page = usePage();
const user = page.props.auth?.user;
const { isDark, toggle: toggleTheme } = useTheme();

defineProps({
    quotaUsed: Number,
    quotaTotal: Number,
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

function deleteAllData() {
    if (!confirm('Are you sure? This will permanently delete ALL your sync data.')) return;
    router.delete('/settings/data');
}
</script>

<template>
    <AppLayout>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100 mb-6">Settings</h1>

        <!-- Account -->
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg p-4 mb-4">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">Account</h2>
            <div class="space-y-2">
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500 dark:text-gray-400">Name</span>
                    <span class="text-gray-900 dark:text-gray-100 font-medium">{{ user?.name }}</span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500 dark:text-gray-400">Email</span>
                    <span class="text-gray-900 dark:text-gray-100">{{ user?.email }}</span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500 dark:text-gray-400">Provider</span>
                    <span class="text-gray-900 dark:text-gray-100">Authentik</span>
                </div>
            </div>
        </div>

        <!-- Appearance -->
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg p-4 mb-4">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">Appearance</h2>
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-900 dark:text-gray-100">Dark mode</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Toggle between light and dark theme. Preference is stored locally.
                    </p>
                </div>
                <button
                    type="button"
                    role="switch"
                    :aria-checked="isDark"
                    @click="toggleTheme"
                    :class="[
                        'relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900',
                        isDark ? 'bg-primary-600' : 'bg-gray-300',
                    ]"
                >
                    <span
                        :class="[
                            'inline-block h-4 w-4 transform rounded-full bg-white transition-transform',
                            isDark ? 'translate-x-6' : 'translate-x-1',
                        ]"
                    />
                </button>
            </div>
        </div>

        <!-- Storage -->
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg p-4 mb-4">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">Storage</h2>
            <div class="mb-2">
                <div class="w-full bg-gray-100 dark:bg-gray-800 rounded-full h-2">
                    <div
                        class="bg-primary-600 dark:bg-primary-500 h-2 rounded-full transition-all"
                        :style="{ width: Math.min((quotaUsed / quotaTotal) * 100, 100) + '%' }"
                    />
                </div>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                {{ formatBytes(quotaUsed) }} used of {{ formatBytes(quotaTotal) }}
            </p>
        </div>

        <!-- Danger -->
        <div class="bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900 rounded-lg p-4">
            <h2 class="text-sm font-semibold text-red-900 dark:text-red-300 mb-2">Danger Zone</h2>
            <p class="text-xs text-red-700 dark:text-red-400 mb-3">
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
