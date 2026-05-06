<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Services\SyncStorageService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CollectionController extends Controller
{
    public function __construct(
        private SyncStorageService $storage,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $status = $this->storage->getCollectionStatus($user->id);
        $syncInfo = $this->storage->getSyncInfo($user->id);

        $usedBytes = (int) ($syncInfo['used_bytes'] ?? 0);
        $quotaBytes = (int) ($syncInfo['quota_bytes'] ?? 0);

        $collections = Collection::all()->map(function ($collection) use ($status, $usedBytes, $quotaBytes) {
            $stat = $status[$collection->name] ?? null;
            $sizeBytes = (int) ($stat['size_bytes'] ?? 0);
            return [
                'id' => $collection->id,
                'name' => $collection->name,
                'description' => $collection->description,
                'record_count' => $stat['record_count'] ?? 0,
                'last_modified' => $stat['last_modified'] ?? null,
                'size_bytes' => $sizeBytes,
                'size_display' => $this->formatBytes($sizeBytes),
                'percent_of_used' => $usedBytes > 0
                    ? round(($sizeBytes / $usedBytes) * 100, 1)
                    : 0,
                'percent_of_quota' => $quotaBytes > 0
                    ? round(($sizeBytes / $quotaBytes) * 100, 2)
                    : 0,
            ];
        })->sortByDesc('size_bytes')->values();

        return Inertia::render('Collections/Index', [
            'collections' => $collections,
            'quota' => [
                'used_bytes' => $usedBytes,
                'quota_bytes' => $quotaBytes,
                'used_display' => $this->formatBytes($usedBytes),
                'quota_display' => $this->formatBytes($quotaBytes),
                'percent' => $quotaBytes > 0
                    ? round(($usedBytes / $quotaBytes) * 100, 2)
                    : 0,
                'free_bytes' => max(0, $quotaBytes - $usedBytes),
                'free_display' => $this->formatBytes(max(0, $quotaBytes - $usedBytes)),
            ],
        ]);
    }

    public function show(Request $request, string $name)
    {
        $user = $request->user();
        $collection = Collection::findByName($name);

        if (!$collection) {
            abort(404);
        }

        $records = $this->storage->getRecords(
            userId: $user->id,
            collectionName: $name,
            sort: 'newest',
            limit: 100,
        );

        $status = $this->storage->getCollectionStatus($user->id);
        $stat = $status[$name] ?? [];

        $sizeBytes = $stat['size_bytes'] ?? 0;
        $sizeDisplay = $this->formatBytes($sizeBytes);

        return Inertia::render('Collections/Show', [
            'collection' => $collection,
            'records' => $records,
            'meta' => [
                'total' => $stat['record_count'] ?? 0,
                'size_bytes' => $sizeBytes,
                'size_display' => $sizeDisplay,
                'last_modified' => $stat['last_modified'] ?? null,
            ],
        ]);
    }

    public function destroyRecord(Request $request, string $name, string $recordId)
    {
        $user = $request->user();
        $this->storage->deleteRecord($user->id, $name, $recordId);

        return redirect()->back();
    }

    public function destroyCollection(Request $request, string $name)
    {
        $user = $request->user();
        $this->storage->deleteCollection($user->id, $name);

        return redirect('/collections');
    }

    public function export(Request $request, string $name)
    {
        $user = $request->user();
        $collection = Collection::findByName($name);

        if (!$collection) {
            abort(404);
        }

        $records = $this->storage->getRecords(
            userId: $user->id,
            collectionName: $name,
            sort: 'newest',
        );

        $payload = [
            'exported_at' => now()->toIso8601String(),
            'user_id' => $user->id,
            'collection' => $collection->name,
            'record_count' => count($records),
            'records' => $records,
        ];

        $filename = sprintf(
            'midori-%s-%s.json',
            $collection->name,
            now()->format('Ymd-His'),
        );

        return response()->json($payload, 200, [
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ], JSON_PRETTY_PRINT);
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $val = $bytes;
        while ($val >= 1024 && $i < count($units) - 1) {
            $val /= 1024;
            $i++;
        }
        return round($val, $i > 0 ? 1 : 0) . ' ' . $units[$i];
    }
}
