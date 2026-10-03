<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SyncProtocolException;
use App\Http\Controllers\Controller;
use App\Services\SyncPairingService;
use App\Support\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PairingController extends Controller
{
    public function __construct(private SyncPairingService $pairing) {}

    public function generate(Request $request): JsonResponse
    {
        $result = $this->pairing->generate($request->user());
        SecurityLog::info(SecurityLog::EVENT_PAIRING_GENERATED, ['user_id' => $request->user()->id], $request);

        return response()->json($result)->header('Cache-Control', 'no-store');
    }

    public function redeem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pairing_token' => ['required', 'string', 'max:128'],
            'device_name' => ['required', 'string', 'max:255'],
            'device_type' => ['nullable', 'string', 'in:desktop,mobile,tablet'],
            'native_refresh' => ['sometimes', 'boolean'],
        ]);
        try {
            $result = $this->pairing->redeem(
                $data['pairing_token'], $data['device_name'], $data['device_type'] ?? 'desktop',
                $request->ip(), $request->userAgent(),
                (bool) ($data['native_refresh'] ?? false),
            );
        } catch (SyncProtocolException $e) {
            SecurityLog::warning(SecurityLog::EVENT_PAIRING_REJECTED, ['reason' => 'invalid_or_expired'], $request);
            throw $e;
        }
        SecurityLog::info(SecurityLog::EVENT_PAIRING_REDEEMED, [
            'user_id' => $result['user']['id'], 'device_id' => $result['device']['id'],
        ], $request);

        return response()->json($result, 201)->header('Cache-Control', 'no-store');
    }
}
