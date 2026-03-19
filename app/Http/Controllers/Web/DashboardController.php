<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\SyncStorageService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Web dashboard controller for the Midori Sync user panel.
 */
class DashboardController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        private readonly SyncStorageService $syncService,
    ) {}

    /**
     * Display the user dashboard.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $collectionCounts = $this->syncService->getCollectionCounts($user);
        $collectionUsage = $this->syncService->getCollectionUsage($user);
        $quota = $this->syncService->getQuota($user);

        return Inertia::render('Dashboard', [
            'stats' => [
                'collections' => $collectionCounts,
                'usage' => $collectionUsage,
                'quota' => [
                    'used' => $quota[0],
                    'total' => $quota[1],
                ],
                'devices' => $user->devices()->count(),
                'totalItems' => array_sum($collectionCounts),
            ],
        ]);
    }

    /**
     * Display the devices management page.
     */
    public function devices(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Devices', [
            'devices' => $user->devices()
                ->orderBy('last_sync_at', 'desc')
                ->get(),
        ]);
    }

    /**
     * Remove a connected device.
     */
    public function removeDevice(Request $request, int $deviceId): \Illuminate\Http\RedirectResponse
    {
        $request->user()->devices()->where('id', $deviceId)->delete();

        return back()->with('success', 'Device removed successfully.');
    }

    /**
     * Display the settings page with browser configuration instructions.
     */
    public function settings(Request $request): Response
    {
        $user = $request->user();
        $syncUrl = rtrim(config('app.url'), '/');

        return Inertia::render('Settings', [
            'syncConfig' => [
                'tokenServerUrl' => $syncUrl . '/1.0/sync/1.5',
                'storageUrl' => $syncUrl . '/1.5/' . $user->id,
                'authentikUrl' => config('services.authentik.base_url'),
            ],
        ]);
    }

    /**
     * Delete all sync data for the authenticated user.
     */
    public function deleteAllData(Request $request): \Illuminate\Http\RedirectResponse
    {
        $user = $request->user();
        $this->syncService->deleteAllUserData($user);
        $user->devices()->delete();

        return back()->with('success', 'All sync data has been deleted.');
    }
}
