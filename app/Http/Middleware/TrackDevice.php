<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Updates "last seen" bookkeeping on every authenticated sync request.
 *
 * Runs AFTER the inner handler so we do not touch audit columns for
 * requests that end up rejected by validation or quota middleware. It:
 *
 *  - Bumps `devices.last_sync_at` for the device tied to the current
 *    sync session (used by the dashboard to show activity).
 *  - Bumps `sync_sessions.last_used_at` so idle revocation / auditing
 *    can distinguish dormant tokens from active ones.
 *
 * Expects `ValidateSyncToken` to have populated `sync_session` on the
 * request. If it has not (e.g. the route is misconfigured), this
 * middleware is a no-op — it never throws or rewrites the response.
 */
class TrackDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $session = $request->get('sync_session');
        if ($session) {
            if ($session->device) {
                $session->device->touchLastSync();
            }
            $session->forceFill(['last_used_at' => now()])->save();
        }

        return $response;
    }
}
