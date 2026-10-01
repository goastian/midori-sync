<?php

use App\Http\Controllers\Api\Ext\ExtAuthController;
use App\Http\Controllers\Api\Ext\ExtDeviceController;
use App\Http\Controllers\Api\Ext\ExtStorageController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AuthTokenController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\CryptoKeyController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\NativeCryptoController;
use App\Http\Controllers\Api\V1\PairingController;
use App\Http\Controllers\Api\V1\RefreshSessionController;
use App\Http\Controllers\Api\V1\SyncChangesController;
use App\Http\Controllers\Api\V1\SyncInfoController;
use App\Http\Middleware\SyncApiCors;
use App\Http\Middleware\TrackDevice;
use App\Http\Middleware\ValidateSyncToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ─── Library API (servicio monetizable, billing en payments.astian.org) ──
require __DIR__.'/library.php';

// ─── Extension API ──────────────────────────────────────────────────────
// Dedicated endpoints for the browser extension with simplified data formats.
Route::prefix('ext')->middleware(SyncApiCors::class)->group(function () {

    // Catch-all OPTIONS so preflights hit `SyncApiCors` even when
    // the path matches no GET/POST/... route. Laravel/Symfony otherwise
    // auto-respond with 200+Allow and bypass route middleware.
    Route::options('{any}', fn () => response('', 204))->where('any', '.*');

    // Unauthenticated extension endpoints get their own per-IP bucket
    // so brute-forcing pairing tokens or sweeping OAuth poll states
    // cannot exhaust the authenticated read budget of the same client.
    Route::middleware('throttle:sync-unauth')->group(function () {
        // Auth (unauthenticated — extension OAuth flow)
        Route::get('/auth/start', [ExtAuthController::class, 'start']);
        Route::get('/auth/poll', [ExtAuthController::class, 'poll']);

        // Pairing redeem (unauthenticated — uses pairing token)
        Route::post('/pair/redeem', [PairingController::class, 'redeem']);
    });

    // Authenticated extension endpoints
    Route::middleware([ValidateSyncToken::class, TrackDevice::class])->group(function () {

        // Logout
        Route::post('/logout', [AuthTokenController::class, 'destroy']);

        // Profile
        Route::get('/profile', function (Request $request) {
            $user = $request->user();

            return response()->json([
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'avatar_url' => $user->avatar_url,
                'storage_quota_bytes' => $user->storage_quota_bytes,
            ]);
        });

        // Sync status (accepts GET and POST)
        Route::match(['get', 'post'], '/sync/status', [SyncInfoController::class, 'status']);

        // Storage info
        Route::get('/storage/info', [SyncInfoController::class, 'info']);

        // Device pairing (generate token)
        Route::post('/pair', [PairingController::class, 'generate']);

        // Devices (list / rename / revoke) and full wipe
        Route::get('/devices', [ExtDeviceController::class, 'index']);
        Route::patch('/devices/{id}', [ExtDeviceController::class, 'rename']);
        Route::delete('/devices/{id}', [ExtDeviceController::class, 'revoke']);
        Route::delete('/data', [ExtDeviceController::class, 'wipe']);

        // Storage (flat BSO array format)
        Route::get('/storage/{collection}', [ExtStorageController::class, 'index']);
        Route::post('/storage/{collection}', [ExtStorageController::class, 'store']);
    });
});

// ─── API v1 — Midori Sync Protocol ─────────────────────────────────────
Route::prefix('v1')->middleware(SyncApiCors::class)->group(function () {

    // Catch-all OPTIONS so preflights hit `SyncApiCors`.
    Route::options('{any}', fn () => response('', 204))->where('any', '.*');

    // Auth: exchange OAuth token for sync session token
    Route::post('/auth/token', [AuthTokenController::class, 'store']);
    Route::get('/capabilities', [SyncChangesController::class, 'capabilities'])->middleware('throttle:sync-unauth');
    Route::post('/pair/redeem', [PairingController::class, 'redeem'])->middleware('throttle:sync-unauth');
    Route::post('/auth/refresh', [RefreshSessionController::class, 'store'])->middleware('throttle:sync-unauth');
    Route::delete('/auth/refresh', [RefreshSessionController::class, 'destroy'])->middleware('throttle:sync-unauth');

    // Authenticated sync endpoints
    Route::middleware([ValidateSyncToken::class, TrackDevice::class])->group(function () {

        // Auth
        Route::delete('/auth/token', [AuthTokenController::class, 'destroy']);
        Route::get('/account', [AccountController::class, 'show']);
        Route::post('/pair', [PairingController::class, 'generate']);

        // Sync info
        Route::get('/sync/info', [SyncInfoController::class, 'info']);
        Route::get('/sync/status', [SyncInfoController::class, 'status']);
        Route::get('/sync/collections/{name}/changes', [SyncChangesController::class, 'index']);
        Route::post('/sync/collections/{name}/ack', [SyncChangesController::class, 'acknowledge']);
        Route::post('/sync/collections/{name}/operations', [SyncChangesController::class, 'operations']);
        Route::post('/sync/collections/history/clear', [SyncChangesController::class, 'clearHistory']);

        // Collections & Records
        Route::get('/collections/{name}', [CollectionController::class, 'index']);
        Route::get('/collections/{name}/{id}', [CollectionController::class, 'show']);
        Route::put('/collections/{name}/{id}', [CollectionController::class, 'upsert']);
        Route::post('/collections/{name}', [CollectionController::class, 'batchUpsert']);
        Route::delete('/collections/{name}/{id}', [CollectionController::class, 'destroyRecord']);
        Route::delete('/collections/{name}', [CollectionController::class, 'destroyCollection']);

        // Devices
        Route::get('/devices', [DeviceController::class, 'index']);
        Route::put('/devices/{id}', [DeviceController::class, 'upsert']);
        Route::delete('/devices/{id}', [DeviceController::class, 'destroy']);

        // Crypto key bundle
        Route::get('/crypto/state', [NativeCryptoController::class, 'show']);
        Route::post('/crypto/activate', [NativeCryptoController::class, 'activate']);
        Route::post('/crypto/rotate', [NativeCryptoController::class, 'rotate']);
        Route::put('/crypto/native-keys/{keyId}', [NativeCryptoController::class, 'rewrap']);
        Route::delete('/sync/data', [NativeCryptoController::class, 'wipe']);
        Route::get('/crypto/keys', [CryptoKeyController::class, 'show']);
        Route::post('/crypto/keys', [CryptoKeyController::class, 'store']);
    });
});
