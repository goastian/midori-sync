<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    sessions: { type: Array, default: () => [] },
    recentLogins: { type: Array, default: () => [] },
    activeTokenCount: { type: Number, default: 0 },
});

const activeSessions = computed(() => props.sessions.filter((s) => s.active));
const expiredSessions = computed(() => props.sessions.filter((s) => !s.active));

function formatDateTime(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString();
}

function shortUserAgent(ua) {
    if (!ua) return 'Unknown';
    const match = ua.match(/(Firefox|Chrome|Safari|Edge|Opera)\/[\d.]+/);
    const os = ua.match(/\((.*?)\)/)?.[1]?.split(';')[0]?.trim();
    if (match) {
        return os ? `${match[0]} · ${os}` : match[0];
    }
    return ua.length > 80 ? ua.slice(0, 80) + '…' : ua;
}

function revoke(session) {
    if (!confirm('Revoke this session? Any device using this token will be signed out.')) return;
    router.delete(`/audit/sessions/${session.id}`, { preserveScroll: true });
}

function revokeAll() {
    if (!confirm('Revoke ALL sync sessions? Every device will be signed out and must re-authenticate.')) return;
    router.delete('/audit/sessions', { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <div class="flex items-center justify-between mb-6 gap-3">
            <div>
                <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">Audit</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400">Review login history and manage active sync sessions.</p>
            </div>
            <button
                v-if="activeSessions.length > 0"
                @click="revokeAll"
                class="text-xs text-red-500 hover:text-red-700 font-medium px-3 py-1.5 border border-red-200 dark:border-red-900 rounded-md hover:bg-red-50 dark:hover:bg-red-950/40 transition-colors shrink-0"
            >
                Revoke All Sessions
            </button>
        </div>

        <!-- Summary -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg p-4">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Active tokens</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ activeTokenCount }}</p>
            </div>
            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg p-4">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Recent logins</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ recentLogins.length }}</p>
            </div>
            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg p-4">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Expired / revoked</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ expiredSessions.length }}</p>
            </div>
        </div>

        <!-- Recent logins -->
        <div class="mb-8">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">Recent Logins</h2>
            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
                        <tr>
                            <th class="text-left px-4 py-2 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">When</th>
                            <th class="text-left px-4 py-2 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Device</th>
                            <th class="text-left px-4 py-2 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">IP</th>
                            <th class="text-left px-4 py-2 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Client</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <tr v-if="!recentLogins.length">
                            <td colspan="4" class="text-center py-8 text-gray-400 dark:text-gray-500">No login history</td>
                        </tr>
                        <tr v-for="(login, i) in recentLogins" :key="i">
                            <td class="px-4 py-2 text-gray-700 dark:text-gray-300 whitespace-nowrap">{{ formatDateTime(login.created_at) }}</td>
                            <td class="px-4 py-2 text-gray-700 dark:text-gray-300">{{ login.device?.name || '—' }}</td>
                            <td class="px-4 py-2 font-mono text-xs text-gray-500 dark:text-gray-400">{{ login.ip_address || '—' }}</td>
                            <td class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400">{{ shortUserAgent(login.user_agent) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Active sessions -->
        <div class="mb-8">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">Active Sync Sessions</h2>
            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg divide-y divide-gray-100 dark:divide-gray-800">
                <div v-if="!activeSessions.length" class="px-4 py-8 text-center text-sm text-gray-400 dark:text-gray-500">
                    No active sessions
                </div>
                <div
                    v-for="session in activeSessions"
                    :key="session.id"
                    class="flex items-start justify-between gap-3 px-4 py-3"
                >
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">
                                {{ session.device?.name || 'Unknown device' }}
                            </p>
                            <span class="text-[10px] uppercase tracking-wide font-semibold px-1.5 py-0.5 rounded bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300">
                                Active
                            </span>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                            {{ shortUserAgent(session.user_agent) }}
                        </p>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                            IP <span class="font-mono">{{ session.ip_address || 'unknown' }}</span>
                            · Created {{ formatDateTime(session.created_at) }}
                            · Expires {{ formatDateTime(session.expires_at) }}
                        </p>
                    </div>
                    <button
                        @click="revoke(session)"
                        class="text-xs text-red-500 hover:text-red-700 font-medium shrink-0"
                    >
                        Revoke
                    </button>
                </div>
            </div>
        </div>

        <!-- History -->
        <div>
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">Session History</h2>
            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg divide-y divide-gray-100 dark:divide-gray-800">
                <div v-if="!expiredSessions.length" class="px-4 py-8 text-center text-sm text-gray-400 dark:text-gray-500">
                    No expired sessions recorded
                </div>
                <div
                    v-for="session in expiredSessions"
                    :key="session.id"
                    class="flex items-start justify-between gap-3 px-4 py-3"
                >
                    <div class="flex-1 min-w-0">
                        <p class="text-sm text-gray-700 dark:text-gray-300 truncate">
                            {{ session.device?.name || 'Unknown device' }}
                        </p>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                            IP <span class="font-mono">{{ session.ip_address || 'unknown' }}</span>
                            · {{ formatDateTime(session.created_at) }}
                            → {{ formatDateTime(session.expires_at) }}
                        </p>
                    </div>
                    <span class="text-[10px] uppercase tracking-wide font-semibold px-1.5 py-0.5 rounded bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400 shrink-0">
                        Expired
                    </span>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
