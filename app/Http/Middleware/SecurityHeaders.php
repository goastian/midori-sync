<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Emits security headers for the web (Inertia) surface:
 * Content-Security-Policy, Strict-Transport-Security and a tightened
 * Referrer-Policy / Permissions-Policy.
 *
 * Skipped for `/api/*` routes (their CORS / cache semantics are
 * separate from the dashboard).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->is('api/*') || $request->is('up')) {
            return $response;
        }

        if (!$response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $this->buildCsp());
        }

        if (config('app.env') === 'production' && !$response->headers->has('Strict-Transport-Security')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), interest-cohort=()'
        );

        return $response;
    }

    private function buildCsp(): string
    {
        $authentik = (string) config('services.authentik.base_url', '');
        $extra = trim($authentik) !== '' ? ' ' . rtrim($authentik, '/') : '';

        // Vite + Inertia in production serve hashed bundles from `self`.
        // In dev (`vite dev`) we additionally allow the configured host.
        $devOrigin = '';
        if (config('app.env') !== 'production') {
            $devOrigin = " http://localhost:5173 ws://localhost:5173";
        }

        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'" . $extra,
            "img-src 'self' data: https:",
            "font-src 'self' data:",
            // 'unsafe-inline' for styles is required by Tailwind utility
            // classes injected by Inertia/Vue runtime.
            "style-src 'self' 'unsafe-inline'",
            "script-src 'self'" . $devOrigin,
            "connect-src 'self'" . $extra . $devOrigin,
        ];

        return implode('; ', $directives);
    }
}
