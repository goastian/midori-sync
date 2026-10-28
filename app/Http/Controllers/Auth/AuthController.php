<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SyncIdentityService;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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
        try {
            $socialiteUser = Socialite::driver('authentik')->user();
        } catch (InvalidStateException $e) {
            Log::warning('OAuth state mismatch; restarting flow', [
                'ip' => $request->ip(),
            ]);
            SecurityLog::warning(SecurityLog::EVENT_LOGIN_FAILED, [
                'reason' => 'state_mismatch',
            ], $request);

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

        app(SyncIdentityService::class)->bindAuthenticatedWebUser($user, freshLogin: true);

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        SecurityLog::info(SecurityLog::EVENT_LOGIN_SUCCESS, [
            'user_id' => $user->id,
            'flow' => 'web',
        ], $request);

        if ($request->session()->has('sync_browser_authorization')) {
            $request->session()->put('sync_browser_authorization.authenticated_user_id', (string) $user->id);

            return redirect()->route('auth.desktop.confirm');
        }

        return redirect()->intended('/dashboard');
    }

    public function logout(Request $request)
    {
        $userId = Auth::id();
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        SecurityLog::info(SecurityLog::EVENT_LOGOUT, [
            'user_id' => $userId,
            'flow' => 'web',
        ], $request);

        return redirect('/');
    }
}
