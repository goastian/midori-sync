<?php

use App\Http\Controllers\Api\Ext\ExtAuthController;
use App\Http\Controllers\Api\Ext\ExtPairingController;
use App\Http\Controllers\Api\Ext\ExtStorageController;
use App\Http\Controllers\Api\V1\AuthTokenController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\CryptoKeyController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\SyncInfoController;
use App\Http\Middleware\CorsForExtension;
use App\Http\Middleware\EnforceQuota;
use App\Http\Middleware\TrackDevice;
use App\Http\Middleware\ValidateSyncToken;
use Illuminate\Support\Facades\Route;

// ─── Extension API ──────────────────────────────────────────────────────
// Dedicated endpoints for the browser extension with simplified data formats.
Route::prefix('ext')->middleware(CorsForExtension::class)->group(function () {

    // Auth (unauthenticated — extension OAuth flow)
    Route::get('/auth/start', [ExtAuthController::class, 'start']);
    Route::get('/auth/poll', [ExtAuthController::class, 'poll']);

    // Pairing redeem (unauthenticated — uses pairing token)
    Route::post('/pair/redeem', [ExtPairingController::class, 'redeem']);

    // Authenticated extension endpoints
    Route::middleware([ValidateSyncToken::class, TrackDevice::class])->group(function () {

        // Logout
        Route::post('/logout', [AuthTokenController::class, 'destroy']);

        // Profile
        Route::get('/profile', function (\Illuminate\Http\Request $request) {
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
        Route::post('/pair', [ExtPairingController::class, 'generate']);

        // Storage (flat BSO array format)
        Route::middleware(EnforceQuota::class)->group(function () {
            Route::get('/storage/{collection}', [ExtStorageController::class, 'index']);
            Route::post('/storage/{collection}', [ExtStorageController::class, 'store']);
        });
    });
});

// ─── API v1 — Midori Sync Protocol ─────────────────────────────────────
Route::prefix('v1')->middleware(CorsForExtension::class)->group(function () {

    // Auth: exchange OAuth token for sync session token
    Route::post('/auth/token', [AuthTokenController::class, 'store']);

    // Authenticated sync endpoints
    Route::middleware([ValidateSyncToken::class, TrackDevice::class])->group(function () {

        // Auth
        Route::delete('/auth/token', [AuthTokenController::class, 'destroy']);

        // Sync info
        Route::get('/sync/info', [SyncInfoController::class, 'info']);
        Route::get('/sync/status', [SyncInfoController::class, 'status']);

        // Collections & Records
        Route::middleware(EnforceQuota::class)->group(function () {
            Route::get('/collections/{name}', [CollectionController::class, 'index']);
            Route::get('/collections/{name}/{id}', [CollectionController::class, 'show']);
            Route::put('/collections/{name}/{id}', [CollectionController::class, 'upsert']);
            Route::post('/collections/{name}', [CollectionController::class, 'batchUpsert']);
            Route::delete('/collections/{name}/{id}', [CollectionController::class, 'destroyRecord']);
            Route::delete('/collections/{name}', [CollectionController::class, 'destroyCollection']);
        });

        // Devices
        Route::get('/devices', [DeviceController::class, 'index']);
        Route::put('/devices/{id}', [DeviceController::class, 'upsert']);
        Route::delete('/devices/{id}', [DeviceController::class, 'destroy']);

        // Crypto key bundle
        Route::get('/crypto/keys', [CryptoKeyController::class, 'show']);
        Route::post('/crypto/keys', [CryptoKeyController::class, 'store']);
    });
});
