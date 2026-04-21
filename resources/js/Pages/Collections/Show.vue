<script setup>
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    collection: Object,
    records: Array,
    meta: Object,
});

function formatTime(ts) {
    if (!ts) return '—';
    return new Date(ts * 1000).toLocaleString();
}

function deleteRecord(recordId) {
    if (!confirm('Delete this record?')) return;
    router.delete(`/collections/${props.collection.name}/${recordId}`);
}

function deleteAll() {
    if (!confirm(`Delete ALL records in "${props.collection.name}"? This cannot be undone.`)) return;
    router.delete(`/collections/${props.collection.name}`);
}
</script>

<template>
    <AppLayout>
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">{{ collection.name }}</h1>
                <p class="text-sm text-gray-500">{{ collection.description }}</p>
            </div>
            <button
                @click="deleteAll"
                class="text-xs text-red-500 hover:text-red-700 font-medium px-3 py-1.5 border border-red-200 rounded-md hover:bg-red-50 transition-colors"
            >
                Delete All
            </button>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-3 gap-4 mb-6">
            <div class="bg-white border border-gray-200 rounded-lg p-3">
                <p class="text-xs text-gray-500">Records</p>
                <p class="text-lg font-semibold text-gray-900">{{ meta?.total ?? 0 }}</p>
            </div>
            <div class="bg-white border border-gray-200 rounded-lg p-3">
                <p class="text-xs text-gray-500">Size</p>
                <p class="text-lg font-semibold text-gray-900">{{ meta?.size_display ?? '0 B' }}</p>
            </div>
            <div class="bg-white border border-gray-200 rounded-lg p-3">
                <p class="text-xs text-gray-500">Last Modified</p>
                <p class="text-lg font-semibold text-gray-900">{{ formatTime(meta?.last_modified) }}</p>
            </div>
        </div>

        <!-- Records table -->
        <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-2 text-xs font-medium text-gray-500 uppercase">Record ID</th>
                        <th class="text-left px-4 py-2 text-xs font-medium text-gray-500 uppercase">Version</th>
                        <th class="text-left px-4 py-2 text-xs font-medium text-gray-500 uppercase">Modified</th>
                        <th class="text-right px-4 py-2 text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-if="!records?.length">
                        <td colspan="4" class="text-center py-8 text-gray-400">No records in this collection</td>
                    </tr>
                    <tr v-for="record in records" :key="record.id" class="hover:bg-gray-50">
                        <td class="px-4 py-2 font-mono text-xs text-gray-700">{{ record.record_id }}</td>
                        <td class="px-4 py-2 text-gray-500">v{{ record.version }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ formatTime(record.modified_at) }}</td>
                        <td class="px-4 py-2 text-right">
                            <button
                                @click="deleteRecord(record.record_id)"
                                class="text-xs text-red-500 hover:text-red-700"
                            >
                                Delete
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
