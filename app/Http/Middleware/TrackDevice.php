<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
