<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SyncProtocolException;
use App\Http\Controllers\Controller;
use App\Services\SyncRefreshService;
use App\Support\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RefreshSessionController extends Controller
{
    public function __construct(private SyncRefreshService $refresh) {}

    public function store(Request $request): JsonResponse
    {
        $data = $this->input($request, true);
        $result = $this->refresh->rotate($data['refresh_token'], $data['operation_id']);
        if (isset($result['error'])) {
            if ($result['error'] === 'refresh_reused') {
                SecurityLog::warning(SecurityLog::EVENT_REFRESH_REUSED, [], $request);
            }
            $response = response()->json(['error' => $result['error']], $result['status'])->header('Cache-Control', 'no-store');
            if (isset($result['retry_after'])) {
                $response->header('Retry-After', $result['retry_after']);
            }

            return $response;
        }

        return response()->json($result)->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request): Response
    {
        $data = $this->input($request, false);
        $this->refresh->revoke($data['refresh_token']);
        SecurityLog::info(SecurityLog::EVENT_TOKEN_REVOKED, ['flow' => 'native_refresh'], $request);

        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    private function input(Request $request, bool $rotation): array
    {
        $body = $request->getContent();
        if (strlen($body) > SyncRefreshService::MAX_REQUEST_BYTES) {
            throw new SyncProtocolException('request_too_large', 413);
        }
        if (! $request->isJson()) {
            throw new SyncProtocolException('invalid_refresh_request', 422);
        }
        try {
            $data = json_decode($body, false, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new SyncProtocolException('invalid_refresh_request', 422);
        }
        if (! $data instanceof \stdClass) {
            throw new SyncProtocolException('invalid_refresh_request', 422);
        }
        $data = get_object_vars($data);
        $fields = $rotation ? ['refresh_token', 'operation_id'] : ['refresh_token'];
        if (array_diff(array_keys($data), $fields) ||
            ! is_string($data['refresh_token'] ?? null) || ! preg_match('/^mrf_[0-9a-f]{64}$/D', $data['refresh_token']) ||
            ($rotation && (! is_string($data['operation_id'] ?? null) ||
                ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $data['operation_id'])))) {
            throw new SyncProtocolException('invalid_refresh_request', 422);
        }

        return $data;
    }
}
