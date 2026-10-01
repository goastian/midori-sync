<script setup>
import { computed, nextTick, onUnmounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    devices: Array,
});

const editingDeviceId = ref(null);
const editingName = ref('');
const editingError = ref('');
const nameInput = ref(null);
const pairingCode = ref('');
const secondsRemaining = ref(0);
const pairingLoading = ref(false);
const pairingError = ref('');
const copied = ref(false);
let pairingTimer;

const displayedCode = computed(() => pairingCode.value.match(/.{1,4}/g)?.join('-') ?? '');
const timeRemaining = computed(() => `${Math.floor(secondsRemaining.value / 60)}:${String(secondsRemaining.value % 60).padStart(2, '0')}`);

function stopPairingTimer() {
    clearInterval(pairingTimer);
    pairingTimer = undefined;
}

async function generatePairingCode() {
    if (pairingLoading.value) return;
    pairingLoading.value = true;
    pairingError.value = '';
    pairingCode.value = '';
    copied.value = false;
    stopPairingTimer();
    try {
        const { data } = await axios.post('/devices/pairing-code', {}, { withXSRFToken: true });
        pairingCode.value = data.pairing_token;
        const expiresAt = Date.now() + data.expires_in * 1000;
        secondsRemaining.value = data.expires_in;
        pairingTimer = setInterval(() => {
            secondsRemaining.value = Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000));
            if (secondsRemaining.value === 0) {
                pairingCode.value = '';
                stopPairingTimer();
            }
        }, 1000);
    } catch (error) {
        const reason = error.response?.data?.error;
        if (error.response?.status === 401 || error.response?.status === 419) {
            pairingError.value = 'Your session expired. Sign in again to generate a code.';
        } else if (error.response?.status === 429) {
            pairingError.value = 'Too many codes requested. Try again in a minute.';
        } else if (reason === 'server_issuer_not_configured') {
            pairingError.value = 'The Sync server is missing its Authentik issuer configuration. Contact the server administrator.';
        } else if (reason === 'native_identity_required') {
            pairingError.value = 'This account does not have a valid native identity. Contact the Sync server administrator.';
        } else if (reason === 'identity_issuer_mismatch' || reason === 'invalid_account_identity') {
            pairingError.value = 'This account cannot pair with Midori Desktop. Contact the Sync server administrator.';
        } else {
            pairingError.value = 'Could not generate a code. Please try again.';
        }
    } finally {
        pairingLoading.value = false;
    }
}

async function copyPairingCode() {
    try {
        await navigator.clipboard.writeText(pairingCode.value);
        copied.value = true;
    } catch {
        copied.value = false;
    }
}

onUnmounted(stopPairingTimer);

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

        <section class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg p-5 mb-6" aria-labelledby="pairing-heading">
            <h2 id="pairing-heading" class="text-base font-semibold text-gray-900 dark:text-gray-100">Connect Midori Desktop</h2>
            <p class="text-sm text-gray-600 dark:text-gray-400 mt-2">
                Open the Sync and Link popup in Midori Desktop, enter the code shown here, then select Connect account.
            </p>
            <div v-if="pairingCode" class="mt-4">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="font-mono text-xl sm:text-2xl font-semibold tracking-wider text-gray-900 dark:text-gray-100 select-all" aria-label="Pairing code">{{ displayedCode }}</span>
                    <button type="button" @click="copyPairingCode" class="text-sm font-medium text-primary-600 dark:text-primary-400 hover:underline">
                        {{ copied ? 'Copied' : 'Copy code' }}
                    </button>
                </div>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Expires in {{ timeRemaining }}. The code works once.</p>
            </div>
            <p v-if="pairingError" class="mt-3 text-sm text-red-600 dark:text-red-400" role="alert">{{ pairingError }}</p>
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <button type="button" :disabled="pairingLoading" @click="generatePairingCode" class="rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                    {{ pairingLoading ? 'Generating…' : pairingCode ? 'Generate another code' : 'Generate pairing code' }}
                </button>
                <button type="button" @click="router.reload({ only: ['devices'] })" class="text-sm text-gray-600 dark:text-gray-400 hover:underline">Refresh devices</button>
            </div>
        </section>

        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg">
            <div v-if="!devices?.length" class="px-4 py-12 text-center text-sm text-gray-400 dark:text-gray-500">
                No devices connected yet. Generate a code above to connect Midori Desktop.
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
