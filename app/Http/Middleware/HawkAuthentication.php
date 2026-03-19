<?php

namespace App\Http\Middleware;

use App\Services\HawkAuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to authenticate Sync Storage API requests using Hawk credentials.
 *
 * The Hawk protocol is used by Firefox Sync 1.5 to authenticate
 * requests to the storage API after obtaining credentials from the TokenServer.
 */
class HawkAuthentication
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        private readonly HawkAuthService $hawkService,
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->hawkService->authenticate($request);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'errors' => [['description' => 'Unauthorized', 'location' => 'header', 'name' => 'Authorization']],
            ], 401)->withHeaders([
                'WWW-Authenticate' => 'Hawk',
                'X-Weave-Timestamp' => round(microtime(true), 2),
            ]);
        }

        // Verify the user ID in the URL matches the authenticated user
        $uid = $request->route('uid');
        if ($uid !== null && (int) $uid !== $user->id) {
            return response()->json([
                'status' => 'error',
                'errors' => [['description' => 'Forbidden']],
            ], 403);
        }

        // Attach user to the request for downstream use
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
