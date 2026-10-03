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

            return $this->refreshLimitResponse(Limit::perMinute($limit)->by("sync:{$bucket}:{$owner}"), $request);
        });

        // Unauthenticated pairing and refresh requests share a per-IP limit.
        RateLimiter::for('sync-unauth', function (Request $request) {
            $limit = (int) (config('services.sync.unauth_rate_limit')
                ?? env('SYNC_UNAUTH_RATE_LIMIT', 30));

            return $this->refreshLimitResponse(Limit::perMinute($limit)->by('sync:u:'.$request->ip()), $request);
        });

        RateLimiter::for('sync-pairing-web', fn (Request $request) => Limit::perMinute(5)
            ->by('sync:web-pair:'.$request->user()->id));

    }

    private function refreshLimitResponse(Limit $limit, Request $request): Limit
    {
        if ($request->is('api/v1/auth/refresh')) {
            $limit->response(fn (Request $request, array $headers) => response()->json(['error' => 'rate_limited'], 429, $headers)
                ->header('Cache-Control', 'no-store'));
        }

        return $limit;
    }
}
