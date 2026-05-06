<?php

/*
 * Midori Sync — CORS policy for the extension/dashboard surface.
 *
 * `allowed_origins` is an exact-match list. `allowed_origin_patterns`
 * is a list of regular expressions (without delimiters; anchored
 * automatically). Any origin in either list is echoed back verbatim
 * in the `Access-Control-Allow-Origin` header. Origins not in the
 * list receive no CORS headers (browser blocks the cross-origin
 * request).
 *
 * In `local` env, defaults include localhost:8000 and any
 * `moz-extension://...` and `chrome-extension://...` origin so the
 * extension can talk to a dev server without ad-hoc config.
 */

$origins = array_filter(array_map('trim', explode(
    ',',
    (string) env('CORS_ALLOWED_ORIGINS', '')
)));

$patterns = array_filter(array_map('trim', explode(
    ',',
    (string) env('CORS_ALLOWED_ORIGIN_PATTERNS', '')
)));

if (empty($origins) && empty($patterns) && env('APP_ENV') === 'local') {
    $origins = [
        'http://localhost:8000',
        'http://127.0.0.1:8000',
    ];
    $patterns = [
        '^moz-extension://[a-z0-9-]+$',
        '^chrome-extension://[a-z0-9]+$',
    ];
}

return [
    // Laravel's built-in HandleCors middleware reads `paths`. We set it
    // to an empty array so HandleCors becomes a no-op for every route,
    // and our `App\Http\Middleware\CorsForExtension` is the single
    // source of truth for CORS policy on `/api/ext` and `/api/v1`.
    'paths' => [],

    'allowed_origins' => $origins,
    'allowed_origin_patterns' => $patterns,
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_headers' => [
        'Content-Type',
        'Authorization',
        'X-If-Unmodified-Since',
        'X-Device-Id',
        'If-None-Match',
        'If-Modified-Since',
        'Accept',
        'Accept-Encoding',
    ],
    'exposed_headers' => [
        'X-Last-Modified',
        'X-Quota-Remaining',
        'ETag',
    ],
    'max_age' => 86400,
    'allow_credentials' => false,
];
