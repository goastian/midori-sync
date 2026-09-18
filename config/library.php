<?php

return [
    /*
    | predominates: library es el único dominio con paywall.
    | sync nunca consulta estos valores.
    */
    'billing_enabled' => env('LIBRARY_BILLING_ENABLED', true),

    'payments_base_url' => env('PAYMENTS_BASE_URL', 'https://payments.astian.org'),
    'payments_api_token' => env('PAYMENTS_API_TOKEN', ''),
    'payments_webhook_secret' => env('PAYMENTS_WEBHOOK_SECRET', ''),
    'upgrade_url' => env('PAYMENTS_UPGRADE_URL', env('PAYMENTS_BASE_URL', 'https://payments.astian.org').'/checkout?plan=pro'),
    'entitlement_ttl' => (int) env('LIBRARY_ENTITLEMENT_TTL', 300),

    // Defaults cuando billing está desactivado (self-host) o payments cae sin cache.
    'selfhost_limits' => [
        'max_links' => -1, // -1 = ilimitado
        'max_snapshots' => -1,
        'snapshot_kinds' => ['html', 'screenshot', 'pdf'],
        'ai_tags' => true,
        'rss_feeds' => 100,
        'api' => true,
        'collaborators' => 10,
    ],

    'free_limits' => [
        'max_links' => 100,
        'max_snapshots' => 50,
        'snapshot_kinds' => ['html'],
        'ai_tags' => false,
        'rss_feeds' => 3,
        'api' => true,
        'collaborators' => 0,
    ],

    'fetch_enabled' => env('LIBRARY_FETCH_ENABLED', true),
    'snapshots_enabled' => env('LIBRARY_SNAPSHOTS_ENABLED', true),
    'snapshot_disk' => env('LIBRARY_SNAPSHOT_DISK', 'local'),
];
