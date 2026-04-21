<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SyncAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

class AuthController extends Controller
{
    public function redirect()
    {
        return Socialite::driver('authentik')->redirect();
    }

    public function callback(Request $request)
    {
        $state = $request->input('state');

        // Extension OAuth flow: state starts with "ext_"
        if ($state && str_starts_with($state, 'ext_')) {
            return $this->handleExtensionCallback($state);
        }

        // Normal web OAuth flow
        try {
            $socialiteUser = Socialite::driver('authentik')->user();
        } catch (InvalidStateException $e) {
            Log::warning('OAuth state mismatch; restarting flow', [
                'ip' => $request->ip(),
            ]);
            return redirect()->route('auth.redirect');
        }

        $user = User::updateOrCreate(
            ['authentik_id' => $socialiteUser->getId()],
            [
                'email' => $socialiteUser->getEmail(),
                'name' => $socialiteUser->getName(),
                'avatar_url' => $socialiteUser->getAvatar(),
            ]
        );

        Auth::login($user, remember: true);

        return redirect()->intended('/dashboard');
    }

    /**
     * Handle the OAuth callback for the browser extension.
     *
     * Uses stateless Socialite (no session state verification) since
     * the extension manages its own state via the polling mechanism.
     */
    private function handleExtensionCallback(string $state)
    {
        $cached = Cache::get("ext_auth:{$state}");

        if (!$cached || ($cached['status'] ?? null) !== 'pending') {
            return response('Invalid or expired authentication request.', 400);
        }

        $socialiteUser = Socialite::driver('authentik')->stateless()->user();

        $user = User::updateOrCreate(
            ['authentik_id' => $socialiteUser->getId()],
            [
                'email' => $socialiteUser->getEmail(),
                'name' => $socialiteUser->getName(),
                'avatar_url' => $socialiteUser->getAvatar(),
            ]
        );

        // Create or update the device
        $deviceId = $cached['device_id'] ?: Str::slug($cached['device_name']);
        $device = $user->devices()->updateOrCreate(
            ['device_id' => $deviceId],
            [
                'name' => $cached['device_name'],
                'type' => $cached['device_type'] ?? 'desktop',
            ]
        );

        // Create a sync session token
        $authService = app(SyncAuthService::class);
        $tokenData = $authService->createSessionToken(
            $user,
            $device->id,
            request()->ip(),
            request()->userAgent(),
        );

        // Store the result for the extension to poll
        Cache::put("ext_auth:{$state}", [
            'status' => 'complete',
            'token' => $tokenData['token'],
            'expires_at' => $tokenData['expires_at'],
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'avatar_url' => $user->avatar_url,
            ],
            'device' => [
                'id' => $device->id,
                'name' => $device->name,
                'type' => $device->type,
            ],
        ], now()->addMinutes(10));

        return view('auth.ext-complete');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
