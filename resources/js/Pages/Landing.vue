<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const isAuthenticated = !!page.props.auth?.user;

const features = [
    {
        title: 'Bookmarks',
        description: 'Keep your bookmarks in sync across all your devices seamlessly.',
        icon: 'bookmark',
    },
    {
        title: 'Passwords',
        description: 'Securely sync your saved passwords with end-to-end encryption.',
        icon: 'lock',
    },
    {
        title: 'Open Tabs',
        description: 'Access your open tabs from any connected device instantly.',
        icon: 'tabs',
    },
    {
        title: 'History',
        description: 'Your browsing history available everywhere you need it.',
        icon: 'history',
    },
    {
        title: 'Form Data',
        description: 'Auto-fill data synced across devices for faster browsing.',
        icon: 'form',
    },
    {
        title: 'Add-ons',
        description: 'Keep your extensions and preferences consistent everywhere.',
        icon: 'addon',
    },
];
</script>

<template>
    <Head title="Sync Your Browser" />

    <div class="min-h-screen bg-white">
        <!-- Header -->
        <header class="bg-white border-b border-gray-100">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center h-16">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 bg-midori-500 rounded-lg flex items-center justify-center">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                        </div>
                        <span class="text-lg font-bold text-gray-900">Midori Sync</span>
                    </div>
                    <div>
                        <Link
                            v-if="isAuthenticated"
                            :href="route('dashboard')"
                            class="inline-flex items-center px-4 py-2 bg-midori-500 text-white text-sm font-medium rounded-lg hover:bg-midori-600 transition-colors"
                        >
                            Dashboard
                        </Link>
                        <a
                            v-else
                            :href="route('auth.redirect')"
                            class="inline-flex items-center px-4 py-2 bg-midori-500 text-white text-sm font-medium rounded-lg hover:bg-midori-600 transition-colors"
                        >
                            Sign in
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Hero Section -->
        <section class="relative overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-br from-midori-50 via-white to-emerald-50"></div>
            <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-24 sm:py-32">
                <div class="text-center max-w-3xl mx-auto">
                    <div class="inline-flex items-center gap-2 bg-midori-100 text-midori-800 text-sm font-medium px-3 py-1 rounded-full mb-6">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                        End-to-end encrypted
                    </div>
                    <h1 class="text-4xl sm:text-5xl lg:text-6xl font-bold text-gray-900 tracking-tight">
                        Your browser data,
                        <span class="text-midori-600">everywhere</span>
                    </h1>
                    <p class="mt-6 text-lg sm:text-xl text-gray-600 leading-relaxed">
                        Midori Sync keeps your bookmarks, passwords, tabs, and browsing history
                        in perfect harmony across all your devices — with zero-knowledge encryption
                        that ensures only you can read your data.
                    </p>
                    <div class="mt-10 flex flex-col sm:flex-row gap-4 justify-center">
                        <a
                            :href="isAuthenticated ? route('dashboard') : route('auth.redirect')"
                            class="inline-flex items-center justify-center px-6 py-3 bg-midori-500 text-white text-base font-medium rounded-xl hover:bg-midori-600 shadow-lg shadow-midori-500/25 transition-all hover:shadow-xl hover:shadow-midori-500/30"
                        >
                            {{ isAuthenticated ? 'Go to Dashboard' : 'Get Started' }}
                            <svg class="ml-2 w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                            </svg>
                        </a>
                        <a
                            href="#features"
                            class="inline-flex items-center justify-center px-6 py-3 bg-white text-gray-700 text-base font-medium rounded-xl border border-gray-200 hover:bg-gray-50 transition-colors"
                        >
                            Learn more
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <!-- Features Section -->
        <section id="features" class="py-20 sm:py-28 bg-white">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center max-w-2xl mx-auto mb-16">
                    <h2 class="text-3xl sm:text-4xl font-bold text-gray-900">
                        Everything you need, synced
                    </h2>
                    <p class="mt-4 text-lg text-gray-600">
                        Midori Sync supports all the data types you rely on daily.
                    </p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                    <div
                        v-for="feature in features"
                        :key="feature.title"
                        class="bg-gray-50 rounded-2xl p-6 hover:bg-midori-50 transition-colors group"
                    >
                        <div class="w-12 h-12 bg-midori-100 text-midori-600 rounded-xl flex items-center justify-center mb-4 group-hover:bg-midori-200 transition-colors">
                            <!-- Bookmark -->
                            <svg v-if="feature.icon === 'bookmark'" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z" />
                            </svg>
                            <!-- Lock -->
                            <svg v-if="feature.icon === 'lock'" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                            <!-- Tabs -->
                            <svg v-if="feature.icon === 'tabs'" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                            </svg>
                            <!-- History -->
                            <svg v-if="feature.icon === 'history'" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <!-- Form -->
                            <svg v-if="feature.icon === 'form'" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            <!-- Addon -->
                            <svg v-if="feature.icon === 'addon'" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 14v6m-3-3h6M6 10h2a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v2a2 2 0 002 2zm10 0h2a2 2 0 002-2V6a2 2 0 00-2-2h-2a2 2 0 00-2 2v2a2 2 0 002 2zM6 20h2a2 2 0 002-2v-2a2 2 0 00-2-2H6a2 2 0 00-2 2v2a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900">{{ feature.title }}</h3>
                        <p class="mt-2 text-gray-600 text-sm leading-relaxed">{{ feature.description }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Security Section -->
        <section class="py-20 sm:py-28 bg-gray-900 text-white">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
                    <div>
                        <h2 class="text-3xl sm:text-4xl font-bold">
                            Privacy by design
                        </h2>
                        <p class="mt-6 text-gray-300 text-lg leading-relaxed">
                            Midori Sync uses a separate encryption passphrase that never leaves your browser.
                            Your data is encrypted locally before it's sent to the server — meaning not even
                            the server operators can read your bookmarks, passwords, or browsing history.
                        </p>
                        <ul class="mt-8 space-y-4">
                            <li class="flex items-start gap-3">
                                <div class="mt-1 w-5 h-5 bg-midori-500 rounded-full flex items-center justify-center flex-shrink-0">
                                    <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                                    </svg>
                                </div>
                                <div>
                                    <span class="font-medium">AES-256-GCM encryption</span>
                                    <p class="text-gray-400 text-sm">Military-grade encryption for all synced data.</p>
                                </div>
                            </li>
                            <li class="flex items-start gap-3">
                                <div class="mt-1 w-5 h-5 bg-midori-500 rounded-full flex items-center justify-center flex-shrink-0">
                                    <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                                    </svg>
                                </div>
                                <div>
                                    <span class="font-medium">Zero-knowledge architecture</span>
                                    <p class="text-gray-400 text-sm">The server never sees your encryption keys or decrypted data.</p>
                                </div>
                            </li>
                            <li class="flex items-start gap-3">
                                <div class="mt-1 w-5 h-5 bg-midori-500 rounded-full flex items-center justify-center flex-shrink-0">
                                    <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                                    </svg>
                                </div>
                                <div>
                                    <span class="font-medium">PBKDF2 + HKDF key derivation</span>
                                    <p class="text-gray-400 text-sm">600,000 rounds to derive encryption keys from your passphrase.</p>
                                </div>
                            </li>
                            <li class="flex items-start gap-3">
                                <div class="mt-1 w-5 h-5 bg-midori-500 rounded-full flex items-center justify-center flex-shrink-0">
                                    <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                                    </svg>
                                </div>
                                <div>
                                    <span class="font-medium">Open source (AGPL-3.0)</span>
                                    <p class="text-gray-400 text-sm">Fully auditable code. Self-host for maximum control.</p>
                                </div>
                            </li>
                        </ul>
                    </div>
                    <div class="bg-gray-800 rounded-2xl p-8 font-mono text-sm">
                        <div class="text-gray-500 mb-2">// End-to-end encryption flow</div>
                        <div class="text-midori-400">Passphrase</div>
                        <div class="text-gray-500 ml-4">↓ PBKDF2-SHA256 (600k rounds)</div>
                        <div class="text-midori-400 ml-4">Master Key <span class="text-gray-500">(256 bits)</span></div>
                        <div class="text-gray-500 ml-8">├─ HKDF → <span class="text-blue-400">Encryption Key</span></div>
                        <div class="text-gray-500 ml-8">└─ HKDF → <span class="text-purple-400">HMAC Key</span></div>
                        <div class="mt-4 text-gray-500">// Each BSO encrypted before upload</div>
                        <div class="text-yellow-400">encrypt<span class="text-gray-500">(</span>data, key<span class="text-gray-500">)</span> → <span class="text-gray-400">opaque blob</span></div>
                        <div class="mt-2 text-gray-500">// Server stores only encrypted blobs</div>
                        <div class="text-red-400">server.canRead(data) === <span class="text-red-500">false</span></div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Self-host Section -->
        <section class="py-20 sm:py-28 bg-white">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
                <h2 class="text-3xl sm:text-4xl font-bold text-gray-900">Easy to deploy</h2>
                <p class="mt-4 text-lg text-gray-600 max-w-2xl mx-auto">
                    Run Midori Sync on your own infrastructure with Docker or install it manually.
                </p>
                <div class="mt-10 bg-gray-900 rounded-2xl p-6 max-w-2xl mx-auto text-left">
                    <div class="flex items-center gap-2 mb-4">
                        <div class="w-3 h-3 rounded-full bg-red-500"></div>
                        <div class="w-3 h-3 rounded-full bg-yellow-500"></div>
                        <div class="w-3 h-3 rounded-full bg-green-500"></div>
                        <span class="ml-2 text-gray-500 text-xs">terminal</span>
                    </div>
                    <pre class="text-sm text-gray-300 overflow-x-auto"><code><span class="text-gray-500">$</span> <span class="text-midori-400">git clone</span> https://github.com/user/midori-sync.git
<span class="text-gray-500">$</span> <span class="text-midori-400">cd</span> midori-sync
<span class="text-gray-500">$</span> <span class="text-midori-400">docker compose up -d</span>
<span class="text-green-400">✓ Midori Sync is running at http://localhost:8000</span></code></pre>
                </div>
            </div>
        </section>

        <!-- Footer -->
        <footer class="bg-gray-50 border-t border-gray-200 py-12">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex flex-col sm:flex-row justify-between items-center gap-4">
                    <div class="flex items-center gap-2">
                        <div class="w-6 h-6 bg-midori-500 rounded-md flex items-center justify-center">
                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                        </div>
                        <span class="text-sm font-medium text-gray-900">Midori Sync</span>
                    </div>
                    <p class="text-sm text-gray-500">
                        Compatible with Firefox Sync 1.5 protocol. Licensed under AGPL-3.0.
                    </p>
                </div>
            </div>
        </footer>
    </div>
</template>
