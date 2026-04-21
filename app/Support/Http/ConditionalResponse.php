<?php

namespace App\Support\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Helper for emitting and honoring `ETag` and `If-Modified-Since` on
 * JSON read endpoints. Keeps the controller code small and the semantics
 * consistent across routes.
 *
 * Usage:
 *
 *   $etag = ConditionalResponse::etag($request, 'sync-info', $userId, $lastModified);
 *   if ($response = ConditionalResponse::notModified($request, $etag, $lastModified)) {
 *       return $response;
 *   }
 *   return response()->json($payload)
 *       ->setEtag($etag)
 *       ->header('Last-Modified', ConditionalResponse::httpDate($lastModified))
 *       ->header('Cache-Control', 'private, must-revalidate');
 */
final class ConditionalResponse
{
    /**
     * Deterministic ETag from a set of scalar identifying fields. We hash
     * so the returned value is a valid ETag token and does not leak raw
     * user IDs.
     */
    public static function etag(string ...$parts): string
    {
        return '"' . substr(hash('sha256', implode('|', $parts)), 0, 32) . '"';
    }

    /**
     * Formats a unix-seconds timestamp (int or float) as an HTTP-date
     * suitable for the `Last-Modified` header. Returns null if the
     * timestamp is missing so callers can skip the header.
     */
    public static function httpDate(null|int|float $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        return gmdate('D, d M Y H:i:s', (int) $timestamp) . ' GMT';
    }

    /**
     * Returns a 304 response if the client already has the current
     * version, or null if the controller should continue and emit a full
     * body.
     *
     * Honors `If-None-Match` (strict equality, handles weak tags and
     * comma-separated lists) and `If-Modified-Since` (second-precision).
     */
    public static function notModified(
        Request $request,
        string $etag,
        null|int|float $lastModified = null,
    ): ?JsonResponse {
        $ifNoneMatch = $request->header('If-None-Match');
        if ($ifNoneMatch) {
            foreach (array_map('trim', explode(',', $ifNoneMatch)) as $candidate) {
                // Tolerate weak-tag prefix `W/"..."` from proxies.
                if (str_starts_with($candidate, 'W/')) {
                    $candidate = substr($candidate, 2);
                }
                if ($candidate === $etag || $candidate === '*') {
                    return self::respond304($etag, $lastModified);
                }
            }
        }

        $ifModifiedSince = $request->header('If-Modified-Since');
        if ($ifModifiedSince && $lastModified !== null) {
            $since = strtotime($ifModifiedSince);
            if ($since !== false && (int) $lastModified <= $since) {
                return self::respond304($etag, $lastModified);
            }
        }

        return null;
    }

    private static function respond304(string $etag, null|int|float $lastModified): JsonResponse
    {
        $response = new JsonResponse(null, 304);
        $response->setEtag(trim($etag, '"'));
        $response->headers->set('Cache-Control', 'private, must-revalidate');
        if ($lastModified !== null) {
            $response->headers->set('Last-Modified', self::httpDate($lastModified));
        }
        return $response;
    }
}
