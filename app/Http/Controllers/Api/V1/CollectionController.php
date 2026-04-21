<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BatchUpsertRecordRequest;
use App\Http\Requests\Api\V1\ListRecordsRequest;
use App\Http\Requests\Api\V1\UpsertRecordRequest;
use App\Services\SyncStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CollectionController extends Controller
{
    public function __construct(
        private SyncStorageService $storage,
    ) {}

    public function index(ListRecordsRequest $request, string $name): JsonResponse
    {
        $records = $this->storage->getRecords(
            userId: $request->user()->id,
            collectionName: $name,
            since: $request->float('since'),
            limit: $request->integer('limit') ?: null,
            sort: $request->input('sort', 'newest'),
            includeDeleted: $request->boolean('include_deleted'),
        );

        return response()->json([
            'records' => $records,
            'count' => count($records),
        ]);
    }

    public function show(Request $request, string $name, string $id): JsonResponse
    {
        $record = $this->storage->getRecord(
            userId: $request->user()->id,
            collectionName: $name,
            recordId: $id,
        );

        if (!$record) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        return response()->json($record);
    }

    public function upsert(UpsertRecordRequest $request, string $name, string $id): JsonResponse
    {
        try {
            $record = $this->storage->upsertRecord(
                userId: $request->user()->id,
                collectionName: $name,
                recordId: $id,
                payload: $request->input('payload'),
                ifUnmodifiedSince: $request->ifUnmodifiedSince(),
                ttl: $request->input('ttl'),
            );
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'Conflict')) {
                return response()->json(['error' => $e->getMessage()], 412);
            }
            throw $e;
        } catch (\OverflowException $e) {
            return response()->json(['error' => $e->getMessage()], 413);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }

        return response()->json($record)
            ->header('X-Last-Modified', $record['modified_at']);
    }

    public function batchUpsert(BatchUpsertRecordRequest $request, string $name): JsonResponse
    {
        try {
            $results = $this->storage->batchUpsert(
                userId: $request->user()->id,
                collectionName: $name,
                records: $request->input('records'),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }

        return response()->json([
            'results' => $results,
            'count' => count($results),
        ]);
    }

    public function destroyRecord(Request $request, string $name, string $id): JsonResponse
    {
        $deleted = $this->storage->deleteRecord(
            userId: $request->user()->id,
            collectionName: $name,
            recordId: $id,
        );

        if (!$deleted) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        return response()->json(null, 204);
    }

    public function destroyCollection(Request $request, string $name): JsonResponse
    {
        $count = $this->storage->deleteCollection(
            userId: $request->user()->id,
            collectionName: $name,
        );

        return response()->json(['deleted' => $count]);
    }
}
