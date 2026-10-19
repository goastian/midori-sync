<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\SyncBrowserAuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;

class BrowserSyncController extends Controller
{
    private const SESSION_KEY = 'sync_browser_authorization';

    public function start(Request $request, SyncBrowserAuthorizationService $authorization)
    {
        abort_unless($authorization->available(), 503);
        $data = $request->validate([
            'state' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/D'],
            'code_challenge' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/D'],
            'redirect_uri' => ['required', 'string', 'max:100'],
        ]);
        abort_unless($this->validRedirect($data['redirect_uri']), 422);
        $request->session()->put(self::SESSION_KEY, $data + ['expires_at' => now()->addMinutes(10)->timestamp]);

        return redirect()->route(Auth::check() ? 'auth.desktop.confirm' : 'auth.redirect')
            ->header('Cache-Control', 'no-store');
    }

    public function confirm(Request $request): Response
    {
        if (! $this->pending($request)) {
            return $this->expired();
        }
        abort_unless(Auth::check(), 401);

        return response()->view('auth.desktop')->header('Cache-Control', 'no-store');
    }

    public function approve(Request $request, SyncBrowserAuthorizationService $authorization)
    {
        $pending = $this->pending($request);
        if (! $pending) {
            return $this->expired();
        }
        abort_unless(Auth::check(), 401);
        $code = $authorization->issue($request->user(), $pending['code_challenge']);
        $request->session()->forget(self::SESSION_KEY);
        $query = http_build_query(['state' => $pending['state'], 'code' => $code], '', '&', PHP_QUERY_RFC3986);

        return Redirect::away($pending['redirect_uri'].'?'.$query)
            ->header('Cache-Control', 'no-store');
    }

    private function expired(): Response
    {
        return response()->view('auth.desktop-expired', [], 410)->header('Cache-Control', 'no-store');
    }

    private function pending(Request $request): ?array
    {
        $pending = $request->session()->get(self::SESSION_KEY);
        if (! is_array($pending) || ! is_int($pending['expires_at'] ?? null)
            || $pending['expires_at'] <= now()->timestamp
            || ! is_string($pending['redirect_uri'] ?? null)
            || ! $this->validRedirect($pending['redirect_uri'])) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return $pending;
    }

    private function validRedirect(string $url): bool
    {
        if (! preg_match('~\Ahttp://127\.0\.0\.1:([1-9][0-9]{0,4})/midori-sync/callback\z~D', $url, $match)) {
            return false;
        }

        return (int) $match[1] <= 65535;
    }
}
