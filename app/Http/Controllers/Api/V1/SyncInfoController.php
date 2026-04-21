<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SyncStorageService;
use App\Support\Http\ConditionalResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncInfoController extends Controller
{
    public function __construct(
        private SyncStorageService $storage,
    ) {}

    public function info(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $data = $this->storage->getSyncInfo($userId);

        $lastModified = $data['last_modified'];
        $etag = ConditionalResponse::etag(
            'sync-info',
            (string) $userId,
            (string) ($lastModified ?? '0'),
            (string) $data['used_bytes'],
        );

        if ($cached = ConditionalResponse::notModified($request, $etag, $lastModified)) {
            return $cached;
        }

        $response = response()->json($data)
            ->header('X-Last-Modified', $lastModified ?? '')
            ->header('Cache-Control', 'private, must-revalidate');
        $response->setEtag(trim($etag, '"'));
        if ($httpDate = ConditionalResponse::httpDate($lastModified)) {
            $response->header('Last-Modified', $httpDate);
        }
        return $response;
    }

    public function status(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $data = $this->storage->getCollectionStatus($userId);

        $maxModified = 0.0;
        foreach ($data as $row) {
            if ($row['last_modified'] > $maxModified) {
                $maxModified = (float) $row['last_modified'];
            }
        }

        $etag = ConditionalResponse::etag(
            'sync-status',
            (string) $userId,
            (string) $maxModified,
            (string) count($data),
        );

        if ($cached = ConditionalResponse::notModified($request, $etag, $maxModified ?: null)) {
            return $cached;
        }

        $response = response()->json(['collections' => $data])
            ->header('Cache-Control', 'private, must-revalidate');
        $response->setEtag(trim($etag, '"'));
        if ($maxModified > 0 && ($httpDate = ConditionalResponse::httpDate($maxModified))) {
            $response->header('Last-Modified', $httpDate);
        }
        return $response;
    }
}
