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

        $col = Collection::firstOrCreate(['name' => $collection]);

        $bsos = Bso::where('user_id', $user->id)
            ->where('collection_id', $col->id)
            ->where(function ($q) {
                $q->whereNull('ttl')->orWhere('ttl', '>', now()->timestamp);
            })
            ->get()
            ->map(fn (Bso $bso) => [
                'id' => $bso->bso_id,
                'payload' => $bso->payload,
                'modified' => $bso->modified,
                'sortindex' => $bso->sortindex,
            ]);

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
        $now = microtime(true);
        $success = [];
        $failed = [];

        foreach ($bsos as $item) {
            $bsoId = $item['id'] ?? null;
            $payload = $item['payload'] ?? '';

            if (!$bsoId) {
                $failed[] = ['id' => $bsoId, 'error' => 'missing id'];
                continue;
            }

            try {
                DB::table('bso')->upsert(
                    [
                        'user_id' => $user->id,
                        'collection_id' => $col->id,
                        'bso_id' => $bsoId,
                        'payload' => $payload,
                        'payload_size' => strlen($payload),
                        'modified' => $now,
                        'sortindex' => $item['sortindex'] ?? 0,
                        'ttl' => isset($item['ttl']) ? now()->timestamp + $item['ttl'] : null,
                    ],
                    ['user_id', 'collection_id', 'bso_id'],
                    ['payload', 'payload_size', 'modified', 'sortindex', 'ttl']
                );
                $success[] = $bsoId;
            } catch (\Throwable $e) {
                $failed[] = ['id' => $bsoId, 'error' => $e->getMessage()];
            }
        }

        // Update collection timestamp
        UserCollection::updateOrCreate(
            ['user_id' => $user->id, 'collection_id' => $col->id],
            ['last_modified' => $now]
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
            ->pluck('user_collections.last_modified', 'collections.name');

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

        return User::where('api_token', hash('sha256', $token))->first();
    }
}
