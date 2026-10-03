<?php

namespace App\Services;

use App\Exceptions\SyncProtocolException;
use App\Models\Device;
use App\Models\SyncSession;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncRefreshService
{
    public const MAX_REQUEST_BYTES = 4096;

    public const MAX_RECEIPTS = 1024;

    public const MAX_SESSIONS = 32;

    public const REFRESH_LIFETIME = 2592000;

    public function __construct(private SyncIdentityService $identity) {}

    public static function newToken(): string
    {
        return 'mrf_'.bin2hex(random_bytes(32));
    }

    public static function tokenHash(string $token): string
    {
        return hash('sha256', 'refresh:'.$token);
    }

    public static function accessLifetime(): int
    {
        return max(60, min(3600, (int) config('services.sync.token_ttl', 3600)));
    }

    public function rotate(string $token, string $operationId): array
    {
        $hash = self::tokenHash($token);
        $operationId = strtolower($operationId);

        return DB::transaction(function () use ($hash, $operationId) {
            $session = $this->lockSession($hash);
            if (! $session || $session->protocol_version !== 2 || ! $session->refresh_expires_at?->isFuture() || ! $session->refreshed_at) {
                throw new SyncProtocolException('invalid_refresh', 401);
            }
            $user = $session->user;
            $device = Device::whereKey($session->device_id)->where('user_id', $user->id)->lockForUpdate()->first();
            if (! $device) {
                throw new SyncProtocolException('invalid_refresh', 401);
            }
            $identity = $this->identity->forUser($user);
            foreach ($identity + ['device_id' => $device->device_id] as $name => $value) {
                if (($session->refresh_identity[$name] ?? null) !== $value) {
                    throw new SyncProtocolException('refresh_identity_changed', 409);
                }
            }
            $receipt = DB::table('sync_refresh_receipts')->where('token_hash', $hash)->where('session_id', $session->id)->first();
            if ($receipt) {
                if ($receipt->operation_id !== $operationId) {
                    $session->delete();

                    return ['error' => 'refresh_reused', 'status' => 401];
                }
                if ($receipt->response === null) {
                    throw new SyncProtocolException('refresh_superseded', 409);
                }

                return $this->replay($session, $receipt);
            }
            if (! hash_equals($session->refresh_hash, $hash)) {
                throw new SyncProtocolException('invalid_refresh', 401);
            }
            $receipts = DB::table('sync_refresh_receipts')->where('session_id', $session->id);
            if ((clone $receipts)->where('operation_id', $operationId)->exists()) {
                throw new SyncProtocolException('refresh_operation_conflict', 409);
            }
            if ($receipts->count() >= self::MAX_RECEIPTS) {
                throw new SyncProtocolException('refresh_capacity', 409);
            }
            $minimumInterval = min(60, intdiv(self::accessLifetime(), 2));
            $retryAfter = $session->refreshed_at->getTimestamp() + $minimumInterval - now()->getTimestamp();
            if ($retryAfter > 0) {
                return ['error' => 'rate_limited', 'status' => 429, 'retry_after' => $retryAfter];
            }
            $access = Str::random(64);
            $refresh = self::newToken();
            $expires = now()->addSeconds(self::accessLifetime())->min($session->refresh_expires_at);
            $session->update([
                'token_hash' => hash('sha256', $access), 'refresh_hash' => self::tokenHash($refresh),
                'expires_at' => $expires, 'refreshed_at' => now(),
            ]);
            $response = [
                'refresh_version' => 1, 'operation_id' => $operationId, 'account_version' => 1,
                'token' => $access, 'refresh_token' => $refresh,
                'expires_at' => $session->expires_at->toIso8601String(),
                'expires_in' => max(0, $session->expires_at->getTimestamp() - now()->getTimestamp()),
                'refresh_expires_at' => $session->refresh_expires_at->toIso8601String(),
                'identity' => $identity,
                'user' => ['id' => (string) $user->id, 'name' => $user->name, 'email' => $user->email],
                'device' => ['id' => $device->device_id, 'name' => $device->name, 'type' => $device->type],
            ];
            $encrypted = Crypt::encryptString(json_encode([
                'session_id' => $session->id, 'token_hash' => $hash, 'operation_id' => $operationId, 'response' => $response,
            ], JSON_THROW_ON_ERROR));
            $receipts->update(['response' => null]);
            DB::table('sync_refresh_receipts')->insert([
                'token_hash' => $hash, 'session_id' => $session->id, 'operation_id' => $operationId,
                'response' => $encrypted, 'created_at' => now(),
            ]);

            return $response;
        }, 5);
    }

    public function revoke(string $token): void
    {
        $hash = self::tokenHash($token);
        DB::transaction(function () use ($hash) {
            $this->lockSession($hash)?->delete();
        }, 5);
    }

    private function lockSession(string $hash): ?SyncSession
    {
        $session = SyncSession::where('refresh_hash', $hash)->first();
        if (! $session) {
            $id = DB::table('sync_refresh_receipts')->where('token_hash', $hash)->value('session_id');
            $session = $id ? SyncSession::find($id) : null;
        }
        if (! $session || ! User::whereKey($session->user_id)->lockForUpdate()->first()) {
            return null;
        }
        $session = SyncSession::whereKey($session->id)->lockForUpdate()->first();
        if (! $session || ($session->refresh_hash !== $hash && ! DB::table('sync_refresh_receipts')
            ->where('session_id', $session->id)->where('token_hash', $hash)->exists())) {
            return null;
        }

        return $session;
    }

    private function replay(SyncSession $session, object $receipt): array
    {
        try {
            $saved = json_decode(Crypt::decryptString($receipt->response), true, 32, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw new SyncProtocolException('refresh_receipt_unavailable', 503);
        }
        $response = $saved['response'] ?? null;
        if (! is_array($saved) || ($saved['session_id'] ?? null) !== $session->id || ($saved['token_hash'] ?? null) !== $receipt->token_hash ||
            ($saved['operation_id'] ?? null) !== $receipt->operation_id || ! is_array($response) ||
            ! is_string($response['token'] ?? null) || ! is_string($response['refresh_token'] ?? null) ||
            ! hash_equals($session->token_hash, hash('sha256', $response['token'])) ||
            ! hash_equals($session->refresh_hash, self::tokenHash($response['refresh_token']))) {
            throw new SyncProtocolException('refresh_receipt_unavailable', 503);
        }

        return $response;
    }
}
