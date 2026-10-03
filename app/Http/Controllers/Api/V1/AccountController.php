<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SyncProtocolException;
use App\Http\Controllers\Controller;
use App\Services\SyncIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function show(Request $request, SyncIdentityService $identity): JsonResponse
    {
        $user = $request->user();
        $session = $request->input('sync_session');
        $device = $session->device;
        if (! $device || $device->user_id !== $user->id) {
            throw new SyncProtocolException('device_required', 409);
        }

        return response()->json([
            'account_version' => 1,
            'identity' => $identity->forUser($user),
            'user' => ['id' => (string) $user->id, 'name' => $user->name, 'email' => $user->email],
            'device' => ['id' => $device->device_id, 'name' => $device->name, 'type' => $device->type],
            'expires_at' => $session->expires_at->toIso8601String(),
        ])->header('Cache-Control', 'no-store');
    }
}
