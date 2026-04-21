<?php

namespace App\Http\Controllers\Api\Ext;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Ext\RedeemPairingRequest;
use App\Models\User;
use App\Services\SyncAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ExtPairingController extends Controller
{
    public function __construct(
        private SyncAuthService $authService,
    ) {}

    /**
     * POST /api/ext/pair
     *
     * Generate a short-lived pairing token for device-to-device auth.
     */
    public function generate(Request $request): JsonResponse
    {
        $pairingToken = Str::random(32);

        Cache::put("pairing:{$pairingToken}", [
            'user_id' => $request->user()->id,
        ], now()->addMinutes(5));

        return response()->json([
            'pairing_token' => $pairingToken,
            'expires_in' => 300,
        ]);
    }

    /**
     * POST /api/ext/pair/redeem
     *
     * Redeem a pairing token to obtain an auth token for the same user.
     */
    public function redeem(RedeemPairingRequest $request): JsonResponse
    {
        $cached = Cache::pull("pairing:{$request->input('pairing_token')}");

        if (!$cached) {
            return response()->json(['error' => 'Invalid or expired pairing token'], 404);
        }

        $user = User::findOrFail($cached['user_id']);

        $deviceId = Str::slug($request->input('device_name'));
        $device = $user->devices()->updateOrCreate(
            ['device_id' => $deviceId],
            [
                'name' => $request->input('device_name'),
                'type' => $request->input('device_type', 'desktop'),
            ]
        );

        $tokenData = $this->authService->createSessionToken($user, $device->id);

        return response()->json([
            'token' => $tokenData['token'],
            'expires_at' => $tokenData['expires_at'],
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'avatar_url' => $user->avatar_url,
            ],
            'device' => [
                'id' => $device->id,
                'name' => $device->name,
                'type' => $device->type,
            ],
        ], 201);
    }
}
