<?php

namespace App\Http\Controllers\Api\Ext;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Ext\StoreBsoRequest;
use App\Services\SyncStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExtStorageController extends Controller
{
    public function __construct(
        private SyncStorageService $storage,
    ) {}

    /**
     * GET /api/ext/storage/{collection}?newer={timestamp}
     *
     * Returns a flat array of BSOs: [{id, payload, modified}, ...]
     */
    public function index(Request $request, string $collection): JsonResponse
    {
        $records = $this->storage->getRecords(
            userId: $request->user()->id,
            collectionName: $collection,
            since: $request->float('newer') ?: null,
        );

        $bsos = array_map(fn ($r) => [
            'id' => $r['id'],
            'payload' => $r['payload'],
            'modified' => $r['modified_at'],
        ], $records);

        return response()->json($bsos);
    }

    /**
     * POST /api/ext/storage/{collection}
     *
     * Accepts a flat JSON array of BSOs: [{id, payload}, ...]
     */
    public function store(StoreBsoRequest $request, string $collection): JsonResponse
    {
        $results = $this->storage->batchUpsert(
            userId: $request->user()->id,
            collectionName: $collection,
            records: $request->validatedBsos(),
        );

        return response()->json($results);
    }
}
