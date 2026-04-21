<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\SyncStorageService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __construct(
        private SyncStorageService $storage,
    ) {}

    public function __invoke(Request $request)
    {
        $user = $request->user();
        $syncInfo = $this->storage->getSyncInfo($user->id);
        $status = $this->storage->getCollectionStatus($user->id);

        $totalRecords = collect($status)->sum('record_count');

        $devices = $user->devices()
            ->orderByDesc('last_sync_at')
            ->get();

        // Recent activity: last 10 modified records across all collections
        $recentActivity = $user->records()
            ->with('collection')
            ->orderByDesc('modified_at')
            ->limit(10)
            ->get()
            ->map(fn ($record) => [
                'id' => $record->id,
                'collection' => $record->collection->name ?? 'unknown',
                'action' => $record->deleted ? 'deleted' : ($record->version > 1 ? 'updated' : 'created'),
                'timestamp' => $record->modified_at * 1000, // Convert to JS timestamp
            ]);

        return Inertia::render('Dashboard', [
            'stats' => [
                'device_count' => $devices->count(),
                'total_records' => $totalRecords,
                'storage_used' => $syncInfo['used_bytes'],
                'storage_quota' => $syncInfo['quota_bytes'],
            ],
            'devices' => $devices,
            'recentActivity' => $recentActivity,
        ]);
    }
}
