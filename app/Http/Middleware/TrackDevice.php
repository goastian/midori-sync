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
        if ($session && $session->device) {
            $session->device->touchLastSync();
        }

        return $response;
    }
}
