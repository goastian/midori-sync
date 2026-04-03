<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bso;
use App\Models\Collection;
use App\Models\User;
use App\Models\UserCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Simplified sync storage controller for the Midori Sync browser extension.
 *
 * Uses Bearer token authentication (api_token) instead of Hawk,
 * providing a simpler API for the extension to read/write BSOs.
 */
class ExtensionSyncController extends Controller
{
    /**
     * GET /api/ext/storage/{collection}
     *
     * Retrieve all BSOs in a collection for the authenticated user.
     */
    public function getCollection(Request $request, string $collection): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (!$user) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $col = Collection::where('name', $collection)->first();
        if (!$col) {
            return response()->json([]);
        }

        $newer = $request->query('newer');
        $older = $request->query('older');
        $limitParam = $request->query('limit');
        $limit = $limitParam !== null ? min((int) $limitParam, 500) : null;
        $offset = (int) $request->query('offset', 0);

        $query = Bso::where('user_id', $user->id)
            ->where('collection_id', $col->id)
            ->where(function ($q) {
                $q->whereNull('expiry')->orWhere('expiry', '>', now());
            });

        if ($newer !== null) {
            $query->where('modified', '>', (float) $newer);
        }

        if ($older !== null) {
            $query->where('modified', '<', (float) $older);
        }

        if ($limit !== null) {
            $query->offset($offset)->limit($limit + 1);
        }

        $bsosCollection = $query->get()
            ->map(fn (Bso $bso) => [
                'id' => $bso->bso_id,
                'payload' => $bso->payload,
                'modified' => $bso->modified,
                'sortindex' => $bso->sortindex,
                'ttl' => $bso->ttl,
            ]);

        $bsos = $bsosCollection->toArray();

        $nextOffset = null;
        if ($limit !== null && count($bsos) > $limit) {
            array_pop($bsos);
            $nextOffset = $offset + $limit;
        }

        Log::info('ext_get_collection', [
            'user_id' => $user->id,
            'collection' => $collection,
            'items' => count($bsos),
            'newer' => $newer,
            'limit' => $limit,
            'offset' => $offset,
        ]);

        if ($limit !== null) {
            return response()->json([
                'items' => $bsos,
                'nextOffset' => $nextOffset,
            ]);
        }

        return response()->json($bsos);
    }

    /**
     * POST /api/ext/storage/{collection}
     *
     * Batch upload BSOs to a collection. Accepts an array of {id, payload} objects.
     */
    public function postCollection(Request $request, string $collection): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (!$user) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $col = Collection::firstOrCreate(['name' => $collection]);
        $bsos = $request->json()->all();

        if (count($bsos) > 500) {
            return response()->json([
                'error' => 'batch-size-exceeded',
                'message' => 'Maximum 500 records per request',
            ], 400);
        }

        $now = microtime(true);
        $upsertById = [];
        $success = [];
        $failed = [];

        foreach ($bsos as $item) {
            $bsoId = $item['id'] ?? null;
            $payload = (string) ($item['payload'] ?? '');

            if (!$bsoId) {
                $failed['unknown-' . count($failed)] = ['missing id'];
                continue;
            }

            if (strlen($payload) > 262144) {
                $failed[$bsoId] = ['payload size exceeded'];
                continue;
            }

            try {
                $ttl = isset($item['ttl']) ? (int) $item['ttl'] : null;
                // Keep only one row per bso_id in the same SQL upsert batch.
                // PostgreSQL rejects batches that contain duplicate conflict keys.
                $upsertById[$bsoId] = [
                    'user_id' => $user->id,
                    'collection_id' => $col->id,
                    'bso_id' => $bsoId,
                    'payload' => $payload,
                    'payload_size' => strlen($payload),
                    'modified' => $now,
                    'sortindex' => $item['sortindex'] ?? 0,
                    'ttl' => $ttl,
                    'expiry' => $ttl !== null ? now()->addSeconds($ttl) : null,
                ];
            } catch (\Throwable $e) {
                $failed[$bsoId] = [$e->getMessage()];
            }
        }

        if (!empty($upsertById)) {
            $upsertData = array_values($upsertById);
            DB::table('bso')->upsert(
                $upsertData,
                ['user_id', 'collection_id', 'bso_id'],
                ['payload', 'payload_size', 'modified', 'sortindex', 'ttl', 'expiry']
            );
            $success = array_keys($upsertById);
        }

        Log::info('ext_post_collection', [
            'user_id' => $user->id,
            'collection' => $collection,
            'received_count' => count($bsos),
            'success_count' => count($success),
            'failed_count' => count($failed),
        ]);

        // Update collection timestamp
        UserCollection::updateOrCreate(
            ['user_id' => $user->id, 'collection_id' => $col->id],
            ['modified' => $now]
        );

        return response()->json([
            'modified' => $now,
            'success' => $success,
            'failed' => $failed,
        ]);
    }

    /**
     * DELETE /api/ext/storage/{collection}
     *
     * Delete all BSOs in a collection for the authenticated user.
     */
    public function deleteCollection(Request $request, string $collection): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (!$user) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $col = Collection::where('name', $collection)->first();
        if ($col) {
            Bso::where('user_id', $user->id)->where('collection_id', $col->id)->delete();
            UserCollection::where('user_id', $user->id)->where('collection_id', $col->id)->delete();
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * GET /api/ext/storage/info
     *
     * Return sync metadata: collection timestamps, counts, and quota.
     */
    public function getInfo(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (!$user) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $collections = UserCollection::where('user_id', $user->id)
            ->join('collections', 'collections.id', '=', 'user_collections.collection_id')
            ->pluck('user_collections.modified', 'collections.name');

        $counts = Bso::where('bso.user_id', $user->id)
            ->join('collections', 'collections.id', '=', 'bso.collection_id')
            ->selectRaw('collections.name, count(*) as cnt')
            ->groupBy('collections.name')
            ->pluck('cnt', 'name');

        $usedBytes = Bso::where('bso.user_id', $user->id)->sum('payload_size');

        return response()->json([
            'collections' => $collections,
            'counts' => $counts,
            'quota' => [
                'used' => (int) $usedBytes,
                'total' => $user->storage_quota_bytes ?? config('services.sync.default_quota_bytes', 104857600),
            ],
        ]);
    }

    /**
     * Resolve user from the Bearer token in the Authorization header.
     */
    private function resolveUser(Request $request): ?User
    {
        $token = $request->bearerToken();
        if (!$token) {
            return null;
        }

        $hashedToken = hash('sha256', $token);

        return User::where('api_token', $hashedToken)->first();
    }
}
