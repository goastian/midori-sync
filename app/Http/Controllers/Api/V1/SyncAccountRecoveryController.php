<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SyncProtocolException;
use App\Http\Controllers\Controller;
use App\Services\SyncNativeCryptoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncAccountRecoveryController extends Controller
{
    public function __construct(private SyncNativeCryptoService $crypto) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->crypto->accountRecovery($request->user()->id, $request->input('sync_session')->id))
            ->header('Cache-Control', 'no-store, private');
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
            'capsule' => ['required', 'array'],
        ]);
        $this->crypto->saveAccountRecovery($request->user()->id,
            $data['crypto'] + ['session_id' => $request->input('sync_session')->id], $data['capsule']);

        return response()->json(null, 204)->header('Cache-Control', 'no-store, private');
    }
}
