<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    collections: Array,
});

function formatTime(ts) {
    if (!ts) return 'Never';
    return new Date(ts).toLocaleString();
}
</script>

<template>
    <AppLayout>
        <h1 class="text-xl font-semibold text-gray-900 mb-6">Collections</h1>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <Link
                v-for="col in collections"
                :key="col.id"
                :href="`/collections/${col.name}`"
                class="bg-white border border-gray-200 rounded-lg p-4 hover:border-primary-300 transition-colors block"
            >
                <h3 class="text-sm font-semibold text-gray-900 mb-1">{{ col.name }}</h3>
                <p class="text-xs text-gray-500 mb-3">{{ col.description || 'No description' }}</p>
                <div class="flex items-center justify-between text-xs text-gray-400">
                    <span>{{ col.record_count ?? 0 }} records</span>
                    <span>Last modified: {{ formatTime(col.last_modified) }}</span>
                </div>
            </Link>
        </div>

        <div v-if="!collections?.length" class="text-center py-12 text-sm text-gray-400">
            No collections available
        </div>
    </AppLayout>
</template>
