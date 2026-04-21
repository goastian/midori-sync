<?php

namespace App\Http\Middleware;

use App\Services\SyncAuthService;
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
            return response()->json(['error' => 'Authentication required'], 401);
        }

        $session = $this->authService->validateToken($token);

        if (!$session) {
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
