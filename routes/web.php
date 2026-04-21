<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Web\AuditController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DeviceController;
use App\Http\Controllers\Web\CollectionController;
use App\Http\Controllers\Web\SettingsController;
use Illuminate\Support\Facades\Route;

// Landing
Route::get('/', function () {
    if (auth()->check()) {
        return redirect('/dashboard');
    }
    return inertia('Welcome');
});

// Auth (Authentik OAuth)
Route::get('/auth/redirect', [AuthController::class, 'redirect'])->name('auth.redirect');
Route::get('/auth/callback', [AuthController::class, 'callback'])->name('auth.callback');
Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

// Authenticated web routes
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/devices', [DeviceController::class, 'index'])->name('devices.index');
    Route::patch('/devices/{deviceId}', [DeviceController::class, 'update'])->name('devices.update');
    Route::delete('/devices/{deviceId}', [DeviceController::class, 'destroy'])->name('devices.destroy');

    Route::get('/collections', [CollectionController::class, 'index'])->name('collections.index');
    Route::get('/collections/{name}', [CollectionController::class, 'show'])->name('collections.show');
    Route::get('/collections/{name}/export', [CollectionController::class, 'export'])->name('collections.export');
    Route::delete('/collections/{name}/{recordId}', [CollectionController::class, 'destroyRecord'])->name('collections.destroy-record');
    Route::delete('/collections/{name}', [CollectionController::class, 'destroyCollection'])->name('collections.destroy');

    Route::get('/settings', SettingsController::class)->name('settings.index');
    Route::delete('/settings/data', [SettingsController::class, 'deleteAllData'])->name('settings.destroy-data');

    Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
    Route::delete('/audit/sessions/{id}', [AuditController::class, 'revoke'])->name('audit.sessions.revoke');
    Route::delete('/audit/sessions', [AuditController::class, 'revokeAll'])->name('audit.sessions.revoke-all');
});
