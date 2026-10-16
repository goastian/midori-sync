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
            'recovery_secret' => ['missing'],
            'custody' => ['sometimes', 'in:client'],
            'registry_auth' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/D'],
        ]);
        $identityKey = $this->identityKey($request);

        return $this->response($this->crypto->activate(
            $request->user()->id, $context, $data['key_id'], $data['encrypted_bundle'], $request->boolean('revoke_incompatible'),
            $identityKey, $options['registry_auth'],
        ));
    }

    public function recovery(Request $request): JsonResponse
    {
        return $this->response($this->crypto->recoverySecret($request->user()->id, $request->input('sync_session')->id));
    }

    public function escrow(Request $request): JsonResponse
    {
        throw new SyncProtocolException('server_recovery_disabled', 409);
    }

    public function startRotation(Request $request): JsonResponse
    {
        $context = $this->context($request);
        $data = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'recovery_secret' => ['missing'],
            'registry_auth' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/D'],
            'keys' => ['required', 'array', 'list', 'min:2', 'max:8'],
            'keys.*' => ['required', 'array:key_id,encrypted_bundle'],
            'keys.*.key_id' => ['required', 'string', 'size:22', 'distinct:strict'],
            'keys.*.encrypted_bundle' => ['required', 'string', 'max:2048'],
        ]);
        $bundle = $this->bundle($request);

        return $this->response($this->crypto->startRotation($request->user()->id, $context, $data['operation_id'],
            $bundle['key_id'], $bundle['encrypted_bundle'], $data['keys'], $this->identityKey($request), $data['registry_auth']));
    }

    public function completeRotation(Request $request): JsonResponse
    {
        $context = $this->context($request);
        $data = $request->validate(['operation_id' => ['required', 'uuid'], 'recovery_secret' => ['missing'],
            'registry_auth' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/D']]);

        return $this->response($this->crypto->completeRotation($request->user()->id, $context, $data['operation_id'], $data['registry_auth']));
    }

    public function rotationRecords(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cursor' => ['nullable', 'string', 'max:4096'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->response($this->crypto->rotationRecords($request->user()->id, $request->input('sync_session')->id,
            $data['cursor'] ?? null, (int) ($data['limit'] ?? 100)));
    }

    public function migrateHistoryId(Request $request, SyncStorageService $storage): JsonResponse
    {
        if (strlen($request->getContent()) > 600000) {
            throw new SyncProtocolException('request_too_large', 413);
        }
        $data = $request->validate([
            'crypto' => ['required', 'array:epoch,revision'],
            'crypto.epoch' => ['required', 'uuid'],
            'crypto.revision' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,9})$/D'],
            'operation_id' => ['required', 'uuid'], 'rotation_id' => ['required', 'uuid'],
            'generation' => ['required', 'uuid'],
            'source_id' => ['required', 'string', 'regex:/^h1:[0-9a-f]{64}$/D'],
            'source_revision' => ['required', 'string', 'regex:/^[1-9][0-9]{0,9}$/D'],
            'target_id' => ['required', 'string', 'regex:/^h2:[0-9a-f]{64}$/D'],
            'target_ttl' => ['nullable', 'string', 'max:64', 'date'],
            'source_payload' => ['required', 'string'], 'target_payload' => ['required', 'string'],
        ]);

        return $this->response($storage->migrateHistoryId($request->user()->id,
            $request->input('sync_session')->device_id, $data + ['session_id' => $request->input('sync_session')->id]));
    }

    public function retire(Request $request, string $keyId): JsonResponse
    {
        $context = $this->context($request);
        $data = $request->validate(['registry_auth' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/D']]);

        return $this->response($this->crypto->retireKey($request->user()->id, $context, $keyId, $data['registry_auth']));
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
        if (strlen($request->getContent()) > 24576) {
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

    private function identityKey(Request $request): array
    {
        return $request->validate([
            'identity_key' => ['required', 'array:key_id,encrypted_bundle'],
            'identity_key.key_id' => ['required', 'string', 'size:22'],
            'identity_key.encrypted_bundle' => ['required', 'string', 'max:2048'],
        ])['identity_key'];
    }

    private function response(array $value): JsonResponse
    {
        return response()->json($value)->header('Cache-Control', 'no-store');
    }
}
