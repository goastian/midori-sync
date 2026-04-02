<?php

namespace App\Services;

use App\Models\HawkToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Handles Hawk authentication for the Sync Storage API.
 *
 * Hawk is an HTTP authentication scheme providing a method for making
 * authenticated HTTP requests with partial cryptographic verification.
 * Used by Firefox Sync 1.5 protocol.
 */
class HawkAuthService
{
    /**
     * Generate a new Hawk token pair for a user.
     *
     * @return array{id: string, key: string, token: HawkToken}
     */
    public function generateToken(User $user): array
    {
        $id = Str::random(64);
        $key = Str::random(64);
        $duration = (int) config('services.sync.hawk_token_duration', 3600);

        $token = HawkToken::create([
            'id' => $id,
            'user_id' => $user->id,
            'hawk_key' => hash('sha256', $key),
            'expires_at' => now()->addSeconds($duration),
        ]);

        return [
            'id' => $id,
            'key' => base64_encode($key),
            'token' => $token,
        ];
    }

    /**
     * Validate a Hawk Authorization header and return the authenticated user.
     */
    public function authenticate(Request $request): ?User
    {
        $authHeader = $request->header('Authorization', '');

        if (!str_starts_with($authHeader, 'Hawk ')) {
            return null;
        }

        $params = $this->parseHawkHeader($authHeader);

        if (!$params || !isset($params['id'], $params['ts'], $params['nonce'], $params['mac'])) {
            Log::warning('hawk_auth_rejected', ['reason' => 'missing_required_params']);
            return null;
        }

        $skew = (int) config('services.sync.hawk_clock_skew_seconds', 60);
        $now = time();
        $clientTs = (int) $params['ts'];
        if (abs($now - $clientTs) > $skew) {
            Log::warning('hawk_auth_rejected', [
                'reason' => 'timestamp_skew',
                'client_ts' => $clientTs,
                'server_ts' => $now,
            ]);

            return null;
        }

        $nonceKey = sprintf('hawk:nonce:%s:%s', $params['id'], $params['nonce']);
        if (!Cache::add($nonceKey, true, $skew + 30)) {
            Log::warning('hawk_auth_rejected', ['reason' => 'nonce_replay']);

            return null;
        }

        $token = HawkToken::find($params['id']);

        if (!$token || $token->isExpired()) {
            Log::warning('hawk_auth_rejected', ['reason' => 'invalid_or_expired_token']);
            return null;
        }

        if (!empty($params['hash']) && $request->getContent() !== '') {
            $expectedPayloadHash = base64_encode(hash('sha256', $request->getContent(), true));
            if (!hash_equals($expectedPayloadHash, $params['hash'])) {
                Log::warning('hawk_auth_rejected', ['reason' => 'payload_hash_mismatch']);

                return null;
            }
        }

        // Verify the MAC
        $expectedMac = $this->calculateMac(
            $token,
            $request->method(),
            $request->path(),
            $request->getQueryString(),
            $request->getHost(),
            $request->getPort(),
            $params['ts'],
            $params['nonce'],
            $params['hash'] ?? null,
        );

        if (!hash_equals($expectedMac, $params['mac'])) {
            Log::warning('hawk_auth_rejected', ['reason' => 'mac_mismatch']);
            return null;
        }

        Log::info('hawk_auth_success', ['token_id' => $token->id, 'user_id' => $token->user_id]);

        return $token->user;
    }

    /**
     * Parse a Hawk Authorization header into its components.
     *
     * @return array<string, string>|null
     */
    private function parseHawkHeader(string $header): ?array
    {
        $header = substr($header, 5); // Remove "Hawk "
        $params = [];

        if (preg_match_all('/(\w+)="([^"]*)"/', $header, $matches)) {
            foreach ($matches[1] as $i => $key) {
                $params[$key] = $matches[2][$i];
            }
        }

        return !empty($params) ? $params : null;
    }

    /**
     * Calculate the Hawk MAC for request verification.
     */
    private function calculateMac(
        HawkToken $token,
        string $method,
        string $path,
        ?string $queryString,
        string $host,
        int $port,
        string $ts,
        string $nonce,
        ?string $hash = null,
    ): string {
        $resource = '/' . ltrim($path, '/');
        if (!empty($queryString)) {
            $resource .= '?' . $queryString;
        }

        $normalized = "hawk.1.header\n"
            . $ts . "\n"
            . $nonce . "\n"
            . strtoupper($method) . "\n"
            . $resource . "\n"
            . strtolower($host) . "\n"
            . $port . "\n"
            . ($hash ?? '') . "\n"
            . "\n";

        $key = pack('H*', $token->hawk_key);

        return base64_encode(hash_hmac('sha256', $normalized, $key, true));
    }

    /**
     * Clean up expired Hawk tokens.
     */
    public function purgeExpiredTokens(): int
    {
        return HawkToken::where('expires_at', '<', now())->delete();
    }
}
