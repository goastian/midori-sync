<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpsertDeviceRequest;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
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

    public function upsert(UpsertDeviceRequest $request, string $id): JsonResponse
    {
        $device = $request->user()->devices()->updateOrCreate(
            ['device_id' => $id],
            [
                'name' => $request->input('name'),
                'type' => $request->input('type', 'desktop'),
                'os' => $request->input('os'),
                'browser_version' => $request->input('browser_version'),
            ]
        );

        return response()->json([
            'id' => $device->device_id,
            'name' => $device->name,
            'type' => $device->type,
            'os' => $device->os,
            'browser_version' => $device->browser_version,
            'last_sync_at' => $device->last_sync_at?->toIso8601String(),
            'created_at' => $device->created_at->toIso8601String(),
        ], 200);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $deleted = $request->user()->devices()
            ->where('device_id', $id)
            ->delete();

        if (!$deleted) {
            return response()->json(['error' => 'Device not found'], 404);
        }

        return response()->json(null, 204);
    }
}
