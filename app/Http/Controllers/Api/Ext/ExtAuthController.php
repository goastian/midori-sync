<?php

namespace App\Http\Controllers\Api\Ext;

use App\Http\Controllers\Controller;
use App\Support\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class ExtAuthController extends Controller
{
    /**
     * Start the OAuth2 flow for a browser extension.
     *
     * Generates a state token, stores pending auth data in cache,
     * and returns the Authentik authorization URL.
     */
    public function start(Request $request): JsonResponse
    {
        $state = 'ext_' . Str::random(40);
        $ttl = (int) config('services.sync.oauth_state_ttl', 600);

        Cache::put("ext_auth:{$state}", [
            'device_name' => $request->input('device_name', 'Unknown Device'),
            'device_type' => $request->input('device_type', 'desktop'),
            'device_id' => $request->input('device_id', ''),
            'status' => 'pending',
        ], now()->addSeconds($ttl));

        $authUrl = Socialite::driver('authentik')
            ->stateless()
            ->with(['state' => $state])
            ->redirect()
            ->getTargetUrl();

        SecurityLog::info(SecurityLog::EVENT_OAUTH_START, [
            'flow' => 'extension',
        ], $request);

        return response()->json([
            'auth_url' => $authUrl,
            'state' => $state,
        ]);
    }

    /**
     * Poll for extension auth completion.
     *
     * Returns 'pending' until the OAuth callback has been processed,
     * then returns the full auth result (token, user, device).
     */
    public function poll(Request $request): JsonResponse
    {
        $state = $request->input('state');

        if (!$state) {
            return response()->json(['error' => 'Missing state parameter'], 400);
        }

        $cached = Cache::get("ext_auth:{$state}");

        if (!$cached) {
            return response()->json(['error' => 'Unknown or expired state'], 404);
        }

        if ($cached['status'] === 'pending') {
            return response()->json(['status' => 'pending']);
        }

        // Clean up after successful retrieval
        Cache::forget("ext_auth:{$state}");

        return response()->json($cached);
    }
}
