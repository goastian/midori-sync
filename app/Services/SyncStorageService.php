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

    public function getChanges(int $userId, string $collectionName, int $deviceId, ?string $cursor, int $limit): array
    {
        $collection = $this->resolveCollection($collectionName);
        if (! $collection) {
            throw new SyncProtocolException('unknown_collection', 404);
        }

        return $this->journal->changes($userId, $collection->id, $deviceId, $cursor, $limit);
    }

    public function acknowledgeChanges(int $userId, string $collectionName, int $deviceId, string $cursor): array
    {
        $collection = $this->resolveCollection($collectionName);
        if (! $collection) {
            throw new SyncProtocolException('unknown_collection', 404);
        }

        return $this->journal->acknowledge($userId, $collection->id, $deviceId, $cursor);
    }

    public function clearHistory(int $userId, int $deviceId, string $operationId, string $generation, array $cryptoContext): array
    {
        $collection = $this->resolveCollection('history');
        if (! $collection || ! Str::isUuid($operationId) || ! Str::isUuid($generation)) {
            throw new SyncProtocolException('invalid_history_clear');
        }
        $operationId = strtolower($operationId);
        $generation = strtolower($generation);

        return DB::transaction(function () use ($userId, $deviceId, $operationId, $generation, $cryptoContext, $collection) {
            $this->journal->lockUser($userId);
            $this->crypto->authorizeWrite($userId, $cryptoContext, $deviceId);
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
            $this->journal->requireDevice($userId, $deviceId);
            $stream = $this->journal->prepare($userId, $collection->id);
            if ($stream->generation !== $generation) {
                throw new SyncProtocolException('reset_required', 409);
            }

            $quotaTime = now();
            $usage = $this->quota->usage($userId, $quotaTime);
            $results = [];
            $changed = [];
            foreach ($operations as $index => $operation) {
                $validated = $this->validateOperation($operation);
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

                if ($cryptoState && ! $this->envelope->validRecord($validated, $collection->name, $generation, $cryptoState->active_key_id)) {
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
                    $oldBytes = $record ? $this->quota->recordBytes($record->payload, $record->deleted, $record->ttl?->toIso8601String(), $quotaTime) : 0;
                    $delta = $this->quota->recordBytes($validated['payload'], $validated['deleted'], $validated['ttl'], $quotaTime) - $oldBytes;
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

    private function validateOperation(mixed $operation): ?array
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
            'operation.payload' => ['required_unless:operation.deleted,true', 'nullable', 'string'],
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
            'payload' => $deleted ? '' : $operation['payload'],
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
            if (! $ownerRequest) {
                if ($cryptoContext !== null) {
                    $this->crypto->authorizeReset($userId, $cryptoContext);
                } else {
                    $this->crypto->requireLegacyWriter($userId);
                }
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
            ->chunkById(100, function ($records) use (&$count) {
                foreach ($records->groupBy(['user_id', 'collection_id']) as $userId => $collections) {
                    foreach ($collections as $collectionId => $expired) {
                        $count += DB::transaction(function () use ($userId, $collectionId, $expired) {
                            $this->journal->lockUser($userId);
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
