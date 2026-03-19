<?php

use App\Http\Controllers\Api\ExtensionAuthController;
use App\Http\Controllers\Api\ExtensionSyncController;
use App\Http\Controllers\Api\SyncStorageController;
use App\Http\Controllers\Api\TokenServerController;
use App\Http\Middleware\ExtensionCors;
use App\Http\Middleware\HawkAuthentication;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Firefox Sync 1.5 Compatible API Routes
|--------------------------------------------------------------------------
|
| TokenServer: Exchanges Authentik Bearer tokens for Hawk credentials.
| Sync Storage: Stores/retrieves encrypted BSOs using Hawk authentication.
|
*/

// TokenServer endpoint — accepts Authentik Bearer token, returns Hawk credentials
Route::get('/1.0/sync/1.5', [TokenServerController::class, 'getToken']);

// Heartbeat endpoint for health checks
Route::get('/__heartbeat__', fn () => response()->json(['status' => 'ok']));
Route::get('/__lbheartbeat__', fn () => response()->json(null, 200));

/*
|--------------------------------------------------------------------------
| Extension API Routes
|--------------------------------------------------------------------------
|
| Authentication and device management endpoints for the Midori Sync
| browser extension. Uses Bearer token auth (api_token).
|
*/

Route::prefix('ext')->middleware(ExtensionCors::class)->group(function () {
    // OAuth2 Authorization Code flow for extension
    // (callback is a web route at /ext/auth/callback — see routes/web.php)
    Route::get('/auth/start', [ExtensionAuthController::class, 'authStart']);
    Route::get('/auth/poll', [ExtensionAuthController::class, 'authPoll']);
    Route::post('/logout', [ExtensionAuthController::class, 'logout']);
    Route::get('/profile', [ExtensionAuthController::class, 'profile']);
    Route::post('/pair', [ExtensionAuthController::class, 'generatePairingToken']);
    Route::post('/pair/redeem', [ExtensionAuthController::class, 'redeemPairingToken']);
    Route::post('/sync/status', [ExtensionAuthController::class, 'updateSyncStatus']);

    // Simplified sync storage (Bearer token auth)
    Route::get('/storage/info', [ExtensionSyncController::class, 'getInfo']);
    Route::get('/storage/{collection}', [ExtensionSyncController::class, 'getCollection']);
    Route::post('/storage/{collection}', [ExtensionSyncController::class, 'postCollection']);
    Route::delete('/storage/{collection}', [ExtensionSyncController::class, 'deleteCollection']);
});

// Sync Storage 1.5 API — protected by Hawk authentication
Route::prefix('1.5/{uid}')->middleware(HawkAuthentication::class)->group(function () {
    // Info endpoints
    Route::get('/info/collections', [SyncStorageController::class, 'getCollections']);
    Route::get('/info/quota', [SyncStorageController::class, 'getQuota']);
    Route::get('/info/collection_usage', [SyncStorageController::class, 'getCollectionUsage']);
    Route::get('/info/collection_counts', [SyncStorageController::class, 'getCollectionCounts']);

    // Storage endpoints
    Route::get('/storage/{collection}', [SyncStorageController::class, 'getBsos']);
    Route::get('/storage/{collection}/{id}', [SyncStorageController::class, 'getBso']);
    Route::put('/storage/{collection}/{id}', [SyncStorageController::class, 'putBso']);
    Route::post('/storage/{collection}', [SyncStorageController::class, 'postBsos']);
    Route::delete('/storage/{collection}', [SyncStorageController::class, 'deleteCollection']);
    Route::delete('/storage/{collection}/{id}', [SyncStorageController::class, 'deleteBso']);

    // Delete all user data
    Route::delete('/', [SyncStorageController::class, 'deleteAll']);
});
