<?php

namespace App\Http\Middleware;

use App\Services\SyncAuthService;
use App\Support\SecurityLog;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ValidateSyncToken implements AuthenticatesRequests
{
    public function __construct(
        private SyncAuthService $authService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $failureKey = 'sync:failed-auth:'.$request->ip();
        $failureLimit = max(1, (int) config('services.sync.unauth_rate_limit', 30));
        if (RateLimiter::tooManyAttempts($failureKey, $failureLimit)) {
            return response()->json(['error' => 'rate_limited'], 429)
                ->header('Cache-Control', 'no-store')
                ->header('Retry-After', (string) max(1, RateLimiter::availableIn($failureKey)));
        }
        $token = $request->bearerToken();

        if (! $token) {
            RateLimiter::hit($failureKey, 60);
            SecurityLog::warning(SecurityLog::EVENT_TOKEN_INVALID, [
                'reason' => 'missing',
                'path' => $request->path(),
            ], $request);

            return response()->json(['error' => 'Authentication required'], 401);
        }

        $session = $this->authService->validateToken($token);

        if (! $session) {
            RateLimiter::hit($failureKey, 60);
            SecurityLog::warning(SecurityLog::EVENT_TOKEN_INVALID, [
                'reason' => 'invalid_or_expired',
                'path' => $request->path(),
            ], $request);

            return response()->json(['error' => 'Invalid or expired token'], 401);
        }

        $request->merge([
            'sync_user' => $session->user,
            'sync_session' => $session,
        ]);

        $request->setUserResolver(fn () => $session->user);

        return $next($request);
    }
}
