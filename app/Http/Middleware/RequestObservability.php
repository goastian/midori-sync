<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestObservability
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header('X-Request-Id', (string) Str::uuid());
        $request->attributes->set('request_id', $requestId);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $start = hrtime(true);
        $response = $next($request);
        $durationMs = round((hrtime(true) - $start) / 1000000, 2);

        $queryLog = DB::getQueryLog();
        DB::disableQueryLog();

        $queryCount = count($queryLog);
        $queryDurationMs = round(array_sum(array_column($queryLog, 'time')), 2);
        $responseSize = strlen((string) $response->getContent());

        $endpoint = $this->resolveEndpoint($request);
        $status = $response->getStatusCode();

        $this->recordMetrics($endpoint, $status, $durationMs, $queryCount, $responseSize);

        Log::info('http_request', [
            'request_id' => $requestId,
            'method' => $request->method(),
            'endpoint' => $endpoint,
            'path' => $request->path(),
            'status' => $status,
            'duration_ms' => $durationMs,
            'query_count' => $queryCount,
            'query_duration_ms' => $queryDurationMs,
            'response_bytes' => $responseSize,
            'user_id' => optional($request->user())->id,
            'ip' => $request->ip(),
        ]);

        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }

    private function resolveEndpoint(Request $request): string
    {
        if ($request->route() && $request->route()->uri()) {
            return $request->route()->uri();
        }

        return $request->path();
    }

    private function recordMetrics(string $endpoint, int $status, float $durationMs, int $queryCount, int $responseSize): void
    {
        $prefix = 'metrics:endpoint:' . $endpoint;

        Cache::increment($prefix . ':count');
        Cache::increment($prefix . ':duration_total_ms', (int) round($durationMs));
        Cache::increment($prefix . ':queries_total', $queryCount);
        Cache::increment($prefix . ':bytes_total', $responseSize);

        if ($status >= 400) {
            Cache::increment($prefix . ':errors');
        }

        $bucket = $this->durationBucket($durationMs);
        Cache::increment($prefix . ':hist:' . $bucket);

        $endpoints = Cache::get('metrics:endpoints', []);
        if (!in_array($endpoint, $endpoints, true)) {
            $endpoints[] = $endpoint;
            Cache::put('metrics:endpoints', $endpoints, now()->addDays(30));
        }
    }

    private function durationBucket(float $durationMs): string
    {
        if ($durationMs <= 50) {
            return '<=50';
        }
        if ($durationMs <= 100) {
            return '<=100';
        }
        if ($durationMs <= 250) {
            return '<=250';
        }
        if ($durationMs <= 500) {
            return '<=500';
        }
        if ($durationMs <= 1000) {
            return '<=1000';
        }

        return '>1000';
    }
}
