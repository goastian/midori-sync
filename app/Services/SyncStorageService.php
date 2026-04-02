<?php

namespace App\Services;

use App\Models\Bso;
use App\Models\Collection;
use App\Models\User;
use App\Models\UserCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Core Sync Storage service implementing the Firefox Sync 1.5 storage logic.
 *
 * All data stored is encrypted client-side (E2E encryption).
 * The server only stores opaque encrypted blobs.
 *
 * @see https://mozilla-services.readthedocs.io/en/latest/storage/apis-1.5.html
 */
class SyncStorageService
{
    /**
     * Get the current server timestamp with millisecond precision.
     */
    public function getTimestamp(): float
    {
        return round(microtime(true), 2);
    }

    /**
     * Get timestamps for all collections that have been modified.
     *
     * @return array<string, float>
     */
    public function getCollectionTimestamps(User $user): array
    {
        $results = UserCollection::where('user_id', $user->id)
            ->join('collections', 'user_collections.collection_id', '=', 'collections.id')
            ->pluck('user_collections.modified', 'collections.name');

        return $results->toArray();
    }

    /**
     * Get item counts per collection for a user.
     *
     * @return array<string, int>
     */
    public function getCollectionCounts(User $user): array
    {
        return Bso::where('bso.user_id', $user->id)
            ->join('collections', 'bso.collection_id', '=', 'collections.id')
            ->groupBy('collections.name')
            ->selectRaw('collections.name, COUNT(*) as cnt')
            ->pluck('cnt', 'collections.name')
            ->toArray();
    }

    /**
     * Get storage usage per collection in bytes.
     *
     * @return array<string, int>
     */
    public function getCollectionUsage(User $user): array
    {
        return Bso::where('bso.user_id', $user->id)
            ->join('collections', 'bso.collection_id', '=', 'collections.id')
            ->groupBy('collections.name')
            ->selectRaw('collections.name, SUM(bso.payload_size) as usage')
            ->pluck('usage', 'collections.name')
            ->map(fn ($v) => (int) $v)
            ->toArray();
    }

    /**
     * Get quota information for a user.
     *
     * @return array{used: int, quota: int}
     */
    public function getQuota(User $user): array
    {
        $used = Bso::where('user_id', $user->id)->sum('payload_size');

        return [
            (int) $used,
            $user->storage_quota_bytes,
        ];
    }

    /**
     * Resolve a collection name to its ID, creating it if needed.
     */
    public function resolveCollectionId(string $name): int
    {
        $collection = Collection::firstOrCreate(['name' => $name]);

        return $collection->id;
    }

    /**
     * Get BSOs from a collection with filtering and pagination.
     *
     * @param array<string, mixed> $params Filter parameters (ids, newer, older, sort, limit, offset, full)
     * @return array{items: array<int, mixed>, offset: string|null}
     */
    public function getBsos(User $user, string $collectionName, array $params = []): array
    {
        $collection = Collection::where('name', $collectionName)->first();
        if (!$collection) {
            return [
                'items' => [],
                'offset' => null,
            ];
        }

        $collectionId = $collection->id;

        $query = Bso::where('user_id', $user->id)
            ->where('collection_id', $collectionId)
            ->where(function ($q) {
                $q->whereNull('expiry')->orWhere('expiry', '>', now());
            });

        // Filter by IDs
        if (!empty($params['ids'])) {
            $ids = is_array($params['ids']) ? $params['ids'] : explode(',', $params['ids']);
            $query->whereIn('bso_id', $ids);
        }

        // Filter by newer than timestamp
        if (isset($params['newer'])) {
            $query->where('modified', '>', (float) $params['newer']);
        }

        // Filter by older than timestamp
        if (isset($params['older'])) {
            $query->where('modified', '<', (float) $params['older']);
        }

        // Sort order
        $sort = $params['sort'] ?? 'newest';
        match ($sort) {
            'newest' => $query->orderBy('modified', 'desc'),
            'oldest' => $query->orderBy('modified', 'asc'),
            'index' => $query->orderBy('sortindex', 'desc'),
            default => $query->orderBy('modified', 'desc'),
        };

        // Pagination
        $limit = isset($params['limit']) ? min((int) $params['limit'], 1000) : 1000;
        $offset = isset($params['offset']) ? (int) $params['offset'] : 0;

        $query->offset($offset)->limit($limit + 1);

        // Select fields
        $full = filter_var($params['full'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($full) {
            $items = $query->get()->map(fn (Bso $bso) => [
                'id' => $bso->bso_id,
                'modified' => $bso->modified,
                'payload' => $bso->payload,
                'sortindex' => $bso->sortindex,
                'ttl' => $bso->ttl,
            ])->toArray();
        } else {
            $items = $query->pluck('bso_id')->toArray();
        }

        // Check if there are more results
        $nextOffset = null;
        if (count($items) > $limit) {
            array_pop($items);
            $nextOffset = (string) ($offset + $limit);
        }

        return [
            'items' => $items,
            'offset' => $nextOffset,
        ];
    }

    /**
     * Get a single BSO by ID.
     *
     * @return array<string, mixed>|null
     */
    public function getBso(User $user, string $collectionName, string $bsoId): ?array
    {
        $collection = Collection::where('name', $collectionName)->first();
        if (!$collection) {
            return null;
        }

        $collectionId = $collection->id;

        $bso = Bso::where('user_id', $user->id)
            ->where('collection_id', $collectionId)
            ->where('bso_id', $bsoId)
            ->where(function ($q) {
                $q->whereNull('expiry')->orWhere('expiry', '>', now());
            })
            ->first();

        if (!$bso) {
            return null;
        }

        return [
            'id' => $bso->bso_id,
            'modified' => $bso->modified,
            'sortindex' => $bso->sortindex,
            'payload' => $bso->payload,
        ];
    }

    /**
     * Store or update a single BSO.
     *
     * @param array<string, mixed> $data BSO data (payload, sortindex, ttl)
     */
    public function putBso(User $user, string $collectionName, string $bsoId, array $data): float
    {
        $collectionId = $this->resolveCollectionId($collectionName);
        $now = $this->getTimestamp();

        $bso = Bso::where('user_id', $user->id)
            ->where('collection_id', $collectionId)
            ->where('bso_id', $bsoId)
            ->first();

        $attributes = [
            'user_id' => $user->id,
            'collection_id' => $collectionId,
            'bso_id' => $bsoId,
            'modified' => $now,
        ];

        if (isset($data['payload'])) {
            $attributes['payload'] = $data['payload'];
            $attributes['payload_size'] = strlen($data['payload']);
        }

        if (isset($data['sortindex'])) {
            $attributes['sortindex'] = (int) $data['sortindex'];
        }

        if (isset($data['ttl'])) {
            $attributes['ttl'] = (int) $data['ttl'];
            $attributes['expiry'] = now()->addSeconds((int) $data['ttl']);
        }

        if ($bso) {
            $bso->update($attributes);
        } else {
            Bso::create($attributes);
        }

        // Update collection timestamp
        UserCollection::updateOrCreate(
            ['user_id' => $user->id, 'collection_id' => $collectionId],
            ['modified' => $now],
        );

        Log::info('sync_put_bso', [
            'user_id' => $user->id,
            'collection' => $collectionName,
            'bso_id' => $bsoId,
            'modified' => $now,
        ]);

        return $now;
    }

    /**
     * Batch upload BSOs to a collection.
     *
     * @param array<int, array<string, mixed>> $bsos Array of BSO data
     * @return array{modified: float, success: list<string>, failed: array<string, list<string>>}
     */
    public function postBsos(User $user, string $collectionName, array $bsos): array
    {
        $maxPayloadBytes = 262144;
        $maxRecords = 100;

        if (count($bsos) > $maxRecords) {
            throw new \InvalidArgumentException("Maximum {$maxRecords} records per request");
        }

        $collectionId = $this->resolveCollectionId($collectionName);
        $now = $this->getTimestamp();
        $upsertData = [];
        $failed = [];

        foreach ($bsos as $bsoData) {
            $bsoId = $bsoData['id'] ?? null;

            if (!$bsoId || strlen($bsoId) > 64) {
                if ($bsoId) {
                    $failed[$bsoId] = ['invalid id'];
                }
                continue;
            }

            $payload = (string) ($bsoData['payload'] ?? '');
            if ($payload !== '' && strlen($payload) > $maxPayloadBytes) {
                $failed[$bsoId] = ['payload size exceeded'];
                continue;
            }

            $ttl = isset($bsoData['ttl']) ? (int) $bsoData['ttl'] : null;

            $upsertData[] = [
                'user_id' => $user->id,
                'collection_id' => $collectionId,
                'bso_id' => $bsoId,
                'payload' => $payload !== '' ? $payload : null,
                'payload_size' => strlen($payload),
                'modified' => $now,
                'sortindex' => isset($bsoData['sortindex']) ? (int) $bsoData['sortindex'] : 0,
                'ttl' => $ttl,
                'expiry' => $ttl !== null ? now()->addSeconds($ttl) : null,
            ];
        }

        DB::transaction(function () use ($user, $collectionId, $upsertData, $now) {
            if (!empty($upsertData)) {
                DB::table('bso')->upsert(
                    $upsertData,
                    ['user_id', 'collection_id', 'bso_id'],
                    ['payload', 'payload_size', 'modified', 'sortindex', 'ttl', 'expiry'],
                );
            }

            // Update collection timestamp
            UserCollection::updateOrCreate(
                ['user_id' => $user->id, 'collection_id' => $collectionId],
                ['modified' => $now],
            );
        });

        $success = array_column($upsertData, 'bso_id');

        Log::info('sync_post_bsos', [
            'user_id' => $user->id,
            'collection' => $collectionName,
            'received_count' => count($bsos),
            'success_count' => count($success),
            'failed_count' => count($failed),
            'modified' => $now,
        ]);

        return [
            'modified' => $now,
            'success' => $success,
            'failed' => $failed,
        ];
    }

    /**
     * Delete BSOs from a collection.
     *
     * @param array<string, mixed> $params Filter parameters (ids)
     */
    public function deleteBsos(User $user, string $collectionName, array $params = []): float
    {
        $collectionId = $this->resolveCollectionId($collectionName);
        $now = $this->getTimestamp();

        $query = Bso::where('user_id', $user->id)
            ->where('collection_id', $collectionId);

        if (!empty($params['ids'])) {
            $ids = is_array($params['ids']) ? $params['ids'] : explode(',', $params['ids']);
            $query->whereIn('bso_id', $ids);
        }

        $query->delete();

        UserCollection::updateOrCreate(
            ['user_id' => $user->id, 'collection_id' => $collectionId],
            ['modified' => $now],
        );

        return $now;
    }

    /**
     * Delete a single BSO.
     */
    public function deleteBso(User $user, string $collectionName, string $bsoId): float
    {
        $collectionId = $this->resolveCollectionId($collectionName);
        $now = $this->getTimestamp();

        Bso::where('user_id', $user->id)
            ->where('collection_id', $collectionId)
            ->where('bso_id', $bsoId)
            ->delete();

        UserCollection::updateOrCreate(
            ['user_id' => $user->id, 'collection_id' => $collectionId],
            ['modified' => $now],
        );

        return $now;
    }

    /**
     * Delete ALL sync data for a user.
     */
    public function deleteAllUserData(User $user): float
    {
        $now = $this->getTimestamp();

        Bso::where('user_id', $user->id)->delete();
        UserCollection::where('user_id', $user->id)->delete();

        return $now;
    }

    /**
     * Purge expired BSOs across all users.
     */
    public function purgeExpiredBsos(): int
    {
        return Bso::whereNotNull('expiry')
            ->where('expiry', '<', now())
            ->delete();
    }
}
