<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TokenServerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TokenServer controller — compatible with Mozilla's TokenServer API.
 *
 * Accepts Authentik OAuth Bearer tokens and returns Hawk credentials
 * for the Sync Storage API, bridging Authentik SSO to Firefox Sync 1.5 protocol.
 *
 * @see https://mozilla-services.readthedocs.io/en/latest/token/apis.html
 */
class TokenServerController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        private readonly TokenServerService $tokenService,
    ) {}

    /**
     * Handle token exchange: Authentik Bearer token → Hawk credentials.
     *
     * GET /1.0/sync/1.5
     */
    public function getToken(Request $request): JsonResponse
    {
        $authHeader = $request->header('Authorization', '');

        if (!str_starts_with($authHeader, 'Bearer ')) {
            return response()->json([
                'status' => 'error',
                'errors' => [['description' => 'Missing or invalid Authorization header', 'location' => 'header', 'name' => 'Authorization']],
            ], 401)->withHeaders([
                'X-Weave-Timestamp' => round(microtime(true), 2),
            ]);
        }

        $bearerToken = substr($authHeader, 7);

        // Validate the Bearer token against Authentik's userinfo endpoint
        $user = $this->validateAuthentikToken($bearerToken);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'errors' => [['description' => 'Invalid or expired token', 'location' => 'header', 'name' => 'Authorization']],
            ], 401)->withHeaders([
                'X-Weave-Timestamp' => round(microtime(true), 2),
            ]);
        }

        $tokenData = $this->tokenService->generateSyncToken($user);

        return response()->json($tokenData)->withHeaders([
            'X-Weave-Timestamp' => round(microtime(true), 2),
        ]);
    }

    /**
     * Validate an Authentik Bearer token by calling the userinfo endpoint.
     */
    private function validateAuthentikToken(string $token): ?User
    {
        $baseUrl = rtrim(config('services.authentik.base_url'), '/');
        $userinfoUrl = $baseUrl . '/application/o/userinfo/';

        try {
            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->timeout(10)
                ->get($userinfoUrl);

            if (!$response->successful()) {
                return null;
            }

            $userInfo = $response->json();
            $sub = $userInfo['sub'] ?? null;

            if (!$sub) {
                return null;
            }

            // Find or create user from Authentik userinfo
            return User::updateOrCreate(
                ['authentik_id' => $sub],
                [
                    'name' => $userInfo['name'] ?? $userInfo['preferred_username'] ?? 'User',
                    'email' => $userInfo['email'] ?? $sub . '@midori.sync',
                    'email_verified_at' => now(),
                ],
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Authentik token validation failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
