<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SyncAuthService;
use App\Support\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthTokenController extends Controller
{
    public function __construct(
        private SyncAuthService $authService,
    ) {}

    public function destroy(Request $request): JsonResponse
    {
        $token = $request->bearerToken();
        if (! $token) {
            return response()->json(['error' => 'No token provided'], 400);
        }

        $this->authService->revokeSession($request->user()->id, $request->input('sync_session')->id);

        SecurityLog::info(SecurityLog::EVENT_TOKEN_REVOKED, [
            'user_id' => $request->user()?->id,
        ], $request);

        return response()->json(null, 204)->header('Cache-Control', 'no-store');
    }
}
