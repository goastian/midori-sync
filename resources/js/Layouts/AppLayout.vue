<script setup>
import { ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const user = page.props.auth?.user;
const mobileMenuOpen = ref(false);

const navigation = [
    { name: 'Dashboard', href: '/dashboard', icon: 'home' },
    { name: 'Devices', href: '/devices', icon: 'monitor' },
    { name: 'Collections', href: '/collections', icon: 'database' },
    { name: 'Settings', href: '/settings', icon: 'settings' },
];

function isActive(href) {
    return page.url.startsWith(href);
}
</script>

<template>
    <div class="min-h-screen bg-gray-50">
        <!-- Sidebar -->
        <aside class="fixed inset-y-0 left-0 z-30 w-56 bg-white border-r border-gray-200 hidden lg:block">
            <div class="flex items-center gap-2 px-5 py-4 border-b border-gray-200">
                <svg class="w-6 h-6 text-primary-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/>
                </svg>
                <span class="font-semibold text-gray-900">Midori Sync</span>
            </div>

            <nav class="px-3 py-4 space-y-1">
                <Link
                    v-for="item in navigation"
                    :key="item.name"
                    :href="item.href"
                    :class="[
                        'flex items-center gap-3 px-3 py-2 rounded-md text-sm font-medium transition-colors',
                        isActive(item.href)
                            ? 'bg-primary-50 text-primary-700'
                            : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'
                    ]"
                >
                    {{ item.name }}
                </Link>
            </nav>

            <div class="absolute bottom-0 left-0 right-0 p-4 border-t border-gray-200">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-primary-100 flex items-center justify-center text-primary-700 text-sm font-medium">
                        {{ user?.name?.charAt(0)?.toUpperCase() || '?' }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-gray-900 truncate">{{ user?.name }}</p>
                        <p class="text-xs text-gray-500 truncate">{{ user?.email }}</p>
                    </div>
                </div>
                <Link
                    href="/auth/logout"
                    method="post"
                    as="button"
                    class="mt-3 w-full text-left text-xs text-gray-500 hover:text-gray-700"
                >
                    Sign out
                </Link>
            </div>
        </aside>

        <!-- Mobile header -->
        <header class="lg:hidden sticky top-0 z-20 bg-white border-b border-gray-200">
            <div class="flex items-center justify-between px-4 py-3">
                <div class="flex items-center gap-2">
                    <svg class="w-5 h-5 text-primary-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/>
                    </svg>
                    <span class="font-semibold text-sm text-gray-900">Midori Sync</span>
                </div>
                <button @click="mobileMenuOpen = !mobileMenuOpen" class="p-1.5 rounded-md text-gray-500 hover:bg-gray-100">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path v-if="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
                        <path v-else stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <!-- Mobile nav -->
            <nav v-if="mobileMenuOpen" class="px-3 pb-3 space-y-1">
                <Link
                    v-for="item in navigation"
                    :key="item.name"
                    :href="item.href"
                    :class="[
                        'block px-3 py-2 rounded-md text-sm font-medium',
                        isActive(item.href)
                            ? 'bg-primary-50 text-primary-700'
                            : 'text-gray-600 hover:bg-gray-50'
                    ]"
                    @click="mobileMenuOpen = false"
                >
                    {{ item.name }}
                </Link>
            </nav>
        </header>

        <!-- Main content -->
        <main class="lg:pl-56">
            <div class="max-w-4xl mx-auto px-4 sm:px-6 py-8">
                <slot />
            </div>
        </main>
    </div>
</template>
