<?php

namespace App\Http\Controllers\Api\Ext;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\SyncAuthService;
use App\Services\SyncStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Device management for the extension surface (/api/ext).
 *
 * Mirrors the v1 DeviceController shape but uses the same sync session
 * auth and exposes only what the extension needs: list, rename, revoke,
 * and a full server-side data wipe.
 */
class ExtDeviceController extends Controller
{
    public function __construct(
        private SyncAuthService $authService,
        private SyncStorageService $storage,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $devices = $request->user()->devices()
            ->orderByDesc('last_sync_at')
            ->get()
            ->map(fn (Device $d) => [
                'id' => $d->device_id,
                'name' => $d->name,
                'type' => $d->type,
                'os' => $d->os,
                'browser_version' => $d->browser_version,
                'last_sync_at' => $d->last_sync_at?->toIso8601String(),
                'created_at' => $d->created_at->toIso8601String(),
            ]);

        return response()->json(['devices' => $devices]);
    }

    public function rename(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $device = $request->user()->devices()
            ->where('device_id', $id)
            ->first();

        if (!$device) {
            return response()->json(['error' => 'Device not found'], 404);
        }

        $device->update(['name' => $data['name']]);

        return response()->json([
            'id' => $device->device_id,
            'name' => $device->name,
        ]);
    }

    public function revoke(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $device = $user->devices()->where('device_id', $id)->first();

        if (!$device) {
            return response()->json(['error' => 'Device not found'], 404);
        }

        // Revoke all sync sessions tied to this device, then drop the
        // device row. This invalidates any token still in use on it.
        \App\Models\SyncSession::where('user_id', $user->id)
            ->where('device_id', $device->device_id)
            ->delete();

        $device->delete();

        return response()->json(null, 204);
    }

    public function wipe(Request $request): JsonResponse
    {
        $this->storage->deleteAllUserData($request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'All server-side data deleted',
        ]);
    }
}
