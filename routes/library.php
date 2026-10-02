<?php

use App\Http\Controllers\Api\V1\Library\BillingController;
use App\Http\Controllers\Api\V1\Library\HighlightController;
use App\Http\Controllers\Api\V1\Library\LibraryCollectionController;
use App\Http\Controllers\Api\V1\Library\LinkController;
use App\Http\Controllers\Api\V1\Library\NativeCollectionsController;
use App\Http\Controllers\Api\V1\Library\NativeLinkMutationController;
use App\Http\Controllers\Api\V1\Library\SaveController;
use App\Http\Controllers\Api\V1\Library\ShareController;
use App\Http\Controllers\Api\V1\Library\TagController;
use App\Http\Middleware\CheckLibraryEntitlement;
use App\Http\Middleware\SyncApiCors;
use App\Http\Middleware\TrackDevice;
use App\Http\Middleware\ValidateSyncToken;
use Illuminate\Support\Facades\Route;

// Public share (no auth).
Route::get('/library/s/{token}', [ShareController::class, 'public']);

// payments.astian.org webhook (HMAC-signed, no user auth, own throttle).
Route::post('/library/billing/webhook', [BillingController::class, 'webhook'])
    ->middleware('throttle:sync-unauth');

Route::prefix('library')->middleware([SyncApiCors::class, ValidateSyncToken::class, TrackDevice::class])->group(function () {
    Route::get('/entitlement', [BillingController::class, 'entitlement']);
    Route::get('/upgrade', [BillingController::class, 'upgrade']);

    Route::post('/v1/saves', [SaveController::class, 'store']);
    Route::get('/v1/collections', [NativeCollectionsController::class, 'index']);
    Route::get('/v1/changes', [LinkController::class, 'changes']);
    Route::get('/v1/links', [LinkController::class, 'browse']);
    Route::patch('/v1/links/{id}', [NativeLinkMutationController::class, 'update']);
    Route::delete('/v1/links/{id}', [NativeLinkMutationController::class, 'destroy']);

    Route::get('/links/export', [LinkController::class, 'export']);
    Route::get('/links', [LinkController::class, 'index']);
    Route::post('/links', [LinkController::class, 'store']);
    Route::post('/links/import', [LinkController::class, 'import']);
    Route::post('/links/bulk', [LinkController::class, 'bulk']);
    Route::get('/links/{id}', [LinkController::class, 'show']);
    Route::patch('/links/{id}', [LinkController::class, 'update']);
    Route::delete('/links/{id}', [LinkController::class, 'destroy']);
    Route::post('/links/{id}/preserve', [LinkController::class, 'preserve'])->middleware(CheckLibraryEntitlement::class.':snapshots');

    Route::get('/tags', [TagController::class, 'index']);
    Route::post('/tags', [TagController::class, 'store']);
    Route::delete('/tags/{id}', [TagController::class, 'destroy']);

    Route::get('/collections', [LibraryCollectionController::class, 'index']);
    Route::post('/collections', [LibraryCollectionController::class, 'store']);
    Route::patch('/collections/{id}', [LibraryCollectionController::class, 'update']);
    Route::delete('/collections/{id}', [LibraryCollectionController::class, 'destroy']);

    Route::get('/links/{linkId}/highlights', [HighlightController::class, 'index']);
    Route::post('/links/{linkId}/highlights', [HighlightController::class, 'store']);
    Route::delete('/links/{linkId}/highlights/{id}', [HighlightController::class, 'destroy']);

    Route::post('/links/{linkId}/shares', [ShareController::class, 'store']);
    Route::delete('/links/{linkId}/shares/{id}', [ShareController::class, 'destroy']);
});
