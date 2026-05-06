<?php

namespace App\Http\Middleware;

use App\Models\Record;
use App\Support\SecurityLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceQuota
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!in_array($request->method(), ['PUT', 'POST'])) {
            return $next($request);
        }

        $user = $request->user();
        if (!$user) {
            return $next($request);
        }

        $currentUsage = Record::where('user_id', $user->id)
            ->where('deleted', false)
            ->sum(\DB::raw('LENGTH(payload)'));

        $incomingSize = strlen($request->getContent());

        if (($currentUsage + $incomingSize) > $user->storage_quota_bytes) {
            SecurityLog::warning(SecurityLog::EVENT_QUOTA_EXCEEDED, [
                'user_id' => $user->id,
                'used_bytes' => (int) $currentUsage,
                'incoming_bytes' => $incomingSize,
                'quota_bytes' => (int) $user->storage_quota_bytes,
            ], $request);

            return response()->json([
                'error' => 'Storage quota exceeded',
                'quota_bytes' => $user->storage_quota_bytes,
                'used_bytes' => (int) $currentUsage,
            ], 403);
        }

        return $next($request);
    }
}
