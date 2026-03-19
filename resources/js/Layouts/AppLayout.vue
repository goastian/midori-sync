<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const page = usePage();
const mobileMenuOpen = ref(false);

const navigation = [
    { name: 'Dashboard', route: 'dashboard' },
    { name: 'Devices', route: 'devices' },
    { name: 'Settings', route: 'settings' },
];
</script>

<template>
    <div class="min-h-screen bg-gray-50">
        <!-- Navigation -->
        <nav class="bg-white border-b border-gray-200">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between h-16">
                    <div class="flex items-center">
                        <!-- Logo -->
                        <Link :href="route('landing')" class="flex items-center gap-2">
                            <div class="w-8 h-8 bg-midori-500 rounded-lg flex items-center justify-center">
                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                </svg>
                            </div>
                            <span class="text-lg font-bold text-gray-900">Midori Sync</span>
                        </Link>

                        <!-- Desktop navigation -->
                        <div class="hidden sm:ml-8 sm:flex sm:space-x-4">
                            <Link
                                v-for="item in navigation"
                                :key="item.route"
                                :href="route(item.route)"
                                :class="[
                                    route().current(item.route)
                                        ? 'border-midori-500 text-gray-900'
                                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300',
                                    'inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium'
                                ]"
                            >
                                {{ item.name }}
                            </Link>
                        </div>
                    </div>

                    <!-- User menu -->
                    <div class="flex items-center gap-4">
                        <div class="hidden sm:flex items-center gap-3">
                            <img
                                v-if="page.props.auth.user?.avatar"
                                :src="page.props.auth.user.avatar"
                                :alt="page.props.auth.user.name"
                                class="w-8 h-8 rounded-full"
                            />
                            <div v-else class="w-8 h-8 bg-midori-100 text-midori-700 rounded-full flex items-center justify-center text-sm font-medium">
                                {{ page.props.auth.user?.name?.charAt(0)?.toUpperCase() }}
                            </div>
                            <span class="text-sm text-gray-700">{{ page.props.auth.user?.name }}</span>
                        </div>
                        <Link
                            :href="route('logout')"
                            method="post"
                            as="button"
                            class="text-sm text-gray-500 hover:text-gray-700"
                        >
                            Logout
                        </Link>

                        <!-- Mobile menu button -->
                        <button
                            @click="mobileMenuOpen = !mobileMenuOpen"
                            class="sm:hidden inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100"
                        >
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path v-if="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                                <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Mobile menu -->
            <div v-if="mobileMenuOpen" class="sm:hidden border-t border-gray-200">
                <div class="py-2 space-y-1">
                    <Link
                        v-for="item in navigation"
                        :key="item.route"
                        :href="route(item.route)"
                        :class="[
                            route().current(item.route)
                                ? 'bg-midori-50 border-midori-500 text-midori-700'
                                : 'border-transparent text-gray-500 hover:bg-gray-50 hover:text-gray-700',
                            'block pl-3 pr-4 py-2 border-l-4 text-base font-medium'
                        ]"
                    >
                        {{ item.name }}
                    </Link>
                </div>
            </div>
        </nav>

        <!-- Flash messages -->
        <div v-if="page.props.flash?.success" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
            <div class="bg-midori-50 border border-midori-200 text-midori-800 px-4 py-3 rounded-lg text-sm">
                {{ page.props.flash.success }}
            </div>
        </div>
        <div v-if="page.props.flash?.error" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
            <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg text-sm">
                {{ page.props.flash.error }}
            </div>
        </div>

        <!-- Page content -->
        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <slot />
        </main>
    </div>
</template>
