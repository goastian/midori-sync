<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Authentication controller for the Midori Sync browser extension.
 *
 * Uses OAuth2 Authorization Code flow with Authentik:
 * 1. Extension calls /api/ext/auth/start → gets Authentik login URL + state
 * 2. Extension opens that URL in a browser tab
 * 3. User logs in at Authentik → redirected to /api/ext/auth/callback
 * 4. Server exchanges code for tokens, creates user, stores api_token in cache
 * 5. Extension polls /api/ext/auth/poll with the state to retrieve the api_token
 */
class ExtensionAuthController extends Controller
{
    /**
     * GET /api/ext/auth/start
     *
     * Generate Authentik OAuth2 authorization URL for the extension.
     * Returns the URL the extension should open in a new browser tab
     * and a state token for polling.
     */
    public function authStart(Request $request): JsonResponse
    {
        $state = Str::random(40);
        $deviceName = $request->input('device_name', 'Midori Browser');
        $deviceType = $request->input('device_type', 'desktop');

        $deviceId = $request->input('device_id', '');

        // Store device info so callback can register the device
        cache()->put('ext_auth_state:' . $state, [
            'device_id' => $deviceId,
            'device_name' => $deviceName,
            'device_type' => $deviceType,
            'created_at' => now()->toIso8601String(),
        ], now()->addMinutes(10));

        $params = http_build_query([
            'client_id' => config('services.authentik.client_id'),
            'redirect_uri' => rtrim(config('app.url'), '/') . '/ext/auth/callback',
            'response_type' => 'code',
            'scope' => 'openid profile email',
            'state' => $state,
        ]);

        $authUrl = rtrim(config('services.authentik.base_url'), '/')
            . '/application/o/authorize/?' . $params;

        return response()->json([
            'auth_url' => $authUrl,
            'state' => $state,
        ]);
    }

    /**
     * GET /api/ext/auth/callback
     *
     * Authentik redirects here after the user logs in.
     * Exchanges the authorization code for tokens, creates/updates the user,
     * stores the api_token in cache keyed by state, and shows a success page.
     */
    public function authCallback(Request $request): \Illuminate\Http\Response
    {
        $code = $request->input('code');
        $state = $request->input('state');

        if (!$code || !$state) {
            return response(view('extension.auth-result', [
                'success' => false,
                'message' => 'Missing authorization code or state.',
            ]));
        }

        // Verify state exists
        $stateData = cache()->pull('ext_auth_state:' . $state);
        if (!$stateData) {
            return response(view('extension.auth-result', [
                'success' => false,
                'message' => 'Invalid or expired state. Please try again from the extension.',
            ]));
        }

        // Exchange code for tokens with Authentik
        $tokenResponse = Http::asForm()->post(
            rtrim(config('services.authentik.base_url'), '/') . '/application/o/token/',
            [
                'grant_type' => 'authorization_code',
                'client_id' => config('services.authentik.client_id'),
                'client_secret' => config('services.authentik.client_secret'),
                'redirect_uri' => rtrim(config('app.url'), '/') . '/ext/auth/callback',
                'code' => $code,
            ]
        );

        if ($tokenResponse->failed()) {
            return response(view('extension.auth-result', [
                'success' => false,
                'message' => 'Failed to exchange authorization code. Please try again.',
            ]));
        }

        $tokenData = $tokenResponse->json();
        $accessToken = $tokenData['access_token'] ?? null;

        if (!$accessToken) {
            return response(view('extension.auth-result', [
                'success' => false,
                'message' => 'No access token received from Authentik.',
            ]));
        }

        // Fetch user info
        $userInfoResponse = Http::withToken($accessToken)->get(
            rtrim(config('services.authentik.base_url'), '/') . '/application/o/userinfo/'
        );

        if ($userInfoResponse->failed()) {
            return response(view('extension.auth-result', [
                'success' => false,
                'message' => 'Failed to retrieve user information.',
            ]));
        }

        $userInfo = $userInfoResponse->json();

        // Create or update the local user
        $user = User::updateOrCreate(
            ['authentik_id' => $userInfo['sub']],
            [
                'name' => $userInfo['name'] ?? $userInfo['preferred_username'] ?? 'User',
                'email' => $userInfo['email'] ?? '',
                'avatar' => $userInfo['picture'] ?? null,
                'email_verified_at' => now(),
            ]
        );

        // Generate API token for the extension
        $apiToken = Str::random(80);
        $user->update(['api_token' => hash('sha256', $apiToken)]);

        // Register or update the device (reuse existing device_id to avoid duplicates)
        $deviceId = !empty($stateData['device_id'])
            ? $stateData['device_id']
            : Str::uuid()->toString();

        $device = $user->devices()->updateOrCreate(
            ['device_id' => $deviceId],
            [
                'name' => $stateData['device_name'] ?? 'Midori Browser',
                'type' => $stateData['device_type'] ?? 'desktop',
                'last_sync_at' => now(),
            ]
        );

        // Store the result in cache so the extension can poll for it
        cache()->put('ext_auth_result:' . $state, [
            'token' => $apiToken,
            'user' => [
                'id' => $user->id,
                'uid' => $user->authentik_id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar,
                'storage_quota' => $user->storage_quota_bytes,
            ],
            'device' => [
                'id' => $device->device_id,
                'name' => $device->name,
                'type' => $device->type,
            ],
        ], now()->addMinutes(5));

        return response(view('extension.auth-result', [
            'success' => true,
            'message' => 'Signed in successfully! You can close this tab and return to Midori.',
            'userName' => $user->name,
        ]));
    }

    /**
     * GET /api/ext/auth/poll
     *
     * Extension polls this endpoint with the state token to check if
     * the user has completed login. Returns the api_token when ready.
     */
    public function authPoll(Request $request): JsonResponse
    {
        $state = $request->input('state');

        if (!$state) {
            return response()->json(['status' => 'error', 'message' => 'Missing state'], 400);
        }

        $result = cache()->get('ext_auth_result:' . $state);

        if (!$result) {
            // Check if the state is still pending (user hasn't logged in yet)
            $pending = cache()->get('ext_auth_state:' . $state);
            if ($pending) {
                return response()->json(['status' => 'pending']);
            }

            // State was consumed by callback but result not yet available,
            // or state expired
            return response()->json(['status' => 'pending']);
        }

        // Got the result — remove it from cache (one-time use)
        cache()->forget('ext_auth_result:' . $state);

        return response()->json([
            'status' => 'complete',
            ...$result,
        ]);
    }

    /**
     * POST /api/ext/logout
     *
     * Invalidate the extension API token.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user) {
            $user->update(['api_token' => null]);
        }

        return response()->json(['message' => 'Logged out successfully.']);
    }

    /**
     * GET /api/ext/profile
     *
     * Return the authenticated user's profile.
     */
    public function profile(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (!$user) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $devices = $user->devices()->get()->map(fn ($d) => [
            'id' => $d->device_id,
            'name' => $d->name,
            'type' => $d->type,
            'last_sync_at' => $d->last_sync_at?->toIso8601String(),
        ]);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'uid' => $user->authentik_id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar,
                'storage_quota' => $user->storage_quota,
            ],
            'devices' => $devices,
        ]);
    }

    /**
     * POST /api/ext/pair
     *
     * Generate a pairing token (displayed as QR) for connecting another device.
     */
    public function generatePairingToken(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (!$user) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $pairingToken = Str::random(32);
        $expiresAt = now()->addMinutes(10);

        cache()->put(
            'pairing:' . $pairingToken,
            ['user_id' => $user->id, 'expires_at' => $expiresAt->toIso8601String()],
            $expiresAt
        );

        $pairingUrl = rtrim(config('app.url'), '/') . '/api/ext/pair/redeem?token=' . $pairingToken;

        return response()->json([
            'pairing_token' => $pairingToken,
            'pairing_url' => $pairingUrl,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    /**
     * POST /api/ext/pair/redeem
     *
     * Redeem a pairing token from another device to link it to the same account.
     */
    public function redeemPairingToken(Request $request): JsonResponse
    {
        $request->validate([
            'pairing_token' => 'required|string',
            'device_name' => 'nullable|string|max:255',
            'device_type' => 'nullable|string|in:desktop,mobile,tablet',
        ]);

        $pairingData = cache()->pull('pairing:' . $request->input('pairing_token'));

        if (!$pairingData) {
            return response()->json([
                'error' => 'invalid_token',
                'message' => 'Pairing token is invalid or expired.',
            ], 400);
        }

        $user = User::find($pairingData['user_id']);
        if (!$user) {
            return response()->json(['error' => 'user_not_found'], 404);
        }

        // Generate API token for the new device
        $apiToken = Str::random(80);
        // Note: in production, each device should have its own token table.
        // For MVP, we overwrite the user's api_token (single active session).
        // TODO: Support multiple device tokens.
        $user->update(['api_token' => hash('sha256', $apiToken)]);

        $device = $user->devices()->create([
            'device_id' => Str::uuid()->toString(),
            'name' => $request->input('device_name', 'Paired Device'),
            'type' => $request->input('device_type', 'mobile'),
            'last_sync_at' => now(),
        ]);

        return response()->json([
            'token' => $apiToken,
            'user' => [
                'id' => $user->id,
                'uid' => $user->authentik_id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar,
            ],
            'device' => [
                'id' => $device->device_id,
                'name' => $device->name,
                'type' => $device->type,
            ],
        ]);
    }

    /**
     * POST /api/ext/sync/status
     *
     * Update sync status for a device (last sync timestamp).
     */
    public function updateSyncStatus(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (!$user) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $deviceId = $request->input('device_id');
        if ($deviceId) {
            $user->devices()->where('device_id', $deviceId)->update([
                'last_sync_at' => now(),
            ]);
        }

        return response()->json(['status' => 'ok', 'synced_at' => now()->toIso8601String()]);
    }

    /**
     * Resolve user from the Bearer token in the Authorization header.
     */
    private function resolveUser(Request $request): ?User
    {
        $token = $request->bearerToken();
        if (!$token) {
            return null;
        }

        return User::where('api_token', hash('sha256', $token))->first();
    }
}
