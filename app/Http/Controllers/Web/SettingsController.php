<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\SyncStorageService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function __construct(
        private SyncStorageService $storage,
    ) {}

    public function __invoke(Request $request)
    {
        $user = $request->user();
        $syncInfo = $this->storage->getSyncInfo($user->id);

        return Inertia::render('Settings', [
            'quotaUsed' => $syncInfo['used_bytes'],
            'quotaTotal' => $syncInfo['quota_bytes'],
        ]);
    }

    public function deleteAllData(Request $request)
    {
        $this->storage->deleteAllUserData($request->user()->id);

        return redirect('/settings');
    }
}
