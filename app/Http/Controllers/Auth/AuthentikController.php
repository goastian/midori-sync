<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

/**
 * Handles OAuth2/OIDC authentication flow with Authentik SSO.
 */
class AuthentikController extends Controller
{
    /**
     * Redirect the user to Authentik's authorization page.
     */
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('authentik')
            ->scopes(['openid', 'profile', 'email'])
            ->redirect();
    }

    /**
     * Handle the callback from Authentik after authorization.
     */
    public function callback(): RedirectResponse
    {
        try {
            $authentikUser = Socialite::driver('authentik')->user();
        } catch (\Throwable $e) {
            return redirect()->route('landing')->with('error', 'Authentication failed. Please try again.');
        }

        $user = User::updateOrCreate(
            ['authentik_id' => $authentikUser->getId()],
            [
                'name' => $authentikUser->getName() ?? $authentikUser->getNickname() ?? 'User',
                'email' => $authentikUser->getEmail(),
                'avatar' => $authentikUser->getAvatar(),
                'email_verified_at' => now(),
            ],
        );

        Auth::login($user, remember: true);

        return redirect()->route('dashboard');
    }

    /**
     * Log the user out.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('landing');
    }
}
