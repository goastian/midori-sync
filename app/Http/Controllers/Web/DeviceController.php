<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\SyncProtocolException;
use App\Http\Controllers\Controller;
use App\Services\SyncAuthService;
use App\Services\SyncIdentityService;
use App\Services\SyncPairingService;
use App\Support\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DeviceController extends Controller
{
    public function __construct(
        private SyncAuthService $auth,
        private SyncIdentityService $identity,
        private SyncPairingService $pairing,
    ) {}

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
        $this->auth->revokeDevice($request->user(), $deviceId);

        return redirect()->back();
    }

    public function pairingCode(Request $request): JsonResponse
    {
        if (! $this->identity->configuredIssuer()) {
            throw new SyncProtocolException('server_issuer_not_configured', 503);
        }
        $this->identity->bindAuthenticatedWebUser($request->user());
        $this->identity->forUser($request->user());
        $result = $this->pairing->generate($request->user());
        SecurityLog::info(SecurityLog::EVENT_PAIRING_GENERATED, ['user_id' => $request->user()->id], $request);

        return response()->json($result)->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, string $deviceId)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:100'],
        ]);

        $request->user()
            ->devices()
            ->where('device_id', $deviceId)
            ->update(['name' => $validated['name']]);

        return redirect()->back();
    }
}
