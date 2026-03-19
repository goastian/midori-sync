<script setup>
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ref } from 'vue';

const props = defineProps({
    syncConfig: {
        type: Object,
        required: true,
    },
});

const copied = ref('');

/**
 * Copy text to clipboard and show feedback.
 */
async function copyToClipboard(text, label) {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = label;
        setTimeout(() => { copied.value = ''; }, 2000);
    } catch {
        // Fallback for non-HTTPS contexts
        const textarea = document.createElement('textarea');
        textarea.value = text;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        copied.value = label;
        setTimeout(() => { copied.value = ''; }, 2000);
    }
}

/**
 * Delete all sync data after confirmation.
 */
function deleteAllData() {
    if (confirm('Are you sure you want to delete ALL your sync data? This action cannot be undone.')) {
        if (confirm('This will permanently delete all your synced bookmarks, passwords, tabs, and history from the server. Continue?')) {
            router.delete(route('sync.delete-all'));
        }
    }
}
</script>

<template>
    <Head title="Settings" />
    <AppLayout>
        <div class="space-y-8">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Settings</h1>
                <p class="mt-1 text-sm text-gray-500">
                    Configure your Midori browser and manage your sync data.
                </p>
            </div>

            <!-- Browser Configuration -->
            <div class="bg-white rounded-xl border border-gray-200">
                <div class="px-5 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900">Browser Configuration</h2>
                    <p class="mt-1 text-sm text-gray-500">
                        Follow these steps to connect your Midori browser to this sync server.
                    </p>
                </div>
                <div class="p-5 space-y-6">
                    <!-- Step 1 -->
                    <div class="flex gap-4">
                        <div class="flex-shrink-0 w-8 h-8 bg-midori-100 text-midori-700 rounded-full flex items-center justify-center text-sm font-bold">1</div>
                        <div class="flex-1">
                            <h3 class="text-sm font-semibold text-gray-900">Open about:config</h3>
                            <p class="mt-1 text-sm text-gray-600">
                                In your Midori browser, type <code class="bg-gray-100 px-1.5 py-0.5 rounded text-xs font-mono">about:config</code> in the address bar and press Enter. Accept the warning if prompted.
                            </p>
                        </div>
                    </div>

                    <!-- Step 2 -->
                    <div class="flex gap-4">
                        <div class="flex-shrink-0 w-8 h-8 bg-midori-100 text-midori-700 rounded-full flex items-center justify-center text-sm font-bold">2</div>
                        <div class="flex-1">
                            <h3 class="text-sm font-semibold text-gray-900">Set the Token Server URL</h3>
                            <p class="mt-1 text-sm text-gray-600">
                                Search for <code class="bg-gray-100 px-1.5 py-0.5 rounded text-xs font-mono">identity.sync.tokenserver.uri</code> and set it to:
                            </p>
                            <div class="mt-2 flex items-center gap-2">
                                <code class="flex-1 bg-gray-900 text-midori-400 px-4 py-2.5 rounded-lg text-sm font-mono block overflow-x-auto">
                                    {{ syncConfig.tokenServerUrl }}
                                </code>
                                <button
                                    @click="copyToClipboard(syncConfig.tokenServerUrl, 'tokenserver')"
                                    class="flex-shrink-0 px-3 py-2.5 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm transition-colors"
                                >
                                    {{ copied === 'tokenserver' ? '✓' : 'Copy' }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Step 3 -->
                    <div class="flex gap-4">
                        <div class="flex-shrink-0 w-8 h-8 bg-midori-100 text-midori-700 rounded-full flex items-center justify-center text-sm font-bold">3</div>
                        <div class="flex-1">
                            <h3 class="text-sm font-semibold text-gray-900">Set up your encryption passphrase</h3>
                            <p class="mt-1 text-sm text-gray-600">
                                When prompted during first sync, create a strong encryption passphrase. This passphrase
                                encrypts your data locally — it is <strong>never</strong> sent to the server.
                                Use the same passphrase on all devices you want to sync.
                            </p>
                            <div class="mt-2 bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-lg text-sm">
                                <strong>Important:</strong> If you forget your passphrase, your synced data cannot be recovered.
                                Make sure to store it securely.
                            </div>
                        </div>
                    </div>

                    <!-- Step 4 -->
                    <div class="flex gap-4">
                        <div class="flex-shrink-0 w-8 h-8 bg-midori-100 text-midori-700 rounded-full flex items-center justify-center text-sm font-bold">4</div>
                        <div class="flex-1">
                            <h3 class="text-sm font-semibold text-gray-900">Restart and sync</h3>
                            <p class="mt-1 text-sm text-gray-600">
                                Restart Midori browser, then sign in via the Sync option in the menu.
                                You'll be redirected to Authentik for authentication.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Server Information -->
            <div class="bg-white rounded-xl border border-gray-200">
                <div class="px-5 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900">Server Information</h2>
                </div>
                <div class="p-5">
                    <dl class="space-y-4">
                        <div class="flex flex-col sm:flex-row sm:gap-4">
                            <dt class="text-sm font-medium text-gray-500 sm:w-48">Token Server URL</dt>
                            <dd class="text-sm text-gray-900 font-mono">{{ syncConfig.tokenServerUrl }}</dd>
                        </div>
                        <div class="flex flex-col sm:flex-row sm:gap-4">
                            <dt class="text-sm font-medium text-gray-500 sm:w-48">Storage API URL</dt>
                            <dd class="text-sm text-gray-900 font-mono">{{ syncConfig.storageUrl }}</dd>
                        </div>
                        <div class="flex flex-col sm:flex-row sm:gap-4">
                            <dt class="text-sm font-medium text-gray-500 sm:w-48">Authentik URL</dt>
                            <dd class="text-sm text-gray-900 font-mono">{{ syncConfig.authentikUrl }}</dd>
                        </div>
                        <div class="flex flex-col sm:flex-row sm:gap-4">
                            <dt class="text-sm font-medium text-gray-500 sm:w-48">Protocol</dt>
                            <dd class="text-sm text-gray-900">Firefox Sync 1.5</dd>
                        </div>
                        <div class="flex flex-col sm:flex-row sm:gap-4">
                            <dt class="text-sm font-medium text-gray-500 sm:w-48">Encryption</dt>
                            <dd class="text-sm text-gray-900">AES-256-GCM (E2E, client-side)</dd>
                        </div>
                    </dl>
                </div>
            </div>

            <!-- Danger Zone -->
            <div class="bg-white rounded-xl border border-red-200">
                <div class="px-5 py-4 border-b border-red-100">
                    <h2 class="text-lg font-semibold text-red-600">Danger Zone</h2>
                </div>
                <div class="p-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-sm font-medium text-gray-900">Delete all sync data</h3>
                            <p class="text-sm text-gray-500 mt-1">
                                Permanently remove all your synced data from the server.
                                This will not affect local data on your devices.
                            </p>
                        </div>
                        <button
                            @click="deleteAllData"
                            class="flex-shrink-0 px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-lg hover:bg-red-700 transition-colors"
                        >
                            Delete All Data
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
