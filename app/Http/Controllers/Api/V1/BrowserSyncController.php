<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SyncProtocolException;
use App\Http\Controllers\Controller;
use App\Services\SyncBrowserAuthorizationService;
use App\Support\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrowserSyncController extends Controller
{
    public function store(Request $request, SyncBrowserAuthorizationService $authorization): JsonResponse
    {
        if (strlen($request->getContent()) > 4096) {
            throw new SyncProtocolException('browser_authorization_too_large', 413);
        }
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/D'],
            'code_verifier' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43,128}$/D'],
            'device_name' => ['required', 'string', 'min:1', 'max:255'],
        ]);
        $result = $authorization->redeem($data['code'], $data['code_verifier'], $data['device_name'],
            $request->ip(), $request->userAgent());
        SecurityLog::info(SecurityLog::EVENT_TOKEN_ISSUED, [
            'user_id' => $result['user']['id'], 'device_id' => $result['device']['id'], 'flow' => 'browser_sync',
        ], $request);

        return response()->json($result, 201)->header('Cache-Control', 'no-store');
    }
}
