<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SyncProtocolException;
use App\Http\Controllers\Controller;
use App\Services\SyncBrowserAuthorizationService;
use App\Services\SyncChangeJournal;
use App\Services\SyncIdentityService;
use App\Services\SyncNativeEnvelope;
use App\Services\SyncNativeOidcService;
use App\Services\SyncRefreshService;
use App\Services\SyncStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncChangesController extends Controller
{
    public function __construct(private SyncStorageService $storage) {}

    public function capabilities(SyncIdentityService $identity, SyncNativeOidcService $oidc,
        SyncBrowserAuthorizationService $browser): JsonResponse
    {
        $nativeOidc = $oidc->configuration();

        return response()->json([
            'protocol' => 'MSP',
            'native_ready' => false,
            'account_version' => 1,
            'authentication' => [
                'pairing' => $identity->configuredIssuer() !== null,
                'development' => $identity->isDevelopment(),
                'issuer' => $identity->configuredIssuer(),
                'oidc' => $nativeOidc === null ? null : ['version' => 1] + $nativeOidc,
                'browser_login' => $browser->available() ? ['version' => 1] : null,
                'refresh' => [
                    'version' => 1,
                    'request_bytes' => SyncRefreshService::MAX_REQUEST_BYTES,
                    'lifetime_seconds' => SyncRefreshService::REFRESH_LIFETIME,
                    'receipts_per_session' => SyncRefreshService::MAX_RECEIPTS,
                    'sessions_per_account' => SyncRefreshService::MAX_SESSIONS,
                ],
            ],
            'changes_version' => 1,
            'operations_version' => 1,
            'crypto_state_version' => 1,
            'crypto_custody_version' => 1,
            'crypto' => ['enrollment' => true, 'reset' => true, 'password_unlock_version' => 1],
            'record_schema_versions' => [1, 2],
            'client_recovery' => ['version' => 1, 'rotation_version' => 1, 'registry_auth_version' => 2, 'authenticated_controls' => true,
                'compaction_version' => 1, 'history_reconciliation_version' => 1],
            'crypto_versions' => [2],
            'collections' => SyncNativeEnvelope::COLLECTIONS,
            'features' => ['opaque_cursors', 'snapshot_fence', 'tombstones', 'device_acknowledgements', 'conditional_operations', 'idempotent_operations', 'credit_cards', 'form_history', 'addresses', 'journal_compaction', 'authenticated_controls',
                'bookmark_labels', 'history_parts', 'history_visit_removals', 'tab_fragments', 'change_page_byte_budget', 'workspace_metadata'],
            'data_schemas' => SyncNativeEnvelope::DATA_SCHEMAS,
            'limits' => [
                'change_page_records' => SyncChangeJournal::MAX_PAGE_SIZE,
                'change_page_payload_bytes' => SyncChangeJournal::MAX_PAGE_BYTES,
                'record_payload_bytes' => (int) config('services.sync.max_record_size', 262144),
                'operation_batch_records' => 100,
                'operation_batch_bytes' => SyncChangeJournal::MAX_PAGE_BYTES,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function index(Request $request, string $name): JsonResponse
    {
        $data = $request->validate([
            'cursor' => ['nullable', 'string', 'max:4096'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.SyncChangeJournal::MAX_PAGE_SIZE],
            'max_bytes' => ['sometimes', 'integer', 'min:32768', 'max:'.SyncChangeJournal::MAX_PAGE_BYTES],
        ]);

        return response()->json($this->storage->getChanges(
            $request->user()->id,
            $name,
            $this->deviceId($request),
            $data['cursor'] ?? null,
            (int) ($data['limit'] ?? SyncChangeJournal::MAX_PAGE_SIZE),
            $request->input('sync_session')->id,
            (int) ($data['max_bytes'] ?? SyncChangeJournal::MAX_PAGE_BYTES),
        ))->header('Cache-Control', 'no-store');
    }

    public function acknowledge(Request $request, string $name): JsonResponse
    {
        $data = $request->validate(['cursor' => ['required', 'string', 'max:4096']]);

        return response()->json($this->storage->acknowledgeChanges(
            $request->user()->id,
            $name,
            $this->deviceId($request),
            $data['cursor'],
            $request->input('sync_session')->id,
        ))->header('Cache-Control', 'no-store');
    }

    public function operations(Request $request, string $name): JsonResponse
    {
        if (strlen($request->getContent()) > SyncChangeJournal::MAX_PAGE_BYTES) {
            throw new SyncProtocolException('batch_too_large', 413);
        }
        $data = $request->validate([
            'generation' => ['required', 'uuid'],
            'crypto' => ['sometimes', 'required', 'array:epoch,revision'],
            'crypto.epoch' => ['required_with:crypto', 'uuid'],
            'crypto.revision' => ['required_with:crypto', 'string', 'regex:/^(0|[1-9][0-9]{0,9})$/D'],
            'operations' => ['required', 'array', 'list', 'min:1', 'max:100'],
        ]);

        return response()->json($this->storage->applyOperations(
            $request->user()->id,
            $name,
            $this->deviceId($request),
            $data['generation'],
            $data['operations'],
            isset($data['crypto']) ? $data['crypto'] + ['session_id' => $request->input('sync_session')->id] : null,
        ))->header('Cache-Control', 'no-store');
    }

    public function clearHistory(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > 24576) {
            throw new SyncProtocolException('request_too_large', 413);
        }
        $data = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'generation' => ['required', 'uuid'],
            'crypto' => ['required', 'array:epoch,revision'],
            'crypto.epoch' => ['required', 'uuid'],
            'crypto.revision' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,9})$/D'],
            'control' => ['sometimes', 'required', 'array'],
        ]);

        return response()->json($this->storage->clearHistory(
            $request->user()->id,
            $this->deviceId($request),
            $data['operation_id'],
            $data['generation'],
            $data['crypto'] + ['session_id' => $request->input('sync_session')->id],
            $data['control'] ?? null,
        ))->header('Cache-Control', 'no-store');
    }

    public function compact(Request $request, string $name): JsonResponse
    {
        if (strlen($request->getContent()) > 24576) {
            throw new SyncProtocolException('request_too_large', 413);
        }
        $data = $request->validate([
            'crypto' => ['required', 'array:epoch,revision'], 'crypto.epoch' => ['required', 'uuid'],
            'crypto.revision' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,9})$/D'],
            'operation_id' => ['required', 'uuid'], 'generation' => ['required', 'uuid'],
            'snapshot_sequence' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,18})$/D'],
        ]);

        return response()->json($this->storage->compactCollection($request->user()->id, $this->deviceId($request),
            $name, $data['operation_id'], $data['generation'], $data['snapshot_sequence'],
            $data['crypto'] + ['session_id' => $request->input('sync_session')->id]))->header('Cache-Control', 'no-store');
    }

    private function deviceId(Request $request): int
    {
        $deviceId = $request->input('sync_session')->device_id;
        if (! $deviceId) {
            throw new SyncProtocolException('device_required', 409);
        }

        return (int) $deviceId;
    }
}
