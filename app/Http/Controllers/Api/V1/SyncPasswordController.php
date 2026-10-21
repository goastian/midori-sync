<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SyncProtocolException;
use App\Http\Controllers\Controller;
use App\Services\SyncNativeCryptoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncPasswordController extends Controller
{
    public function __construct(private SyncNativeCryptoService $crypto) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->crypto->passwordBackup($request->user()->id, $request->input('sync_session')->id))
            ->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > 65536) {
            throw new SyncProtocolException('request_too_large', 413);
        }
        $data = $request->validate([
            'crypto' => ['required', 'array:epoch,revision'],
            'crypto.epoch' => ['required', 'uuid'],
            'crypto.revision' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,9})$/D'],
            'capsule' => ['required', 'array:version,key,payload'],
            'capsule.version' => ['required', 'integer', 'in:1'],
            'capsule.key' => ['required', 'array:key_id,encrypted_bundle'],
            'capsule.key.key_id' => ['required', 'string', 'size:22'],
            'capsule.key.encrypted_bundle' => ['required', 'string', 'max:2048'],
            'capsule.payload' => ['required', 'string', 'max:49152'],
            'password' => ['missing'], 'recovery_secret' => ['missing'],
        ]);
        $this->crypto->savePasswordBackup($request->user()->id,
            $data['crypto'] + ['session_id' => $request->input('sync_session')->id], $data['capsule']);

        return response()->json(null, 204)->header('Cache-Control', 'no-store');
    }
}
