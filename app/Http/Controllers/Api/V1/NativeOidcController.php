<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SyncProtocolException;
use App\Http\Controllers\Controller;
use App\Services\SyncNativeOidcService;
use App\Support\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NativeOidcController extends Controller
{
    public function store(Request $request, SyncNativeOidcService $oidc): JsonResponse
    {
        if (strlen($request->getContent()) > 32768) {
            throw new SyncProtocolException('oidc_request_too_large', 413);
        }
        $data = $request->validate([
            'id_token' => ['required', 'string', 'max:16384'],
            'access_token' => ['required', 'string', 'max:8192'],
            'nonce' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{32,128}$/D'],
            'device_name' => ['required', 'string', 'min:1', 'max:255'],
        ]);
        $result = $oidc->exchange($data['id_token'], $data['access_token'], $data['nonce'],
            $data['device_name'], $request->ip(), $request->userAgent());
        SecurityLog::info(SecurityLog::EVENT_TOKEN_ISSUED, [
            'user_id' => $result['user']['id'], 'device_id' => $result['device']['id'], 'flow' => 'native_oidc',
        ], $request);

        return response()->json($result, 201)->header('Cache-Control', 'no-store');
    }
}
