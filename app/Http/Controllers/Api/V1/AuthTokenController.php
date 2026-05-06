<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ExchangeTokenRequest;
use App\Services\SyncAuthService;
use App\Support\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AuthTokenController extends Controller
{
    public function __construct(
        private SyncAuthService $authService,
    ) {}

    public function store(ExchangeTokenRequest $request): JsonResponse
    {
        $bearerToken = $request->bearerToken();
        if (!$bearerToken) {
            return response()->json(['error' => 'Missing OAuth bearer token'], 401);
        }

        // Validate the OAuth token with Authentik userinfo endpoint
        $baseUrl = rtrim(config('services.authentik.base_url'), '/');
        $response = Http::withToken($bearerToken)
            ->get("{$baseUrl}/application/o/userinfo/");

        if (!$response->successful()) {
            return response()->json(['error' => 'Invalid OAuth token'], 401);
        }

        $userInfo = $response->json();
        $authentikId = $userInfo['sub'] ?? null;

        if (!$authentikId) {
            return response()->json(['error' => 'Could not determine user identity'], 401);
        }

        $user = \App\Models\User::updateOrCreate(
            ['authentik_id' => $authentikId],
            [
                'email' => $userInfo['email'] ?? '',
                'name' => $userInfo['name'] ?? $userInfo['preferred_username'] ?? '',
                'avatar_url' => $userInfo['picture'] ?? null,
            ]
        );

        // Resolve device_id if provided
        $deviceId = null;
        if ($request->input('device_id')) {
            $device = $user->devices()->firstOrCreate(
                ['device_id' => $request->input('device_id')],
                [
                    'name' => $request->input('device_name', 'Unknown Device'),
                    'type' => $request->input('device_type', 'desktop'),
                    'os' => $request->input('device_os'),
                    'browser_version' => $request->input('browser_version'),
                ]
            );
            $deviceId = $device->id;
        }

        $tokenData = $this->authService->createSessionToken(
            $user,
            $deviceId,
            $request->ip(),
            $request->userAgent(),
        );

        SecurityLog::info(SecurityLog::EVENT_TOKEN_ISSUED, [
            'user_id' => $user->id,
            'device_pk' => $deviceId,
            'flow' => 'oauth_exchange',
        ], $request);

        return response()->json([
            'token' => $tokenData['token'],
            'expires_at' => $tokenData['expires_at'],
            'expires_in' => $tokenData['expires_in'],
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'storage_quota_bytes' => $user->storage_quota_bytes,
            ],
        ], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $token = $request->bearerToken();
        if (!$token) {
            return response()->json(['error' => 'No token provided'], 400);
        }

        $this->authService->revokeToken($token);

        SecurityLog::info(SecurityLog::EVENT_TOKEN_REVOKED, [
            'user_id' => $request->user()?->id,
        ], $request);

        return response()->json(null, 204);
    }
}
