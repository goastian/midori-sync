<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AuthTokenController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\NativeCryptoController;
use App\Http\Controllers\Api\V1\NativeOidcController;
use App\Http\Controllers\Api\V1\PairingController;
use App\Http\Controllers\Api\V1\RefreshSessionController;
use App\Http\Controllers\Api\V1\SyncChangesController;
use App\Http\Controllers\Api\V1\SyncNotificationController;
use App\Http\Middleware\SyncApiCors;
use App\Http\Middleware\TrackDevice;
use App\Http\Middleware\ValidateSyncToken;
use Illuminate\Support\Facades\Route;

// ─── API v1 — Midori Sync Protocol ─────────────────────────────────────
Route::prefix('v1')->middleware(SyncApiCors::class)->group(function () {

    // Catch-all OPTIONS so preflights hit `SyncApiCors`.
    Route::options('{any}', fn () => response('', 204))->where('any', '.*');

    Route::post('/auth/native-token', [NativeOidcController::class, 'store'])->middleware('throttle:sync-unauth');
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
        Route::get('/sync/collections/{name}/changes', [SyncChangesController::class, 'index']);
        Route::post('/sync/notifications/ticket', [SyncNotificationController::class, 'ticket'])->middleware('throttle:sync');
        Route::post('/sync/collections/{name}/ack', [SyncChangesController::class, 'acknowledge']);
        Route::post('/sync/collections/{name}/operations', [SyncChangesController::class, 'operations']);
        Route::post('/sync/collections/history/clear', [SyncChangesController::class, 'clearHistory']);

        // Devices
        Route::get('/devices', [DeviceController::class, 'index']);
        Route::delete('/devices/{id}', [DeviceController::class, 'destroy']);

        // Crypto key bundle
        Route::get('/crypto/state', [NativeCryptoController::class, 'show']);
        Route::get('/crypto/recovery', [NativeCryptoController::class, 'recovery'])->middleware('throttle:sync');
        Route::post('/crypto/recovery', [NativeCryptoController::class, 'escrow'])->middleware('throttle:sync');
        Route::post('/crypto/activate', [NativeCryptoController::class, 'activate']);
        Route::post('/crypto/rotate', [NativeCryptoController::class, 'rotate']);
        Route::put('/crypto/native-keys/{keyId}', [NativeCryptoController::class, 'rewrap']);
        Route::delete('/sync/data', [NativeCryptoController::class, 'wipe']);
    });
});
