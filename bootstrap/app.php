<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\NegotiateCompression;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => app()->environment(['local', 'testing'])
            && config('services.sync.local_dev') === true && getenv('MIDORI_SYNC_DEV_ENV_DIR')
                ? '/' : '/auth/redirect');

        $middleware->web(append: [
            HandleInertiaRequests::class,
            SecurityHeaders::class,
        ]);

        $middleware->api(prepend: [
            NegotiateCompression::class,
        ]);

        $middleware->throttleApi(
            limiter: 'sync',
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

if ($environmentPath = getenv('MIDORI_SYNC_DEV_ENV_DIR')) {
    if (! is_dir($environmentPath) || ! is_file($environmentPath.'/.env')) {
        throw new RuntimeException('The isolated Sync environment is missing.');
    }
    $app->useEnvironmentPath($environmentPath);
}

return $app;
