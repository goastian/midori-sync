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

        $rangeParam = (int) $request->query('range', 7);
        $rangeDays = in_array($rangeParam, [7, 30], true) ? $rangeParam : 7;

        return Inertia::render('Dashboard', [
            'stats' => [
                'device_count' => $devices->count(),
                'total_records' => $totalRecords,
                'storage_used' => $syncInfo['used_bytes'],
                'storage_quota' => $syncInfo['quota_bytes'],
            ],
            'devices' => $devices,
            'recentActivity' => $recentActivity,
            'activitySeries' => $this->buildActivityHistogram($user->id, $rangeDays),
            'activityRange' => $rangeDays,
        ]);
    }

    /**
     * Build a daily activity histogram for the last N days (today and the previous N-1 days).
     *
     * @return array<int, array{date: string, label: string, count: int}>
     */
    private function buildActivityHistogram(int $userId, int $days): array
    {
        $tz = config('app.timezone', 'UTC');
        $endOfToday = now($tz)->endOfDay();
        $start = now($tz)->subDays($days - 1)->startOfDay();

        $rows = Record::where('user_id', $userId)
            ->where('modified_at', '>=', $start->getTimestamp())
            ->where('modified_at', '<=', $endOfToday->getTimestamp())
            ->get(['modified_at'])
            ->groupBy(function ($record) use ($tz) {
                return \Carbon\Carbon::createFromTimestamp((float) $record->modified_at, $tz)->toDateString();
            })
            ->map->count();

        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = now($tz)->subDays($i)->startOfDay();
            $key = $day->toDateString();
            // For 30-day view, show only every ~5th day label to avoid crowding
            $label = $days > 7
                ? ($i % 5 === 0 ? $day->format('M j') : '')
                : $day->format('D');
            $series[] = [
                'date' => $key,
                'label' => $label,
                'count' => (int) ($rows[$key] ?? 0),
            ];
        }

        return $series;
    }
}
