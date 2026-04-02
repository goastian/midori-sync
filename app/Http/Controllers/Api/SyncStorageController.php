<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SyncStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Sync Storage API controller — implements the Firefox Sync 1.5 storage API.
 *
 * All BSO payloads are encrypted client-side (E2E). The server only stores
 * opaque encrypted blobs and never has access to decryption keys.
 *
 * @see https://mozilla-services.readthedocs.io/en/latest/storage/apis-1.5.html
 */
class SyncStorageController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        private readonly SyncStorageService $syncService,
    ) {}

    /**
     * GET /1.5/{uid}/info/collections
     *
     * Returns a map of collection names to last-modified timestamps.
     */
    public function getCollections(Request $request, int $uid): JsonResponse
    {
        $user = $request->user();
        $timestamps = $this->syncService->getCollectionTimestamps($user);

        return $this->syncResponse($timestamps);
    }

    /**
     * GET /1.5/{uid}/info/quota
     *
     * Returns quota usage [used, quota] in KB.
     */
    public function getQuota(Request $request, int $uid): JsonResponse
    {
        $user = $request->user();
        $quota = $this->syncService->getQuota($user);

        return $this->syncResponse($quota);
    }

    /**
     * GET /1.5/{uid}/info/collection_usage
     *
     * Returns storage usage per collection in bytes.
     */
    public function getCollectionUsage(Request $request, int $uid): JsonResponse
    {
        $user = $request->user();
        $usage = $this->syncService->getCollectionUsage($user);

        return $this->syncResponse($usage);
    }

    /**
     * GET /1.5/{uid}/info/collection_counts
     *
     * Returns item counts per collection.
     */
    public function getCollectionCounts(Request $request, int $uid): JsonResponse
    {
        $user = $request->user();
        $counts = $this->syncService->getCollectionCounts($user);

        return $this->syncResponse($counts);
    }

    /**
     * GET /1.5/{uid}/info/configuration
     *
     * Returns server-side limits and capabilities for sync clients.
     */
    public function getConfiguration(Request $request, int $uid): JsonResponse
    {
        return $this->syncResponse([
            'max_record_payload_bytes' => 262144,
            'max_post_records' => 100,
            'max_request_bytes' => 5242880,
            'max_total_records' => 1000000,
            'max_total_bytes' => 104857600,
            'implicit_collectionname' => false,
            'gzip' => true,
        ]);
    }

    /**
     * GET /1.5/{uid}/storage/{collection}
     *
     * Returns BSOs from a collection, with optional filtering and pagination.
     */
    public function getBsos(Request $request, int $uid, string $collection): JsonResponse
    {
        $user = $request->user();
        $params = $request->only(['ids', 'newer', 'older', 'sort', 'limit', 'offset', 'full']);

        // Handle conditional request: X-If-Modified-Since
        $ifModifiedSince = $request->header('X-If-Modified-Since');
        if ($ifModifiedSince !== null) {
            $collectionTimestamps = $this->syncService->getCollectionTimestamps($user);
            $lastModified = $collectionTimestamps[$collection] ?? 0;
            if ($lastModified <= (float) $ifModifiedSince) {
                return response()->json(null, 304)
                    ->withHeaders($this->syncHeaders());
            }
        }

        $result = $this->syncService->getBsos($user, $collection, $params);

        $headers = $this->syncHeaders();
        if (isset($result['items']) && is_array($result['items'])) {
            $headers['X-Weave-Records'] = (string) count($result['items']);
        }
        if ($result['offset'] !== null) {
            $headers['X-Weave-Next-Offset'] = $result['offset'];
        }

        return response()->json($result['items'])
            ->withHeaders($headers);
    }

    /**
     * GET /1.5/{uid}/storage/{collection}/{id}
     *
     * Returns a single BSO by ID.
     */
    public function getBso(Request $request, int $uid, string $collection, string $id): JsonResponse
    {
        $user = $request->user();
        $bso = $this->syncService->getBso($user, $collection, $id);

        if (!$bso) {
            return response()->json(null, 404)
                ->withHeaders($this->syncHeaders());
        }

        return $this->syncResponse($bso);
    }

    /**
     * PUT /1.5/{uid}/storage/{collection}/{id}
     *
     * Creates or updates a single BSO.
     */
    public function putBso(Request $request, int $uid, string $collection, string $id): JsonResponse
    {
        $user = $request->user();

        // Handle conditional request: X-If-Unmodified-Since
        $ifUnmodifiedSince = $request->header('X-If-Unmodified-Since');
        if ($ifUnmodifiedSince !== null) {
            $existing = $this->syncService->getBso($user, $collection, $id);
            if ($existing && $existing['modified'] > (float) $ifUnmodifiedSince) {
                return response()->json(null, 412)
                    ->withHeaders($this->syncHeaders());
            }
        }

        $data = $request->json()->all();
        $modified = $this->syncService->putBso($user, $collection, $id, $data);

        return response()->json($modified)
            ->withHeaders(array_merge($this->syncHeaders(), [
                'X-Last-Modified-Version' => $modified,
            ]));
    }

    /**
     * POST /1.5/{uid}/storage/{collection}
     *
     * Batch upload of BSOs to a collection.
     */
    public function postBsos(Request $request, int $uid, string $collection): JsonResponse
    {
        $user = $request->user();

        // Handle conditional request: X-If-Unmodified-Since
        $ifUnmodifiedSince = $request->header('X-If-Unmodified-Since');
        if ($ifUnmodifiedSince !== null) {
            $collectionTimestamps = $this->syncService->getCollectionTimestamps($user);
            $lastModified = $collectionTimestamps[$collection] ?? 0;
            if ($lastModified > (float) $ifUnmodifiedSince) {
                return response()->json(null, 412)
                    ->withHeaders($this->syncHeaders());
            }
        }

        $bsos = $request->json()->all();

        if (count($bsos) > 100) {
            return response()->json([
                'error' => 'batch-size-exceeded',
                'message' => 'Maximum 100 records per request',
            ], 400)->withHeaders($this->syncHeaders());
        }

        $result = $this->syncService->postBsos($user, $collection, $bsos);

        Log::info('sync_post_collection_completed', [
            'user_id' => $user->id,
            'collection' => $collection,
            'success_count' => count($result['success']),
            'failed_count' => count($result['failed']),
        ]);

        return response()->json($result)
            ->withHeaders(array_merge($this->syncHeaders(), [
                'X-Last-Modified-Version' => $result['modified'],
            ]));
    }

    /**
     * DELETE /1.5/{uid}/storage/{collection}
     *
     * Deletes BSOs from a collection (optionally filtered by IDs).
     */
    public function deleteCollection(Request $request, int $uid, string $collection): JsonResponse
    {
        $user = $request->user();
        $params = $request->only(['ids']);
        $modified = $this->syncService->deleteBsos($user, $collection, $params);

        return response()->json($modified)
            ->withHeaders(array_merge($this->syncHeaders(), [
                'X-Last-Modified-Version' => $modified,
            ]));
    }

    /**
     * DELETE /1.5/{uid}/storage/{collection}/{id}
     *
     * Deletes a single BSO.
     */
    public function deleteBso(Request $request, int $uid, string $collection, string $id): JsonResponse
    {
        $user = $request->user();
        $modified = $this->syncService->deleteBso($user, $collection, $id);

        return response()->json($modified)
            ->withHeaders(array_merge($this->syncHeaders(), [
                'X-Last-Modified-Version' => $modified,
            ]));
    }

    /**
     * DELETE /1.5/{uid}
     *
     * Deletes all sync data for the user.
     */
    public function deleteAll(Request $request, int $uid): JsonResponse
    {
        $user = $request->user();
        $modified = $this->syncService->deleteAllUserData($user);

        return response()->json($modified)
            ->withHeaders(array_merge($this->syncHeaders(), [
                'X-Last-Modified-Version' => $modified,
            ]));
    }

    /**
     * Build a standard sync JSON response with required headers.
     */
    private function syncResponse(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)
            ->withHeaders($this->syncHeaders());
    }

    /**
     * Get the standard sync response headers.
     *
     * @return array<string, string>
     */
    private function syncHeaders(): array
    {
        return [
            'X-Weave-Timestamp' => round(microtime(true), 2),
            'X-Last-Modified' => (string) round(microtime(true) * 1000),
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ];
    }
}
