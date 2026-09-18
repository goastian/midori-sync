<?php

namespace App\Services\Library;

use App\Models\Library\BillingCache;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * payments.astian.org es la fuente de verdad.
 * Library solo cachea entitlements (Redis TTL + tabla billing_cache).
 * Fallback: lecturas abiertas, escrituras con último plan conocido → free.
 */
class EntitlementService
{
    public function getLimits(User $user): array
    {
        return $this->getEntitlement($user)['limits'];
    }

    public function getEntitlement(User $user): array
    {
        if (! config('library.billing_enabled')) {
            return [
                'plan' => 'selfhost',
                'status' => 'active',
                'limits' => config('library.selfhost_limits'),
                'cached' => false,
            ];
        }

        $cacheKey = "ent:{$user->id}";
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached + ['cached' => true];
        }

        $fresh = $this->fetchFromPayments($user);
        if ($fresh) {
            Cache::put($cacheKey, $fresh, (int) config('library.entitlement_ttl', 300));

            return $fresh + ['cached' => false];
        }

        $row = BillingCache::find($user->id);
        if ($row) {
            return [
                'plan' => $row->plan,
                'status' => $row->status,
                'limits' => $row->limits ?? config('library.free_limits'),
                'cached' => true,
                'stale' => true,
            ];
        }

        return [
            'plan' => 'free',
            'status' => 'active',
            'limits' => config('library.free_limits'),
            'cached' => false,
            'stale' => true,
        ];
    }

    public function refresh(User $user): array
    {
        Cache::forget("ent:{$user->id}");
        $ent = $this->getEntitlement($user);

        return $ent;
    }

    private function fetchFromPayments(User $user): ?array
    {
        $base = rtrim((string) config('library.payments_base_url'), '/');
        $token = (string) config('library.payments_api_token');
        if ($base === '' || $token === '') {
            return null;
        }

        try {
            $res = Http::withToken($token)
                ->timeout(5)
                ->get($base.'/api/entitlements', [
                    'authentik_id' => $user->authentik_id,
                    'email' => $user->email,
                ]);
            if (! $res->successful()) {
                return null;
            }
            $data = $res->json();
            $ent = [
                'plan' => $data['plan'] ?? 'free',
                'status' => $data['status'] ?? 'active',
                'limits' => $data['limits'] ?? config('library.free_limits'),
            ];
            BillingCache::updateOrCreate(
                ['user_id' => $user->id],
                ['plan' => $ent['plan'], 'status' => $ent['status'], 'limits' => $ent['limits'], 'synced_at' => now()]
            );

            return $ent;
        } catch (\Throwable $e) {
            Log::warning('payments entitlements fetch failed', ['user_id' => $user->id, 'err' => $e->getMessage()]);

            return null;
        }
    }

    public static function limit(array $limits, string $key, mixed $default = null): mixed
    {
        return $limits[$key] ?? $default;
    }

    /** -1 = ilimitado. */
    public static function unlimited(mixed $limit): bool
    {
        return $limit === -1 || $limit === null;
    }
}
