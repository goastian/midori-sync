<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    sessions: { type: Array, default: () => [] },
    pagination: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({ status: 'all', q: '', per_page: 25 }) },
    recentLogins: { type: Array, default: () => [] },
    activeTokenCount: { type: Number, default: 0 },
    expiredCount: { type: Number, default: 0 },
});

const status = ref(props.filters.status || 'all');
const search = ref(props.filters.q || '');
const perPage = ref(props.filters.per_page || 25);

let searchTimer = null;

function applyFilters(extra = {}) {
    router.get('/audit', {
        status: status.value,
        q: search.value,
        per_page: perPage.value,
        ...extra,
    }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

watch(status, () => applyFilters({ page: 1 }));
watch(perPage, () => applyFilters({ page: 1 }));
watch(search, () => {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => applyFilters({ page: 1 }), 300);
});

function goToPage(url) {
    if (!url) return;
    router.get(url, {}, { preserveScroll: true, preserveState: true, replace: true });
}

function decodeLabel(label) {
    if (label == null) return '';
    const txt = document.createElement('textarea');
    txt.innerHTML = String(label);
    return txt.value.replace(/<[^>]*>/g, '');
}

function resetFilters() {
    status.value = 'all';
    search.value = '';
    perPage.value = 25;
}

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
                v-if="activeTokenCount > 0"
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
                <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ expiredCount }}</p>
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

        <!-- Sessions: filters + paginated list -->
        <div>
            <div class="flex items-center justify-between mb-3 gap-3 flex-wrap">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Sync Sessions</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    <span v-if="pagination.total">
                        Showing {{ pagination.from || 0 }}–{{ pagination.to || 0 }} of {{ pagination.total }}
                    </span>
                </p>
            </div>

            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg p-3 mb-3">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="inline-flex rounded-md border border-gray-200 dark:border-gray-800 overflow-hidden text-xs">
                        <button
                            v-for="opt in [{v:'all',l:'All'},{v:'active',l:'Active'},{v:'expired',l:'Expired'}]"
                            :key="opt.v"
                            type="button"
                            @click="status = opt.v"
                            :class="[
                                'px-3 py-1.5 transition-colors',
                                status === opt.v
                                    ? 'bg-primary-600 text-white dark:bg-primary-500'
                                    : 'bg-white dark:bg-gray-900 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800',
                                opt.v !== 'all' ? 'border-l border-gray-200 dark:border-gray-800' : ''
                            ]"
                        >{{ opt.l }}</button>
                    </div>
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Search by IP, device, or client…"
                        class="flex-1 min-w-[200px] text-sm px-3 py-1.5 border border-gray-200 dark:border-gray-800 rounded-md bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-primary-500"
                    />
                    <select
                        v-model.number="perPage"
                        class="text-xs px-2 py-1.5 border border-gray-200 dark:border-gray-800 rounded-md bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100"
                    >
                        <option :value="25">25 / page</option>
                        <option :value="50">50 / page</option>
                        <option :value="100">100 / page</option>
                    </select>
                    <button
                        v-if="status !== 'all' || search || perPage !== 25"
                        type="button"
                        @click="resetFilters"
                        class="text-xs text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                    >Reset</button>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg divide-y divide-gray-100 dark:divide-gray-800">
                <div v-if="!sessions.length" class="px-4 py-8 text-center text-sm text-gray-400 dark:text-gray-500">
                    No sessions match the current filters
                </div>
                <div
                    v-for="session in sessions"
                    :key="session.id"
                    class="flex items-start justify-between gap-3 px-4 py-3"
                >
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">
                                {{ session.device?.name || 'Unknown device' }}
                            </p>
                            <span
                                v-if="session.active"
                                class="text-[10px] uppercase tracking-wide font-semibold px-1.5 py-0.5 rounded bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300"
                            >Active</span>
                            <span
                                v-else
                                class="text-[10px] uppercase tracking-wide font-semibold px-1.5 py-0.5 rounded bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400"
                            >Expired</span>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                            {{ shortUserAgent(session.user_agent) }}
                        </p>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                            IP <span class="font-mono">{{ session.ip_address || 'unknown' }}</span>
                            · Created {{ formatDateTime(session.created_at) }}
                            · {{ session.active ? 'Expires' : 'Expired' }} {{ formatDateTime(session.expires_at) }}
                        </p>
                    </div>
                    <button
                        v-if="session.active"
                        @click="revoke(session)"
                        class="text-xs text-red-500 hover:text-red-700 font-medium shrink-0"
                    >
                        Revoke
                    </button>
                </div>
            </div>

            <!-- Pagination -->
            <nav v-if="pagination.last_page > 1" class="mt-4 flex items-center justify-between flex-wrap gap-2">
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Page {{ pagination.current_page }} of {{ pagination.last_page }}
                </p>
                <div class="inline-flex flex-wrap gap-1">
                    <button
                        v-for="(link, i) in pagination.links"
                        :key="i"
                        type="button"
                        :disabled="!link.url || link.active"
                        @click="goToPage(link.url)"
                        :class="[
                            'min-w-[2rem] text-center text-xs px-2.5 py-1 border rounded-md transition-colors',
                            link.active
                                ? 'bg-primary-600 text-white border-primary-600 dark:bg-primary-500 dark:border-primary-500'
                                : link.url
                                    ? 'bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 border-gray-200 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800'
                                    : 'bg-gray-50 dark:bg-gray-900/50 text-gray-300 dark:text-gray-600 border-gray-100 dark:border-gray-800 cursor-not-allowed'
                        ]"
                    >{{ decodeLabel(link.label) }}</button>
                </div>
            </nav>
        </div>
    </AppLayout>
</template>
