<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { useTheme } from '@/composables/useTheme';

const page = usePage();
const user = page.props.auth?.user;
const { isDark, toggle: toggleTheme } = useTheme();

const navigation = [
    { name: 'Devices', href: '/devices' },
    { name: 'Security', href: '/audit' },
    { name: 'Settings', href: '/settings' },
];
</script>

<template>
    <div class="min-h-screen flex flex-col bg-white dark:bg-gray-950 text-gray-900 dark:text-gray-100">
        <header class="border-b border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-950">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between gap-4">
                <div class="flex items-center gap-6 min-w-0">
                    <Link href="/dashboard" class="flex items-center gap-2 shrink-0">
                        <svg class="w-7 h-7 text-primary-600 dark:text-primary-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/>
                        </svg>
                        <span class="text-lg font-medium">Midori <span class="text-primary-600 dark:text-primary-400">sync</span></span>
                    </Link>
                    <nav class="hidden sm:flex items-center gap-5 text-sm text-gray-500 dark:text-gray-400">
                        <Link
                            v-for="item in navigation"
                            :key="item.name"
                            :href="item.href"
                            class="hover:text-gray-900 dark:hover:text-gray-100 transition-colors"
                        >
                            {{ item.name }}
                        </Link>
                    </nav>
                </div>
                <div class="flex items-center gap-3 shrink-0">
                    <button
                        type="button"
                        @click="toggleTheme"
                        :aria-label="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
                        :title="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
                        class="p-1.5 rounded-md text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800"
                    >
                        <svg v-if="isDark" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="4"/>
                            <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/>
                        </svg>
                        <svg v-else class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                        </svg>
                    </button>
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="w-8 h-8 rounded-full bg-primary-700 text-white flex items-center justify-center text-sm font-medium" aria-hidden="true">
                            {{ user?.name?.charAt(0)?.toUpperCase() || '?' }}
                        </span>
                        <span class="hidden sm:block text-sm font-medium truncate max-w-48">{{ user?.name }}</span>
                    </div>
                    <Link
                        href="/auth/logout"
                        method="post"
                        as="button"
                        title="Sign out"
                        aria-label="Sign out"
                        class="p-1.5 rounded-md text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800"
                    >
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>
                        </svg>
                    </Link>
                </div>
            </div>
        </header>

        <main class="flex-1">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 py-10">
                <slot />
            </div>
        </main>

        <footer class="border-t border-gray-200 dark:border-gray-800 py-6 text-center text-xs text-gray-500 dark:text-gray-400">
            Your data is end-to-end encrypted with a key that never leaves your devices.
        </footer>
    </div>
</template>
