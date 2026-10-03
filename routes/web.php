<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Web\AuditController;
use App\Http\Controllers\Web\CollectionController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DeviceController;
use App\Http\Controllers\Web\SettingsController;
use App\Models\User;
use App\Services\SyncIdentityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Landing
Route::get('/', function () {
    if (auth()->check()) {
        return redirect('/dashboard');
    }

    return inertia('Welcome', ['localDevelopment' => Route::has('auth.local')]);
});

// Auth (Authentik OAuth)
Route::get('/auth/redirect', [AuthController::class, 'redirect'])->name('auth.redirect');
Route::get('/auth/callback', [AuthController::class, 'callback'])->name('auth.callback');
Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

if (app()->environment(['local', 'testing']) && config('services.sync.local_dev') === true
    && getenv('MIDORI_SYNC_DEV_ENV_DIR')) {
    Route::post('/auth/local', function (Request $request) {
        abort_unless(in_array($request->ip(), ['127.0.0.1', '::1'], true)
            && in_array($request->getHost(), ['localhost', '127.0.0.1', '::1'], true), 404);
        $user = User::where('authentik_id', 'midori-local-development')
            ->where('authentik_issuer', SyncIdentityService::DEVELOPMENT_ISSUER)
            ->firstOrFail();
        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/devices');
    })->name('auth.local');
}

// Authenticated web routes
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/devices', [DeviceController::class, 'index'])->name('devices.index');
    Route::post('/devices/pairing-code', [DeviceController::class, 'pairingCode'])
        ->middleware('throttle:sync-pairing-web')->name('devices.pairing-code');
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
