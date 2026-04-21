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
            $limit = (int) config('services.sync.rate_limit', 60);
            return Limit::perMinute($limit)->by($request->user()?->id ?: $request->ip());
        });
    }
}
