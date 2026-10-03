<?php

namespace App\Exceptions;

use App\Support\SecurityLog;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncQuotaException extends \RuntimeException implements ShouldntReport
{
    public function __construct(
        public readonly int $quotaBytes,
        public readonly int $usedBytes,
        public readonly int $additionalBytes,
    ) {
        parent::__construct('Storage quota exceeded');
    }

    public function render(Request $request): JsonResponse
    {
        SecurityLog::warning(SecurityLog::EVENT_QUOTA_EXCEEDED, [
            'user_id' => $request->user()?->id,
            'used_bytes' => $this->usedBytes,
            'incoming_bytes' => $this->additionalBytes,
            'quota_bytes' => $this->quotaBytes,
        ], $request);

        return response()->json([
            'error' => $this->getMessage(),
            'quota_bytes' => $this->quotaBytes,
            'used_bytes' => $this->usedBytes,
        ], 403)->header('Cache-Control', 'no-store');
    }
}
