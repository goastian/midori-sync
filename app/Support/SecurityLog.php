<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Thin facade over the `security` log channel. Every event is a single
 * structured line ready to be shipped to a SIEM. Fields are normalized
 * (`event`, `user_id`, `ip`, `ua`, `device_id`, `at`) and PII is kept
 * to a minimum (no tokens, no payload bodies).
 */
final class SecurityLog
{
    public const EVENT_LOGIN_SUCCESS = 'auth.login.success';
    public const EVENT_LOGIN_FAILED = 'auth.login.failed';
    public const EVENT_LOGOUT = 'auth.logout';
    public const EVENT_TOKEN_ISSUED = 'auth.token.issued';
    public const EVENT_TOKEN_REVOKED = 'auth.token.revoked';
    public const EVENT_TOKEN_REVOKED_BULK = 'auth.token.revoked_bulk';
    public const EVENT_TOKEN_INVALID = 'auth.token.invalid';
    public const EVENT_OAUTH_START = 'auth.oauth.start';
    public const EVENT_OAUTH_CALLBACK = 'auth.oauth.callback';
    public const EVENT_PAIRING_GENERATED = 'auth.pairing.generated';
    public const EVENT_PAIRING_REDEEMED = 'auth.pairing.redeemed';
    public const EVENT_PAIRING_REJECTED = 'auth.pairing.rejected';
    public const EVENT_QUOTA_EXCEEDED = 'quota.exceeded';
    public const EVENT_QUOTA_CHANGED = 'quota.changed';
    public const EVENT_DATA_WIPED = 'data.wiped';
    public const EVENT_DEVICE_REVOKED = 'device.revoked';
    public const EVENT_RATE_LIMIT_HIT = 'ratelimit.hit';

    public static function info(string $event, array $context = [], ?Request $request = null): void
    {
        self::log('info', $event, $context, $request);
    }

    public static function warning(string $event, array $context = [], ?Request $request = null): void
    {
        self::log('warning', $event, $context, $request);
    }

    public static function error(string $event, array $context = [], ?Request $request = null): void
    {
        self::log('error', $event, $context, $request);
    }

    private static function log(string $level, string $event, array $context, ?Request $request): void
    {
        $request ??= app('request');

        $base = [
            'event' => $event,
            'at' => now()->toIso8601String(),
        ];

        if ($request instanceof Request) {
            $base['ip'] = $request->ip();
            $ua = (string) $request->userAgent();
            if ($ua !== '') {
                $base['ua'] = mb_substr($ua, 0, 256);
            }
            $deviceId = $request->header('X-Device-Id');
            if ($deviceId) {
                $base['device_id'] = mb_substr((string) $deviceId, 0, 128);
            }
            if ($request->user()) {
                $base['user_id'] = $request->user()->id;
            }
        }

        Log::channel('security')->{$level}($event, array_merge($base, $context));
    }
}
