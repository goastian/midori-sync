<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CORS policy for `/api/ext` and `/api/v1`.
 *
 * Echoes back the request `Origin` only if it appears in the configured
 * allow-list (`config('cors.allowed_origins')`) or matches one of the
 * configured regex patterns (`allowed_origin_patterns`). Origins outside
 * the allow-list receive no CORS headers, which causes the browser to
 * block the response. Preflights (`OPTIONS`) short-circuit with 204.
 */
class CorsForExtension
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');
        $isAllowed = $origin !== null && $this->isAllowedOrigin($origin);

        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);
        } else {
            $response = $next($request);
        }

        if ($isAllowed) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set(
                'Vary',
                $this->mergeVary($response->headers->get('Vary'), 'Origin')
            );
            $response->headers->set(
                'Access-Control-Allow-Methods',
                implode(', ', (array) config('cors.allowed_methods', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']))
            );
            $response->headers->set(
                'Access-Control-Allow-Headers',
                implode(', ', (array) config('cors.allowed_headers', []))
            );
            $response->headers->set(
                'Access-Control-Expose-Headers',
                implode(', ', (array) config('cors.exposed_headers', []))
            );
            $response->headers->set('Access-Control-Max-Age', (string) config('cors.max_age', 86400));

            if (config('cors.allow_credentials')) {
                $response->headers->set('Access-Control-Allow-Credentials', 'true');
            }
        }

        return $response;
    }

    private function isAllowedOrigin(string $origin): bool
    {
        $allowed = (array) config('cors.allowed_origins', []);
        if (in_array($origin, $allowed, true)) {
            return true;
        }

        foreach ((array) config('cors.allowed_origin_patterns', []) as $pattern) {
            if ($pattern === '') {
                continue;
            }
            if (@preg_match('#' . $pattern . '#', $origin) === 1) {
                return true;
            }
        }

        return false;
    }

    private function mergeVary(?string $current, string $value): string
    {
        if (!$current) {
            return $value;
        }
        $parts = array_map('trim', explode(',', $current));
        if (!in_array($value, $parts, true)) {
            $parts[] = $value;
        }
        return implode(', ', $parts);
    }
}
