<?php

namespace App\Services;

use App\Models\Collection;
use App\Models\Record;
use App\Models\UserCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncStorageService
{
    public function getRecords(
        int $userId,
        string $collectionName,
        ?float $since = null,
        ?int $limit = null,
        ?string $sort = 'newest',
        bool $includeDeleted = false,
    ): array {
        $collection = Collection::findByName($collectionName);
        if (!$collection) {
            return [];
        }

        $query = Record::forUser($userId)->inCollection($collection->id);

        if ($since !== null) {
            $query->modifiedSince($since);
        }

        if (!$includeDeleted) {
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
        $collection = Collection::findByName($collectionName);
        if (!$collection) {
            return null;
        }

        $record = Record::forUser($userId)
            ->inCollection($collection->id)
            ->where('record_id', $recordId)
            ->first();

        return $record ? $this->formatRecord($record) : null;
    }

    public function upsertRecord(
        int $userId,
        string $collectionName,
        string $recordId,
        string $payload,
        ?float $ifUnmodifiedSince = null,
        ?string $ttl = null,
    ): array {
        $collection = Collection::findByName($collectionName);
        if (!$collection) {
            throw new \InvalidArgumentException("Unknown collection: {$collectionName}");
        }

        $maxSize = (int) config('services.sync.max_record_size', 262144);
        if (strlen($payload) > $maxSize) {
            throw new \OverflowException("Record payload exceeds maximum size of {$maxSize} bytes");
        }

        return DB::transaction(function () use ($userId, $collection, $recordId, $payload, $ifUnmodifiedSince, $ttl) {
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

            $this->updateCollectionStats($userId, $collection->id);

            return $this->formatRecord($record);
        });
    }

    public function batchUpsert(int $userId, string $collectionName, array $records): array
    {
        $collection = Collection::findByName($collectionName);
        if (!$collection) {
            throw new \InvalidArgumentException("Unknown collection: {$collectionName}");
        }

        $maxSize = (int) config('services.sync.max_record_size', 262144);
        $now = microtime(true);
        $nowDt = now();

        // First pass: validate, build rows preserving original indices.
        $results = [];
        $validRows = [];
        foreach ($records as $i => $data) {
            $recordId = $data['id'] ?? null;
            $payload = $data['payload'] ?? '';

            if (!$recordId) {
                $results[$i] = ['index' => $i, 'error' => 'Missing record id'];
                continue;
            }

            if (strlen($payload) > $maxSize) {
                $results[$i] = ['index' => $i, 'error' => 'Payload too large'];
                continue;
            }

            $recordNow = $now + ($i * 0.000001);
            $ttl = $data['ttl'] ?? null;
            $ttlValue = $ttl ? \Illuminate\Support\Carbon::parse($ttl)->toDateTimeString() : null;

            $validRows[$i] = [
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'collection_id' => $collection->id,
                'record_id' => $recordId,
                'version' => 1,
                'payload' => $payload,
                'modified_at' => $recordNow,
                'deleted' => !empty($data['deleted']) ? 1 : 0,
                'ttl' => $ttlValue,
                'created_at' => $nowDt->toDateTimeString(),
                'updated_at' => $nowDt->toDateTimeString(),
            ];

            $results[$i] = ['index' => $i, 'id' => $recordId, 'modified_at' => $recordNow];
        }

        if (empty($validRows)) {
            ksort($results);
            return array_values($results);
        }

        DB::transaction(function () use ($userId, $collection, $validRows) {
            $this->nativeUpsert(array_values($validRows));
            $this->updateCollectionStats($userId, $collection->id);
        });

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
     * @param array<int, array<string, mixed>> $rows
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

        $placeholderRow = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $placeholders = implode(',', array_fill(0, count($rows), $placeholderRow));

        $bindings = [];
        foreach ($rows as $row) {
            foreach ($columns as $col) {
                $bindings[] = $row[$col];
            }
        }

        $sql = "INSERT INTO records (" . implode(',', $columns) . ") "
            . "VALUES {$placeholders} "
            . "ON CONFLICT (user_id, collection_id, record_id) DO UPDATE SET "
            . "payload = EXCLUDED.payload, "
            . "version = records.version + 1, "
            . "modified_at = EXCLUDED.modified_at, "
            . "deleted = EXCLUDED.deleted, "
            . "ttl = EXCLUDED.ttl, "
            . "updated_at = EXCLUDED.updated_at";

        DB::statement($sql, $bindings);
    }

    public function deleteRecord(int $userId, string $collectionName, string $recordId): bool
    {
        $collection = Collection::findByName($collectionName);
        if (!$collection) {
            return false;
        }

        $deleted = Record::forUser($userId)
            ->inCollection($collection->id)
            ->where('record_id', $recordId)
            ->update([
                'deleted' => true,
                'payload' => '',
                'modified_at' => microtime(true),
            ]);

        if ($deleted) {
            $this->updateCollectionStats($userId, $collection->id);
        }

        return $deleted > 0;
    }

    public function deleteCollection(int $userId, string $collectionName): int
    {
        $collection = Collection::findByName($collectionName);
        if (!$collection) {
            return 0;
        }

        $count = Record::forUser($userId)
            ->inCollection($collection->id)
            ->delete();

        UserCollection::where('user_id', $userId)
            ->where('collection_id', $collection->id)
            ->delete();

        return $count;
    }

    public function getSyncInfo(int $userId): array
    {
        $usage = Record::where('user_id', $userId)
            ->where('deleted', false)
            ->sum(DB::raw('LENGTH(payload)'));

        $user = \App\Models\User::find($userId);
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

    public function deleteAllUserData(int $userId): void
    {
        DB::transaction(function () use ($userId) {
            Record::where('user_id', $userId)->delete();
            UserCollection::where('user_id', $userId)->delete();
            \App\Models\CryptoKeyBundle::where('user_id', $userId)->delete();
        });
    }

    public function cleanupExpiredRecords(): int
    {
        return Record::whereNotNull('ttl')
            ->where('ttl', '<=', now())
            ->delete();
    }

    public function recalculateUsage(int $userId): void
    {
        $collections = Collection::all();

        foreach ($collections as $collection) {
            $stats = Record::where('user_id', $userId)
                ->where('collection_id', $collection->id)
                ->where('deleted', false)
                ->selectRaw('COUNT(*) as record_count, COALESCE(SUM(LENGTH(payload)), 0) as size_bytes, MAX(modified_at) as last_modified')
                ->first();

            if ($stats->record_count > 0) {
                UserCollection::updateOrCreate(
                    ['user_id' => $userId, 'collection_id' => $collection->id],
                    [
                        'record_count' => $stats->record_count,
                        'size_bytes' => $stats->size_bytes,
                        'last_modified' => $stats->last_modified ?? 0,
                    ]
                );
            } else {
                UserCollection::where('user_id', $userId)
                    ->where('collection_id', $collection->id)
                    ->delete();
            }
        }
    }

    private function updateCollectionStats(int $userId, int $collectionId): void
    {
        $stats = Record::where('user_id', $userId)
            ->where('collection_id', $collectionId)
            ->where('deleted', false)
            ->selectRaw('COUNT(*) as record_count, COALESCE(SUM(LENGTH(payload)), 0) as size_bytes, MAX(modified_at) as last_modified')
            ->first();

        UserCollection::updateOrCreate(
            ['user_id' => $userId, 'collection_id' => $collectionId],
            [
                'record_count' => $stats->record_count ?? 0,
                'size_bytes' => $stats->size_bytes ?? 0,
                'last_modified' => $stats->last_modified ?? microtime(true),
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
}
