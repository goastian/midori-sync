<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Negotiates `Content-Encoding: gzip` with the client when the response
 * payload is large enough to be worth compressing.
 *
 * Disabled by default: in typical Docker deployments nginx already
 * gzips PHP-FPM upstream responses, and running this middleware on top
 * would either double-compress or waste CPU. Set `SYNC_HTTP_COMPRESSION=true`
 * to opt in (useful when deploying behind a proxy that does not compress,
 * or during local development).
 *
 * Only compresses when:
 *   - The feature is enabled via config.
 *   - The client sent `Accept-Encoding: gzip`.
 *   - The response does not already have `Content-Encoding` set.
 *   - The response is not already a 304 / 204 / empty body.
 *   - The response body is at least `http_compression_min_bytes` long.
 */
class NegotiateCompression
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!config('services.sync.http_compression')) {
            return $response;
        }

        $accept = $request->header('Accept-Encoding', '');
        if (!str_contains(strtolower((string) $accept), 'gzip')) {
            return $response;
        }

        if ($response->headers->has('Content-Encoding')) {
            return $response;
        }

        $status = $response->getStatusCode();
        if ($status === 204 || $status === 304) {
            return $response;
        }

        $body = (string) $response->getContent();
        $min = (int) config('services.sync.http_compression_min_bytes', 1024);
        if (strlen($body) < $min) {
            return $response;
        }

        $compressed = gzencode($body, 6);
        if ($compressed === false) {
            return $response;
        }

        $response->setContent($compressed);
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Content-Length', (string) strlen($compressed));
        // Make caches aware that representation varies by Accept-Encoding.
        $vary = $response->headers->get('Vary');
        $response->headers->set(
            'Vary',
            $vary ? $vary . ', Accept-Encoding' : 'Accept-Encoding',
        );

        return $response;
    }
}
