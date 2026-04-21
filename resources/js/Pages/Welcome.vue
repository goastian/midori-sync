<script setup>
const features = [
    { title: 'End-to-End Encryption', desc: 'XChaCha20-Poly1305 encryption ensures only you can read your data.' },
    { title: 'Cross-Device Sync', desc: 'Bookmarks, history, tabs, and settings — everywhere.' },
    { title: 'Self-Hosted', desc: 'Your server, your data. No third-party dependencies.' },
    { title: 'Midori Native', desc: 'Deep integration with Midori Tab and Midori Privacy.' },
];
</script>

<template>
    <div class="min-h-screen bg-white">
        <!-- Nav -->
        <nav class="border-b border-gray-100">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 flex items-center justify-between h-14">
                <div class="flex items-center gap-2">
                    <svg class="w-6 h-6 text-primary-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/>
                    </svg>
                    <span class="font-semibold text-gray-900">Midori Sync</span>
                </div>
                <a
                    href="/auth/redirect"
                    class="px-4 py-1.5 bg-primary-600 text-white text-sm font-medium rounded-md hover:bg-primary-700 transition-colors"
                >
                    Sign In
                </a>
            </div>
        </nav>

        <!-- Hero -->
        <section class="max-w-5xl mx-auto px-4 sm:px-6 py-20 text-center">
            <h1 class="text-4xl font-bold text-gray-900 mb-4">
                Sync your browser,<br>on your terms
            </h1>
            <p class="text-lg text-gray-500 max-w-xl mx-auto mb-8">
                A self-hosted, end-to-end encrypted sync service for Midori and Firefox.
                Your data stays yours.
            </p>
            <a
                href="/auth/redirect"
                class="inline-flex items-center px-6 py-2.5 bg-primary-600 text-white font-medium rounded-lg hover:bg-primary-700 transition-colors"
            >
                Get Started
            </a>
        </section>

        <!-- Features -->
        <section class="max-w-5xl mx-auto px-4 sm:px-6 pb-20">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                <div
                    v-for="feat in features"
                    :key="feat.title"
                    class="p-6 border border-gray-200 rounded-lg"
                >
                    <h3 class="font-semibold text-gray-900 mb-1">{{ feat.title }}</h3>
                    <p class="text-sm text-gray-500">{{ feat.desc }}</p>
                </div>
            </div>
        </section>
    </div>
</template>
