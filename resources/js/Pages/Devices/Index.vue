<script setup>
import { ref, nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    devices: Array,
});

const editingDeviceId = ref(null);
const editingName = ref('');
const editingError = ref('');
const nameInput = ref(null);

function formatTime(ts) {
    if (!ts) return 'Never';
    return new Date(ts).toLocaleString();
}

function deleteDevice(device) {
    if (!confirm(`Remove device "${device.name}"?`)) return;
    router.delete(`/devices/${device.device_id}`);
}

async function startEdit(device) {
    editingDeviceId.value = device.device_id;
    editingName.value = device.name;
    editingError.value = '';
    await nextTick();
    nameInput.value?.focus();
    nameInput.value?.select();
}

function cancelEdit() {
    editingDeviceId.value = null;
    editingName.value = '';
    editingError.value = '';
}

function saveEdit(device) {
    const name = editingName.value.trim();
    if (!name) {
        editingError.value = 'Name cannot be empty.';
        return;
    }
    if (name === device.name) {
        cancelEdit();
        return;
    }
    router.patch(
        `/devices/${device.device_id}`,
        { name },
        {
            preserveScroll: true,
            onSuccess: () => cancelEdit(),
            onError: (errors) => {
                editingError.value = errors.name || 'Failed to rename device.';
            },
        },
    );
}
</script>

<template>
    <AppLayout>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100 mb-6">Devices</h1>

        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg">
            <div v-if="!devices?.length" class="px-4 py-12 text-center text-sm text-gray-400 dark:text-gray-500">
                No devices registered yet. Install the extension and sign in to register a device.
            </div>

            <div
                v-for="device in devices"
                :key="device.id"
                class="flex items-center justify-between px-4 py-4 border-b border-gray-100 dark:border-gray-800 last:border-b-0"
            >
                <div class="flex items-center gap-4 flex-1 min-w-0">
                    <div class="w-10 h-10 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                            <line x1="8" y1="21" x2="16" y2="21"/>
                            <line x1="12" y1="17" x2="12" y2="21"/>
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <template v-if="editingDeviceId === device.device_id">
                            <form
                                class="flex items-center gap-2"
                                @submit.prevent="saveEdit(device)"
                            >
                                <input
                                    ref="nameInput"
                                    v-model="editingName"
                                    type="text"
                                    maxlength="100"
                                    class="flex-1 px-2 py-1 text-sm rounded-md border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-primary-500"
                                    @keydown.esc="cancelEdit"
                                />
                                <button
                                    type="submit"
                                    class="text-xs px-2 py-1 rounded-md bg-primary-600 text-white hover:bg-primary-700"
                                >
                                    Save
                                </button>
                                <button
                                    type="button"
                                    @click="cancelEdit"
                                    class="text-xs text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                                >
                                    Cancel
                                </button>
                            </form>
                            <p v-if="editingError" class="mt-1 text-xs text-red-500">{{ editingError }}</p>
                            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                                {{ device.type }} · {{ device.os }}
                            </p>
                        </template>
                        <template v-else>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ device.name }}</p>
                            <p class="text-xs text-gray-400 dark:text-gray-500">
                                {{ device.type }} · {{ device.os }}
                            </p>
                            <p class="text-xs text-gray-400 dark:text-gray-500">
                                Last synced: {{ formatTime(device.last_sync_at) }}
                            </p>
                        </template>
                    </div>
                </div>
                <div v-if="editingDeviceId !== device.device_id" class="flex items-center gap-3 ml-4">
                    <button
                        @click="startEdit(device)"
                        class="text-xs text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 font-medium"
                    >
                        Rename
                    </button>
                    <button
                        @click="deleteDevice(device)"
                        class="text-xs text-red-500 hover:text-red-700 font-medium"
                    >
                        Remove
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
