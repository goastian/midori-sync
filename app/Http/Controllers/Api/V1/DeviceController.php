<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\SyncAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function __construct(private SyncAuthService $auth) {}

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

    public function destroy(Request $request, string $id): JsonResponse
    {
        $deleted = $this->auth->revokeDevice($request->user(), $id);

        if ($deleted === null) {
            return response()->json(['error' => 'Device not found'], 404);
        }

        return response()->json(null, 204);
    }
}
