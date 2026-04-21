<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DeviceController extends Controller
{
    public function index(Request $request)
    {
        $devices = $request->user()
            ->devices()
            ->orderByDesc('last_sync_at')
            ->get();

        return Inertia::render('Devices/Index', [
            'devices' => $devices,
        ]);
    }

    public function destroy(Request $request, string $deviceId)
    {
        $request->user()
            ->devices()
            ->where('device_id', $deviceId)
            ->delete();

        return redirect()->back();
    }
}
