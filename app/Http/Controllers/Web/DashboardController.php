<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Record;
use App\Services\SyncStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'activity7d' => $this->buildActivityHistogram($user->id),
        ]);
    }

    /**
     * Build a 7-day activity histogram (today and the previous 6 days).
     *
     * @return array<int, array{date: string, label: string, count: int}>
     */
    private function buildActivityHistogram(int $userId): array
    {
        $tz = config('app.timezone', 'UTC');
        $endOfToday = now($tz)->endOfDay();
        $start = now($tz)->subDays(6)->startOfDay();

        $rows = Record::where('user_id', $userId)
            ->where('modified_at', '>=', $start->getTimestamp())
            ->where('modified_at', '<=', $endOfToday->getTimestamp())
            ->get(['modified_at'])
            ->groupBy(function ($record) use ($tz) {
                return \Carbon\Carbon::createFromTimestamp((float) $record->modified_at, $tz)->toDateString();
            })
            ->map->count();

        $series = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = now($tz)->subDays($i)->startOfDay();
            $key = $day->toDateString();
            $series[] = [
                'date' => $key,
                'label' => $day->format('D'),
                'count' => (int) ($rows[$key] ?? 0),
            ];
        }

        return $series;
    }
}
