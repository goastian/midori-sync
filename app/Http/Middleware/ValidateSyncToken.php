<?php

namespace App\Http\Middleware;

use App\Services\SyncAuthService;
use App\Support\SecurityLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateSyncToken
{
    public function __construct(
        private SyncAuthService $authService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            SecurityLog::warning(SecurityLog::EVENT_TOKEN_INVALID, [
                'reason' => 'missing',
                'path' => $request->path(),
            ], $request);
            return response()->json(['error' => 'Authentication required'], 401);
        }

        $session = $this->authService->validateToken($token);

        if (!$session) {
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
