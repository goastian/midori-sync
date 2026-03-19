<?php

use App\Http\Controllers\Api\ExtensionAuthController;
use App\Http\Controllers\Auth\AuthentikController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\LandingController;
use Illuminate\Support\Facades\Route;

// Public landing page
Route::get('/', [LandingController::class, 'index'])->name('landing');

// Authentik OAuth routes
Route::prefix('auth/authentik')->group(function () {
    Route::get('/redirect', [AuthentikController::class, 'redirect'])->name('auth.redirect');
    Route::get('/callback', [AuthentikController::class, 'callback'])->name('auth.callback');
});

Route::post('/logout', [AuthentikController::class, 'logout'])->name('logout')->middleware('auth');

// Extension OAuth callback (Authentik redirects here after login)
Route::get('/ext/auth/callback', [ExtensionAuthController::class, 'authCallback'])->name('ext.auth.callback');

// Authenticated user panel
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/devices', [DashboardController::class, 'devices'])->name('devices');
    Route::delete('/devices/{deviceId}', [DashboardController::class, 'removeDevice'])->name('devices.remove');
    Route::get('/settings', [DashboardController::class, 'settings'])->name('settings');
    Route::delete('/sync/data', [DashboardController::class, 'deleteAllData'])->name('sync.delete-all');
});
