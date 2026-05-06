<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Authentik\AuthentikExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Event::listen(SocialiteWasCalled::class, AuthentikExtendSocialite::class);

        RateLimiter::for('sync', function (Request $request) {
            $fallback = (int) config('services.sync.rate_limit', 60);
            $isRead = in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);

            $configured = $isRead
                ? config('services.sync.rate_limit_read')
                : config('services.sync.rate_limit_write');
            $limit = (int) ($configured ?? ($isRead ? max($fallback * 2, $fallback) : $fallback));

            $bucket = $isRead ? 'r' : 'w';
            $owner = $request->user()?->id ?: $request->ip();

            return Limit::perMinute($limit)->by("sync:{$bucket}:{$owner}");
        });

        // Per-IP throttle for unauthenticated extension endpoints
        // (auth/start, auth/poll, pair/redeem). Defaults to 30 req/min
        // and is keyed strictly by IP so a single host cannot brute-force
        // pairing tokens or sweep poll states.
        RateLimiter::for('sync-unauth', function (Request $request) {
            $limit = (int) (config('services.sync.unauth_rate_limit')
                ?? env('SYNC_UNAUTH_RATE_LIMIT', 30));

            return Limit::perMinute($limit)->by('sync:u:' . $request->ip());
        });
    }
}
