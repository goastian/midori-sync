<?php

namespace App\Services;

use App\Exceptions\SyncProtocolException;
use App\Exceptions\SyncQuotaException;
use App\Models\Collection;
use App\Models\CryptoKeyBundle;
use App\Models\Record;
use App\Models\User;
use App\Models\UserCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SyncStorageService
{
    public function __construct(private SyncChangeJournal $journal, private SyncQuota $quota, private SyncNativeCryptoService $crypto, private SyncNativeEnvelope $envelope) {}

    /**
     * Canonical collection names used by the Sync API.
     * If production boots without seeded rows, we can self-heal known
     * collections on first access instead of returning a 500.
     *
     * @var array<string, string>
     */
    private const CANONICAL_COLLECTIONS = [
        'bookmarks' => 'Browser bookmarks and folders',
        'history' => 'Browsing history',
        'tabs' => 'Currently open tabs',
        'browser-settings' => 'Browser preferences and settings',
        'midori-tab' => 'Midori Tab widgets, themes, and shortcuts',
        'midori-privacy' => 'Midori Privacy filter lists and site toggles',
        'devices' => 'Connected device metadata',
        'passwords' => 'Encrypted password entries',
        'credit-cards' => 'Encrypted payment card entries',
        'form-history' => 'Encrypted form field history',
        'addresses' => 'Encrypted autofill address entries',
        'workspaces' => 'Encrypted workspace names and icons',
    ];

    public function getRecords(
        int $userId,
        string $collectionName,
        ?float $since = null,
        ?int $limit = null,
        ?string $sort = 'newest',
        bool $includeDeleted = false,
    ): array {
        $collection = $this->resolveCollection($collectionName);
        if (! $collection) {
            return [];
        }

        $query = Record::forUser($userId)->inCollection($collection->id);

        if ($since !== null) {
            $query->modifiedSince($since);
        }

        if (! $includeDeleted) {
            $query->active();
        }

        $direction = $sort === 'oldest' ? 'asc' : 'desc';
        $query->orderBy('modified_at', $direction);

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()->map(fn (Record $r) => $this->formatRecord($r))->all();
    }

    public function getRecord(int $userId, string $collectionName, string $recordId): ?array
    {
        $collection = $this->resolveCollection($collectionName);
        if (! $collection) {
            return null;
        }

        $record = Record::forUser($userId)
            ->inCollection($collection->id)
            ->where('record_id', $recordId)
            ->first();

        return $record ? $this->formatRecord($record) : null;
    }

    public function getChanges(int $userId, string $collectionName, int $deviceId, ?string $cursor, int $limit, string $sessionId, int $maxBytes = SyncChangeJournal::MAX_PAGE_BYTES): array
    {
        $collection = $this->resolveCollection($collectionName);
        if (! $collection) {
            throw new SyncProtocolException('unknown_collection', 404);
        }

        return $this->journal->changes($userId, $collection->id, $deviceId, $cursor, $limit, $sessionId, $maxBytes);
    }

    public function acknowledgeChanges(int $userId, string $collectionName, int $deviceId, string $cursor, string $sessionId): array
    {
        $collection = $this->resolveCollection($collectionName);
        if (! $collection) {
            throw new SyncProtocolException('unknown_collection', 404);
        }

        return $this->journal->acknowledge($userId, $collection->id, $deviceId, $cursor, $sessionId);
    }

    public function clearHistory(int $userId, int $deviceId, string $operationId, string $generation, array $cryptoContext, ?array $control = null): array
    {
        if ($control !== null) {
            if (($control['previous_generation'] ?? null) !== $generation) {
                throw new SyncProtocolException('invalid_control', 422);
            }

            return $this->applyAuthenticatedControls($userId, $deviceId, $operationId, $cryptoContext, [$control], false);
        }
        $collection = $this->resolveCollection('history');
        if (! $collection || ! Str::isUuid($operationId) || ! Str::isUuid($generation)) {
            throw new SyncProtocolException('invalid_history_clear');
        }
        $operationId = strtolower($operationId);
        $generation = strtolower($generation);

        return DB::transaction(function () use ($userId, $deviceId, $operationId, $generation, $cryptoContext, $collection) {
            $this->journal->lockUser($userId);
            $state = $this->crypto->authorizeWrite($userId, $cryptoContext, $deviceId);
            if ($state && $state->custody !== 'server') {
                throw new SyncProtocolException('authenticated_control_required', 409);
            }
            $this->journal->requireDevice($userId, $deviceId);
            $stream = $this->journal->prepare($userId, $collection->id);
            $receipts = DB::table('sync_history_clears')->where('stream_id', $stream->id);
            $previous = (clone $receipts)->where('operation_id', $operationId)->first();
            if ($previous) {
                if ($previous->previous_generation !== $generation) {
                    throw new SyncProtocolException('idempotency_conflict', 409);
                }

                return $this->historyClearReceipt($previous);
            }
            if ($stream->generation !== $generation) {
                throw new SyncProtocolException('reset_required', 409);
            }

            $clearBeforeMs = Carbon::now('UTC')->getTimestampMs() + 1;
            $deletedRecords = Record::forUser($userId)->inCollection($collection->id)->delete();
            $nextGeneration = $this->journal->resetHistory($stream, $clearBeforeMs);
            $this->updateCollectionStats($userId, $collection->id);
            if ((clone $receipts)->count() >= 1000) {
                $expired = (clone $receipts)->orderBy('id')->limit(100)->pluck('id');
                DB::table('sync_history_clears')->whereIn('id', $expired)->delete();
            }
            $receipt = [
                'stream_id' => $stream->id,
                'operation_id' => $operationId,
                'previous_generation' => $generation,
                'generation' => $nextGeneration,
                'clear_before_ms' => $clearBeforeMs,
                'deleted_records' => $deletedRecords,
                'created_at' => now(),
            ];
            DB::table('sync_history_clears')->insert($receipt);

            return $this->historyClearReceipt((object) $receipt);
        }, 5);
    }

    public function applyAuthenticatedControls(int $userId, int $deviceId, string $operationId, array $context, array $controls, bool $wipe): array
    {
        $operationId = strtolower($operationId);
        $fingerprint = hash('sha256', json_encode([$deviceId, $context['epoch'], $wipe, $controls], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($userId, $deviceId, $operationId, $context, $controls, $wipe, $fingerprint) {
            $this->journal->lockUser($userId);
            $this->journal->requireSession($userId, $context['session_id'], $deviceId);
            $state = $this->crypto->snapshot($userId, $context['session_id']);
            if ($state['epoch'] !== $context['epoch']) {
                throw new SyncProtocolException('crypto_state_conflict', 409);
            }
            $receipts = DB::table('sync_controls')->where('user_id', $userId);
            $previous = (clone $receipts)->where('operation_id', $operationId)->first();
            if ($previous) {
                if (! hash_equals($previous->fingerprint, $fingerprint)) {
                    throw new SyncProtocolException('idempotency_conflict', 409);
                }

                return json_decode($previous->response, true, flags: JSON_THROW_ON_ERROR);
            }
            $authorized = $this->crypto->authorizeWrite($userId, $context, $deviceId);
            if (! $authorized || $authorized->custody === 'server' || $authorized->identity_key_id === null) {
                throw new SyncProtocolException('client_keys_required', 409);
            }
            $names = array_column($controls, 'collection');
            if (count($names) !== count($controls) || count(array_filter($names, 'is_string')) !== count($names)) {
                throw new SyncProtocolException('invalid_control', 422);
            }
            sort($names, SORT_STRING);
            $expected = $wipe ? SyncNativeEnvelope::COLLECTIONS : ['history'];
            sort($expected, SORT_STRING);
            if ($names !== $expected) {
                throw new SyncProtocolException('incomplete_controls', 422);
            }
            $deleted = 0;
            foreach ($controls as $control) {
                $collection = $this->resolveCollection($control['collection']);
                $stream = $this->journal->prepare($userId, $collection->id);
                $this->validateControl($control, $state, $stream, $operationId, $wipe ? 'collection_clear' : 'history_clear');
                $deleted += Record::forUser($userId)->inCollection($collection->id)->delete();
                $this->journal->installControl($stream, $control);
                $this->updateCollectionStats($userId, $collection->id);
            }
            $response = $wipe ? ['operation_id' => $operationId, 'controls' => $controls, 'deleted_records' => $deleted] : [
                'operation_id' => $operationId, 'previous_generation' => $controls[0]['previous_generation'],
                'generation' => $controls[0]['generation'],
                'clear_before' => Carbon::createFromTimestampMs($controls[0]['clear_before_ms'], 'UTC')->toISOString(),
                'deleted_records' => $deleted, 'control' => $controls[0],
            ];
            $receipts->insert(['user_id' => $userId, 'operation_id' => $operationId, 'fingerprint' => $fingerprint,
                'response' => json_encode($response, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $response;
        }, 5);
    }

    private function validateControl(array $control, array $state, object $stream, string $operationId, string $kind): void
    {
        $fields = ['version', 'epoch', 'collection', 'operation_id', 'previous_generation', 'generation', 'control_revision', 'kind', 'clear_before_ms', 'proof'];
        if (count($control) !== count($fields) || array_diff($fields, array_keys($control)) !== [] ||
            $control['version'] !== 1 || $control['epoch'] !== $state['epoch'] ||
            $control['operation_id'] !== $operationId || $control['kind'] !== $kind ||
            ! is_string($control['previous_generation']) || ! Str::isUuid($control['previous_generation']) ||
            ! is_string($control['generation']) || ! Str::isUuid($control['generation']) ||
            $control['generation'] === $control['previous_generation'] ||
            ! is_string($control['control_revision']) || ! preg_match('/^[1-9][0-9]{0,15}$/D', $control['control_revision']) ||
            ! is_int($control['clear_before_ms']) || $control['clear_before_ms'] < 0 || $control['clear_before_ms'] > 9007199254740 ||
            ($kind === 'history_clear' ? $control['clear_before_ms'] === 0 : $control['clear_before_ms'] !== 0) ||
            ! is_string($control['proof']) || ! preg_match('/^[0-9a-f]{64}$/D', $control['proof'])) {
            throw new SyncProtocolException('invalid_control', 422);
        }
        $this->journal->requireGeneration($stream, $control['previous_generation']);
        if ($stream->control_revision >= 9007199254740991 || $control['control_revision'] !== (string) ($stream->control_revision + 1)) {
            throw new SyncProtocolException('control_state_conflict', 409, $this->journal->controlState($stream));
        }
    }

    public function compactCollection(int $userId, int $deviceId, string $name, string $operationId, string $generation, string $sequence, array $context): array
    {
        $collection = $this->resolveCollection($name);
        if (! $collection || ! $this->envelope->supportsCollection($name)) {
            throw new SyncProtocolException('unknown_collection', 404);
        }
        $operationId = strtolower($operationId);
        $fingerprint = hash('sha256', json_encode([$deviceId, $context['epoch'], $generation, $sequence], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($userId, $deviceId, $collection, $operationId, $generation, $sequence, $context, $fingerprint) {
            $this->journal->lockUser($userId);
            $this->journal->requireSession($userId, $context['session_id'], $deviceId);
            $state = $this->crypto->snapshot($userId, $context['session_id']);
            if ($state['epoch'] !== $context['epoch']) {
                throw new SyncProtocolException('crypto_state_conflict', 409);
            }
            $stream = $this->journal->prepare($userId, $collection->id);
            $this->journal->requireGeneration($stream, $generation);
            $receipts = DB::table('sync_compactions')->where('stream_id', $stream->id);
            $previous = (clone $receipts)->where('operation_id', $operationId)->first();
            if ($previous) {
                if (! hash_equals($previous->fingerprint, $fingerprint)) {
                    throw new SyncProtocolException('idempotency_conflict', 409);
                }

                return json_decode($previous->response, true, flags: JSON_THROW_ON_ERROR);
            }
            $authorized = $this->crypto->authorizeWrite($userId, $context, $deviceId);
            if (! $authorized || $authorized->custody !== 'client') {
                throw new SyncProtocolException('rotation_in_progress', 409);
            }
            if ((string) $stream->sequence !== $sequence) {
                throw new SyncProtocolException('snapshot_conflict', 409);
            }
            foreach (Record::forUser($userId)->inCollection($collection->id)->cursor() as $record) {
                if (! $this->envelope->validRecord(['id' => $record->record_id, 'base_revision' => (string) ($record->version - 1),
                    'deleted' => $record->deleted, 'payload' => $record->payload], $collection->name, $generation, $authorized->active_key_id, 2)) {
                    throw new SyncProtocolException('rotation_records_pending', 409);
                }
            }
            $response = ['operation_id' => $operationId] + $this->journal->compact($stream);
            $receipts->insert(['stream_id' => $stream->id, 'operation_id' => $operationId, 'fingerprint' => $fingerprint,
                'response' => json_encode($response, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $response;
        }, 5);
    }

    public function historyMigrationTarget(int $userId, int $deviceId, string $sessionId, string $id, string $generation): array
    {
        return DB::transaction(function () use ($userId, $deviceId, $sessionId, $id, $generation) {
            $this->journal->lockUser($userId);
            $this->journal->requireSession($userId, $sessionId, $deviceId);
            $rotation = DB::table('sync_key_rotations')->where('user_id', $userId)->first();
            if ($rotation?->phase !== 'rewriting') {
                throw new SyncProtocolException('unknown_rotation', 409);
            }
            $collection = $this->resolveCollection('history');
            $stream = $this->journal->prepare($userId, $collection->id);
            $this->journal->requireGeneration($stream, $generation);
            $record = Record::forUser($userId)->inCollection($collection->id)->where('record_id', $id)->first();

            return ['generation' => $generation, 'rotation_id' => $rotation->operation_id, 'record' => $record === null ? null : [
                'id' => $record->record_id, 'revision' => (string) $record->version,
                'payload' => $record->payload, 'deleted' => $record->deleted, 'ttl' => $record->ttl?->toIso8601String(),
            ]];
        }, 5);
    }

    private function historyClearReceipt(object $receipt): array
    {
        return [
            'operation_id' => $receipt->operation_id,
            'previous_generation' => $receipt->previous_generation,
            'generation' => $receipt->generation,
            'clear_before' => Carbon::createFromTimestampMs((int) $receipt->clear_before_ms, 'UTC')->toISOString(),
            'deleted_records' => (int) $receipt->deleted_records,
        ];
    }

    public function migrateHistoryId(int $userId, int $deviceId, array $data): array
    {
        $context = $data['crypto'] + ['session_id' => $data['session_id']];
        $operationId = strtolower($data['operation_id']);
        $targetRevision = $data['target_revision'] ?? '0';
        $targetDeleted = (bool) ($data['target_deleted'] ?? false);
        $targetTtl = empty($data['target_ttl']) ? null : Carbon::parse($data['target_ttl'])->utc()->startOfSecond()->toIso8601String();
        $receiptFields = [$deviceId, $data['rotation_id'], $data['generation'],
            $data['source_id'], $data['source_revision'], $data['target_id'], $data['source_payload'], $data['target_payload'], $targetTtl];
        if (array_key_exists('target_revision', $data) || array_key_exists('target_deleted', $data)) {
            array_push($receiptFields, $targetRevision, $targetDeleted);
        }
        $fingerprint = hash('sha256', json_encode($receiptFields, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($userId, $deviceId, $data, $context, $operationId, $fingerprint, $targetTtl, $targetRevision, $targetDeleted) {
            $this->journal->lockUser($userId);
            $this->journal->requireDevice($userId, $deviceId);
            $receipts = DB::table('sync_history_id_migrations')->where('user_id', $userId);
            $receipt = (clone $receipts)->where('operation_id', $operationId)->first();
            if ($receipt) {
                $state = $this->crypto->snapshot($userId, $context['session_id']);
                if ($context['epoch'] !== $state['epoch']) {
                    throw new SyncProtocolException('crypto_state_conflict', 409);
                }
                if (! hash_equals($receipt->fingerprint, $fingerprint)) {
                    throw new SyncProtocolException('idempotency_conflict', 409);
                }

                return json_decode($receipt->response, true, flags: JSON_THROW_ON_ERROR);
            }
            $state = $this->crypto->authorizeWrite($userId, $context, $deviceId);
            $rotation = DB::table('sync_key_rotations')->where('user_id', $userId)->first();
            if (! $state || $state->custody !== 'transition' || $rotation?->operation_id !== $data['rotation_id'] || $rotation->phase !== 'rewriting') {
                throw new SyncProtocolException('unknown_rotation', 409);
            }
            $collection = $this->resolveCollection('history');
            $records = Record::where('user_id', $userId)->where('collection_id', $collection->id);
            $source = (clone $records)->where('record_id', $data['source_id'])->first();
            if (! $source || $source->deleted || (string) $source->version !== $data['source_revision']) {
                throw new SyncProtocolException('history_source_conflict', 409);
            }
            $target = (clone $records)->where('record_id', $data['target_id'])->first();
            if (($target ? (string) $target->version : '0') !== $targetRevision) {
                throw new SyncProtocolException($targetRevision === '0' ? 'history_target_exists' : 'history_target_conflict', 409);
            }
            if ($target?->deleted && ! $targetDeleted) {
                throw new SyncProtocolException('history_target_deleted', 409);
            }
            $ttls = array_filter([$source->ttl, $target?->ttl]);
            $expectedTtl = $targetDeleted || $ttls === [] ? null : collect($ttls)->sortBy(fn ($ttl) => $ttl->getTimestamp())->first()->utc()->startOfSecond()->toIso8601String();
            if ($targetTtl !== $expectedTtl) {
                throw new SyncProtocolException('history_source_conflict', 409);
            }
            $operations = [
                ['operation_id' => (string) Str::uuid(), 'id' => $data['source_id'], 'base_revision' => $data['source_revision'],
                    'payload' => $data['source_payload'], 'deleted' => true],
                ['operation_id' => (string) Str::uuid(), 'id' => $data['target_id'], 'base_revision' => $targetRevision,
                    'payload' => $data['target_payload'], 'deleted' => $targetDeleted, 'ttl' => $targetTtl],
            ];
            $result = $this->applyOperations($userId, 'history', $deviceId, $data['generation'], $operations, $context);
            foreach ($result['results'] as $outcome) {
                if ($outcome['status'] !== 'applied') {
                    throw new SyncProtocolException('history_migration_'.$outcome['status'], 409);
                }
            }
            $response = ['operation_id' => $operationId, 'rotation_id' => $rotation->operation_id, 'generation' => $data['generation'],
                'source_id' => $data['source_id'], 'source_revision' => $result['results'][0]['revision'],
                'target_id' => $data['target_id'], 'target_revision' => $result['results'][1]['revision'], 'target_deleted' => $targetDeleted];
            $receipts->insert(['user_id' => $userId, 'operation_id' => $operationId, 'rotation_id' => $rotation->operation_id,
                'fingerprint' => $fingerprint, 'response' => json_encode($response, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $response;
        }, 5);
    }

    public function applyOperations(int $userId, string $collectionName, int $deviceId, string $generation, array $operations, ?array $cryptoContext = null): array
    {
        if (count($operations) < 1 || count($operations) > 100) {
            throw new SyncProtocolException('invalid_batch_size');
        }
        $collection = $this->resolveCollection($collectionName);
        if (! $collection) {
            throw new SyncProtocolException('unknown_collection', 404);
        }

        return DB::transaction(function () use ($userId, $collection, $deviceId, $generation, $operations, $cryptoContext) {
            $user = $this->journal->lockUser($userId);
            $cryptoState = $this->crypto->authorizeWrite($userId, $cryptoContext, $deviceId);
            $protected = $cryptoState && $cryptoState->custody !== 'server';
            $this->journal->requireDevice($userId, $deviceId);
            $stream = $this->journal->prepare($userId, $collection->id);
            $this->journal->requireGeneration($stream, $generation);

            $quotaTime = now();
            $usage = $this->quota->usage($userId, $quotaTime);
            $results = [];
            $changed = [];
            foreach ($operations as $index => $operation) {
                $validated = $this->validateOperation($operation, $protected);
                if ($validated === null) {
                    $results[] = ['index' => $index, 'status' => 'invalid'];

                    continue;
                }
                $operationId = $validated['operation_id'];
                $fingerprint = hash('sha256', json_encode([$deviceId, $validated], JSON_THROW_ON_ERROR));
                $previous = DB::table('sync_operations')->where('stream_id', $stream->id)
                    ->where('operation_id', $operationId)->first();
                if ($previous) {
                    $results[] = ['index' => $index] + (hash_equals($previous->fingerprint, $fingerprint)
                        ? json_decode($previous->result, true, flags: JSON_THROW_ON_ERROR)
                        : ['operation_id' => $operationId, 'status' => 'idempotency_conflict']);

                    continue;
                }

                if ($cryptoState && ! $this->envelope->validRecord($validated, $collection->name, $generation, $cryptoState->active_key_id, $protected ? 2 : 1)) {
                    $results[] = ['index' => $index, 'operation_id' => $operationId, 'status' => 'invalid'];

                    continue;
                }

                $record = Record::forUser($userId)->inCollection($collection->id)
                    ->where('record_id', $validated['id'])->first();
                $revision = $record ? (string) $record->version : '0';
                $result = ['operation_id' => $operationId, 'id' => $validated['id']];
                if ($revision !== $validated['base_revision']) {
                    $result += ['status' => 'conflict', 'revision' => $revision];
                } else {
                    $oldBytes = $record ? $this->quota->recordBytes($record->payload, $record->deleted, $record->ttl?->toIso8601String(), $quotaTime, $protected) : 0;
                    $delta = $this->quota->recordBytes($validated['payload'], $validated['deleted'], $validated['ttl'], $quotaTime, $protected) - $oldBytes;
                    try {
                        $this->quota->checkDelta($user, $delta, $usage);
                    } catch (SyncQuotaException $e) {
                        $results[] = ['index' => $index] + $result + ['status' => 'quota_exceeded'];

                        continue;
                    }
                    if ((int) $revision >= 2147483647) {
                        throw new SyncProtocolException('revision_exhausted', 409);
                    }
                    $record ??= new Record(['user_id' => $userId, 'collection_id' => $collection->id, 'record_id' => $validated['id']]);
                    $record->fill([
                        'payload' => $validated['payload'],
                        'deleted' => $validated['deleted'],
                        'ttl' => $validated['ttl'],
                        'version' => (int) $revision + 1,
                        'modified_at' => microtime(true),
                    ])->save();
                    $usage += $delta;
                    $changed[] = $record;
                    $result += ['status' => 'applied', 'revision' => (string) $record->version];
                }

                DB::table('sync_operations')->insert([
                    'stream_id' => $stream->id,
                    'operation_id' => $operationId,
                    'fingerprint' => $fingerprint,
                    'result' => json_encode($result, JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
                $results[] = ['index' => $index] + $result;
            }
            $this->journal->append($userId, $collection->id, $changed);
            $this->updateCollectionStats($userId, $collection->id);

            return ['generation' => $stream->generation, 'results' => $results];
        }, 5);
    }

    private function validateOperation(mixed $operation, bool $protected = false): ?array
    {
        if (! is_array($operation)) {
            return null;
        }
        $validator = Validator::make(['operation' => $operation], [
            'operation' => ['required', 'array:operation_id,id,base_revision,payload,deleted,ttl'],
            'operation.operation_id' => ['required', 'uuid'],
            'operation.id' => ['required', 'string', 'max:255'],
            'operation.base_revision' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,9})$/D'],
            'operation.deleted' => ['sometimes', 'boolean'],
            'operation.payload' => [$protected ? 'required' : 'required_unless:operation.deleted,true', 'nullable', 'string'],
            'operation.ttl' => ['nullable', 'date'],
        ]);
        if ($validator->fails() || strlen($operation['payload'] ?? '') > (int) config('services.sync.max_record_size', 262144)) {
            return null;
        }

        $deleted = (bool) ($operation['deleted'] ?? false);

        return [
            'operation_id' => strtolower($operation['operation_id']),
            'id' => $operation['id'],
            'base_revision' => $operation['base_revision'],
            'payload' => $deleted && ! $protected ? '' : $operation['payload'],
            'deleted' => $deleted,
            'ttl' => $deleted || empty($operation['ttl']) ? null : Carbon::parse($operation['ttl'])->utc()->startOfSecond()->toIso8601String(),
        ];
    }

    public function upsertRecord(
        int $userId,
        string $collectionName,
        string $recordId,
        string $payload,
        ?float $ifUnmodifiedSince = null,
        ?string $ttl = null,
    ): array {
        $collection = $this->resolveCollection($collectionName);
        if (! $collection) {
            throw new \InvalidArgumentException("Unknown collection: {$collectionName}");
        }

        $maxSize = (int) config('services.sync.max_record_size', 262144);
        if (strlen($payload) > $maxSize) {
            throw new \OverflowException("Record payload exceeds maximum size of {$maxSize} bytes");
        }

        return DB::transaction(function () use ($userId, $collection, $recordId, $payload, $ifUnmodifiedSince, $ttl) {
            $user = $this->journal->lockUser($userId);
            $this->crypto->requireLegacyWriter($userId);
            $this->journal->prepare($userId, $collection->id);
            $existing = Record::forUser($userId)
                ->inCollection($collection->id)
                ->where('record_id', $recordId)
                ->lockForUpdate()
                ->first();

            if ($existing && $ifUnmodifiedSince !== null) {
                if ((float) $existing->modified_at > $ifUnmodifiedSince) {
                    throw new \RuntimeException('Conflict: record has been modified');
                }
            }

            $now = microtime(true);

            $this->quota->checkReplacement($user, $collection->id, [[
                'record_id' => $recordId,
                'payload' => $payload,
                'deleted' => false,
                'ttl' => $ttl,
            ]]);

            if ($existing) {
                $existing->update([
                    'payload' => $payload,
                    'version' => $existing->version + 1,
                    'modified_at' => $now,
                    'deleted' => false,
                    'ttl' => $ttl,
                ]);
                $record = $existing->fresh();
            } else {
                $record = Record::create([
                    'id' => Str::uuid(),
                    'user_id' => $userId,
                    'collection_id' => $collection->id,
                    'record_id' => $recordId,
                    'version' => 1,
                    'payload' => $payload,
                    'modified_at' => $now,
                    'deleted' => false,
                    'ttl' => $ttl,
                ]);
            }

            $this->journal->append($userId, $collection->id, [$record]);
            $this->updateCollectionStats($userId, $collection->id);

            return $this->formatRecord($record);
        }, 5);
    }

    public function batchUpsert(int $userId, string $collectionName, array $records): array
    {
        if (count($records) > 100) {
            throw new \InvalidArgumentException('A batch cannot exceed 100 records');
        }
        $collection = $this->resolveCollection($collectionName);
        if (! $collection) {
            throw new \InvalidArgumentException("Unknown collection: {$collectionName}");
        }

        $maxSize = (int) config('services.sync.max_record_size', 262144);
        $now = microtime(true);
        $nowDt = now();

        // First pass: validate, build rows preserving original indices.
        $results = [];
        $validRows = [];
        $seenIds = [];
        foreach ($records as $i => $data) {
            $recordId = $data['id'] ?? null;
            $payload = $data['payload'] ?? '';

            if (! $recordId) {
                $results[$i] = ['index' => $i, 'error' => 'Missing record id'];

                continue;
            }

            if (isset($seenIds[$recordId])) {
                $results[$i] = ['index' => $i, 'error' => 'Duplicate record id in batch'];

                continue;
            }
            $seenIds[$recordId] = true;

            if (strlen($payload) > $maxSize) {
                $results[$i] = ['index' => $i, 'error' => 'Payload too large'];

                continue;
            }

            $recordNow = $now + ($i * 0.000001);
            $ttl = $data['ttl'] ?? null;
            $ttlValue = $ttl ? Carbon::parse($ttl)->toDateTimeString() : null;

            $validRows[$i] = [
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'collection_id' => $collection->id,
                'record_id' => $recordId,
                'version' => 1,
                'payload' => ! empty($data['deleted']) ? '' : $payload,
                'modified_at' => $recordNow,
                'deleted' => ! empty($data['deleted']) ? 1 : 0,
                'ttl' => ! empty($data['deleted']) ? null : $ttlValue,
                'created_at' => $nowDt->toDateTimeString(),
                'updated_at' => $nowDt->toDateTimeString(),
            ];

            $results[$i] = ['index' => $i, 'id' => $recordId, 'modified_at' => $recordNow];
        }

        if (empty($validRows)) {
            DB::transaction(function () use ($userId) {
                $this->journal->lockUser($userId);
                $this->crypto->requireLegacyWriter($userId);
            }, 5);
            ksort($results);

            return array_values($results);
        }

        DB::transaction(function () use ($userId, $collection, $validRows) {
            $user = $this->journal->lockUser($userId);
            $this->crypto->requireLegacyWriter($userId);
            $this->journal->prepare($userId, $collection->id);
            $this->quota->checkReplacement($user, $collection->id, $validRows);
            $this->nativeUpsert(array_values($validRows));
            $changed = Record::forUser($userId)->inCollection($collection->id)
                ->whereIn('record_id', array_column($validRows, 'record_id'))->orderBy('record_id')->get();
            $this->journal->append($userId, $collection->id, $changed);
            $this->updateCollectionStats($userId, $collection->id);
        }, 5);

        ksort($results);

        return array_values($results);
    }

    /**
     * Issue a single INSERT ... ON CONFLICT DO UPDATE statement for all
     * rows. Works on PostgreSQL and on SQLite 3.24+ (both back the unique
     * index `(user_id, collection_id, record_id)`). On conflict we bump
     * `version` server-side via `records.version + 1`, something that
     * Eloquent's `upsert()` helper cannot express.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function nativeUpsert(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $columns = [
            'id', 'user_id', 'collection_id', 'record_id', 'version',
            'payload', 'modified_at', 'deleted', 'ttl',
            'created_at', 'updated_at',
        ];

        $placeholderRow = '('.implode(',', array_fill(0, count($columns), '?')).')';
        $placeholders = implode(',', array_fill(0, count($rows), $placeholderRow));

        $bindings = [];
        foreach ($rows as $row) {
            foreach ($columns as $col) {
                $bindings[] = $row[$col];
            }
        }

        $sql = 'INSERT INTO records ('.implode(',', $columns).') '
            ."VALUES {$placeholders} "
            .'ON CONFLICT (user_id, collection_id, record_id) DO UPDATE SET '
            .'payload = EXCLUDED.payload, '
            .'version = records.version + 1, '
            .'modified_at = EXCLUDED.modified_at, '
            .'deleted = EXCLUDED.deleted, '
            .'ttl = EXCLUDED.ttl, '
            .'updated_at = EXCLUDED.updated_at';

        DB::statement($sql, $bindings);
    }

    public function deleteRecord(int $userId, string $collectionName, string $recordId): bool
    {
        $collection = $this->resolveCollection($collectionName);
        if (! $collection) {
            return false;
        }

        return DB::transaction(function () use ($userId, $collection, $recordId) {
            $this->journal->lockUser($userId);
            $this->crypto->requireLegacyWriter($userId);
            $this->journal->prepare($userId, $collection->id);
            $record = Record::forUser($userId)->inCollection($collection->id)
                ->where('record_id', $recordId)->first();
            if (! $record) {
                return false;
            }
            if (! $record->deleted) {
                $this->tombstone($record);
                $this->journal->append($userId, $collection->id, [$record]);
            }
            $this->updateCollectionStats($userId, $collection->id);

            return true;
        }, 5);
    }

    public function deleteCollection(int $userId, string $collectionName): int
    {
        $collection = $this->resolveCollection($collectionName);
        if (! $collection) {
            return 0;
        }

        return DB::transaction(function () use ($userId, $collection) {
            $this->journal->lockUser($userId);
            $this->crypto->requireLegacyWriter($userId);
            $this->journal->prepare($userId, $collection->id);
            $count = 0;
            Record::forUser($userId)->inCollection($collection->id)->where('deleted', false)
                ->chunkById(100, function ($records) use ($userId, $collection, &$count) {
                    foreach ($records as $record) {
                        $this->tombstone($record);
                        $count++;
                    }
                    $this->journal->append($userId, $collection->id, $records);
                });
            $this->updateCollectionStats($userId, $collection->id);

            return $count;
        }, 5);
    }

    public function getSyncInfo(int $userId): array
    {
        $usage = $this->quota->usage($userId);

        $user = User::find($userId);
        $lastModified = Record::where('user_id', $userId)->max('modified_at');

        return [
            'quota_bytes' => $user->storage_quota_bytes,
            'used_bytes' => (int) $usage,
            'last_modified' => $lastModified ? (float) $lastModified : null,
        ];
    }

    public function getCollectionStatus(int $userId): array
    {
        return UserCollection::where('user_id', $userId)
            ->join('collections', 'collections.id', '=', 'user_collections.collection_id')
            ->select([
                'collections.name',
                'user_collections.last_modified',
                'user_collections.record_count',
                'user_collections.size_bytes',
            ])
            ->get()
            ->keyBy('name')
            ->map(fn ($uc) => [
                'last_modified' => (float) $uc->last_modified,
                'record_count' => $uc->record_count,
                'size_bytes' => $uc->size_bytes,
            ])
            ->all();
    }

    public function deleteAllUserData(int $userId, ?array $cryptoContext = null, bool $ownerRequest = false): void
    {
        DB::transaction(function () use ($userId, $cryptoContext, $ownerRequest) {
            $this->journal->lockUser($userId);
            if ($cryptoContext !== null) {
                $this->crypto->authorizeReset($userId, $cryptoContext, $ownerRequest);
            } elseif (! $ownerRequest) {
                $this->crypto->requireLegacyWriter($userId);
            }
            Record::where('user_id', $userId)->delete();
            UserCollection::where('user_id', $userId)->delete();
            CryptoKeyBundle::where('user_id', $userId)->delete();
            $this->crypto->reset($userId);
            $this->journal->resetUser($userId);
        }, 5);
    }

    public function cleanupExpiredRecords(): int
    {
        $count = 0;
        Record::where('deleted', false)->where('ttl', '<=', now())
            ->whereNotIn('user_id', DB::table('sync_crypto_states')->where('custody', '<>', 'server')->select('user_id'))
            ->chunkById(100, function ($records) use (&$count) {
                foreach ($records->groupBy(['user_id', 'collection_id']) as $userId => $collections) {
                    foreach ($collections as $collectionId => $expired) {
                        $count += DB::transaction(function () use ($userId, $collectionId, $expired) {
                            $this->journal->lockUser($userId);
                            if (DB::table('sync_crypto_states')->where('user_id', $userId)->where('custody', '<>', 'server')->exists()) {
                                return 0;
                            }
                            $this->journal->prepare($userId, $collectionId);
                            $records = Record::whereIn('id', $expired->pluck('id')->all())
                                ->where('deleted', false)->where('ttl', '<=', now())->get();
                            foreach ($records as $record) {
                                $this->tombstone($record);
                            }
                            $this->journal->append($userId, $collectionId, $records);
                            $this->updateCollectionStats($userId, $collectionId);

                            return $records->count();
                        }, 5);
                    }
                }
            });

        return $count;
    }

    private function tombstone(Record $record): void
    {
        $record->update([
            'deleted' => true,
            'payload' => '',
            'ttl' => null,
            'version' => $record->version + 1,
            'modified_at' => microtime(true),
        ]);
    }

    public function recalculateUsage(int $userId): void
    {
        DB::transaction(function () use ($userId) {
            $this->journal->lockUser($userId);
            foreach (Collection::all() as $collection) {
                $this->updateCollectionStats($userId, $collection->id);
            }
        }, 5);
    }

    private function updateCollectionStats(int $userId, int $collectionId): void
    {
        $bytes = SyncQuota::payloadBytesSql();
        $stats = Record::forUser($userId)->inCollection($collectionId)->active()
            ->selectRaw("COUNT(*) as record_count, COALESCE(SUM({$bytes}), 0) as size_bytes")
            ->first();
        $lastModified = Record::forUser($userId)->inCollection($collectionId)->max('modified_at');

        if ($lastModified === null) {
            UserCollection::where('user_id', $userId)->where('collection_id', $collectionId)->delete();

            return;
        }

        UserCollection::updateOrCreate(
            ['user_id' => $userId, 'collection_id' => $collectionId],
            [
                'record_count' => $stats->record_count ?? 0,
                'size_bytes' => $stats->size_bytes ?? 0,
                'last_modified' => $lastModified,
            ]
        );
    }

    private function formatRecord(Record $record): array
    {
        return [
            'id' => $record->record_id,
            'version' => $record->version,
            'payload' => $record->payload,
            'modified_at' => (float) $record->modified_at,
            'ttl' => $record->ttl?->toIso8601String(),
            'deleted' => $record->deleted,
        ];
    }

    private function resolveCollection(string $collectionName): ?Collection
    {
        $collection = Collection::findByName($collectionName);
        if ($collection) {
            return $collection;
        }

        $description = self::CANONICAL_COLLECTIONS[$collectionName] ?? null;
        if ($description === null) {
            return null;
        }

        $collection = Collection::firstOrCreate(
            ['name' => $collectionName],
            ['description' => $description],
        );

        Collection::forgetByName($collectionName);

        return $collection;
    }
}
