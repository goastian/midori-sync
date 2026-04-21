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

        $collections = Collection::all()->map(function ($collection) use ($status) {
            $stat = $status[$collection->name] ?? null;
            return [
                'id' => $collection->id,
                'name' => $collection->name,
                'description' => $collection->description,
                'record_count' => $stat['record_count'] ?? 0,
                'last_modified' => $stat['last_modified'] ?? null,
            ];
        });

        return Inertia::render('Collections/Index', [
            'collections' => $collections,
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
