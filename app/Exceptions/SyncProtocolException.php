<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;

class SyncProtocolException extends \RuntimeException implements ShouldntReport
{
    public function __construct(
        public readonly string $error,
        public readonly int $status = 400,
    ) {
        parent::__construct($error);
    }

    public function render(): JsonResponse
    {
        return response()->json(['error' => $this->error], $this->status)
            ->header('Cache-Control', 'no-store');
    }
}
