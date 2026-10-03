<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SyncProtocolException;
use App\Http\Controllers\Controller;
use App\Services\SyncNativeCryptoService;
use App\Services\SyncStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NativeCryptoController extends Controller
{
    public function __construct(private SyncNativeCryptoService $crypto) {}

    public function show(Request $request): JsonResponse
    {
        return $this->response($this->crypto->snapshot($request->user()->id, $request->input('sync_session')->id));
    }

    public function activate(Request $request): JsonResponse
    {
        $context = $this->context($request);
        $data = $this->bundle($request);
        $options = $request->validate([
            'revoke_incompatible' => ['sometimes', 'boolean'],
            'recovery_secret' => ['sometimes', 'string', 'regex:/^[0-9a-f]{64}$/D'],
        ]);

        return $this->response($this->crypto->activate(
            $request->user()->id, $context, $data['key_id'], $data['encrypted_bundle'], $request->boolean('revoke_incompatible'),
            $options['recovery_secret'] ?? null,
        ));
    }

    public function recovery(Request $request): JsonResponse
    {
        return $this->response($this->crypto->recoverySecret($request->user()->id, $request->input('sync_session')->id));
    }

    public function escrow(Request $request): JsonResponse
    {
        $context = $this->context($request);
        $data = $request->validate([
            'recovery_secret' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/D'],
            'keys' => ['required', 'array', 'min:1', 'max:8'],
            'keys.*' => ['required', 'array:key_id,encrypted_bundle'],
            'keys.*.key_id' => ['required', 'string', 'size:22'],
            'keys.*.encrypted_bundle' => ['required', 'string', 'max:2048'],
        ]);

        return $this->response($this->crypto->escrow($request->user()->id, $context, $data['keys'], $data['recovery_secret']));
    }

    public function rotate(Request $request): JsonResponse
    {
        $context = $this->context($request);
        $data = $this->bundle($request);

        return $this->response($this->crypto->rotate($request->user()->id, $context, $data['key_id'], $data['encrypted_bundle']));
    }

    public function rewrap(Request $request, string $keyId): JsonResponse
    {
        $context = $this->context($request);
        $data = $request->validate(['encrypted_bundle' => ['required', 'string', 'max:2048']]);

        return $this->response($this->crypto->rewrap($request->user()->id, $context, $keyId, $data['encrypted_bundle']));
    }

    public function wipe(Request $request, SyncStorageService $storage): JsonResponse
    {
        $context = $this->context($request);
        $storage->deleteAllUserData($request->user()->id, $context);

        return response()->json(null, 204)->header('Cache-Control', 'no-store');
    }

    private function context(Request $request): array
    {
        if (strlen($request->getContent()) > 8192) {
            throw new SyncProtocolException('request_too_large', 413);
        }
        $data = $request->validate([
            'crypto' => ['required', 'array:epoch,revision'],
            'crypto.epoch' => ['required', 'uuid'],
            'crypto.revision' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,9})$/D'],
        ]);

        return $data['crypto'] + ['session_id' => $request->input('sync_session')->id];
    }

    private function bundle(Request $request): array
    {
        return $request->validate([
            'key_id' => ['required', 'string', 'size:22'],
            'encrypted_bundle' => ['required', 'string', 'max:2048'],
        ]);
    }

    private function response(array $value): JsonResponse
    {
        return response()->json($value)->header('Cache-Control', 'no-store');
    }
}
