<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'authentik' => [
        'base_url' => env('AUTHENTIK_BASE_URL'),
        'client_id' => env('AUTHENTIK_CLIENT_ID'),
        'client_secret' => env('AUTHENTIK_CLIENT_SECRET'),
        'redirect' => env('AUTHENTIK_REDIRECT_URI'),
    ],

    'sync' => [
        'hawk_token_duration' => env('SYNC_HAWK_TOKEN_DURATION', 3600),
        'hawk_clock_skew_seconds' => env('SYNC_HAWK_CLOCK_SKEW_SECONDS', 60),
        'default_quota_bytes' => env('SYNC_DEFAULT_QUOTA_BYTES', 104857600),
        'extension_cors_origins' => env('EXTENSION_CORS_ORIGINS', 'moz-extension://*,chrome-extension://*'),
    ],

    'sentry' => [
        'dsn' => env('SENTRY_DSN'),
        'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE', 0.0),
        'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV', 'production')),
    ],

];
