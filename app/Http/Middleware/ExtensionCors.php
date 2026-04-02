<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * CORS middleware for the Midori Sync browser extension API.
 *
 * Allows cross-origin requests from moz-extension:// origins
 * so the extension popup and background scripts can communicate
 * with the server.
 */
class ExtensionCors
{
    /**
     * Build CORS headers if origin is allowed.
     *
     * @return array<string, string>|null
     */
    private function buildCorsHeaders(?string $origin): ?array
    {
        $configured = config('services.sync.extension_cors_origins', 'moz-extension://*,chrome-extension://*');
        $allowedOrigins = array_map('trim', explode(',', $configured));

        if (!$this->isOriginAllowed($origin, $allowedOrigins)) {
            return null;
        }

        return [
            'Access-Control-Allow-Origin' => $origin ?? '*',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, Accept, X-Requested-With, X-Request-Id',
            'Access-Control-Max-Age' => '86400',
            'Vary' => 'Origin',
        ];
    }

    /**
     * @param array<int, string> $allowedOrigins
     */
    private function isOriginAllowed(?string $origin, array $allowedOrigins): bool
    {
        if ($origin === null) {
            return true;
        }

        foreach ($allowedOrigins as $allowedOrigin) {
            if ($allowedOrigin === '*') {
                return true;
            }

            if (Str::contains($allowedOrigin, '*')) {
                $prefix = Str::before($allowedOrigin, '*');
                if (Str::startsWith($origin, $prefix)) {
                    return true;
                }
                continue;
            }

            if ($origin === $allowedOrigin) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->header('Origin');
        $corsHeaders = $this->buildCorsHeaders($origin);

        if (!$corsHeaders) {
            return response()->json(['error' => 'origin-not-allowed'], 403);
        }

        // Handle preflight OPTIONS requests
        if ($request->isMethod('OPTIONS')) {
            return response('', 204)->withHeaders($corsHeaders);
        }

        $response = $next($request);

        foreach ($corsHeaders as $key => $value) {
            $response->headers->set($key, $value);
        }

        return $response;
    }
}
