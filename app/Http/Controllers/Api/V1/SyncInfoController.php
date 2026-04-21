<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SyncStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncInfoController extends Controller
{
    public function __construct(
        private SyncStorageService $storage,
    ) {}

    public function info(Request $request): JsonResponse
    {
        $data = $this->storage->getSyncInfo($request->user()->id);

        return response()->json($data)
            ->header('X-Last-Modified', $data['last_modified'] ?? '');
    }

    public function status(Request $request): JsonResponse
    {
        $data = $this->storage->getCollectionStatus($request->user()->id);

        return response()->json(['collections' => $data]);
    }
}
