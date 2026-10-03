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

        SecurityLog::info(SecurityLog::EVENT_LOGIN_SUCCESS, [
            'user_id' => $user->id,
            'flow' => 'web',
        ], $request);

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
