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
    }
}
