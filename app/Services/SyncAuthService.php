<?php

namespace App\Services;

use App\Exceptions\SyncProtocolException;
use App\Models\Device;
use App\Models\SyncSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncAuthService
{
    public function __construct(private SyncNativeCryptoService $crypto, private SyncIdentityService $identity) {}

    public function createSessionToken(User $user, ?int $deviceId = null, ?string $ipAddress = null, ?string $userAgent = null, int $protocolVersion = 2, bool $renewable = false): array
    {
        return DB::transaction(function () use ($user, $deviceId, $ipAddress, $userAgent, $protocolVersion, $renewable) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $device = $deviceId === null ? null : Device::whereKey($deviceId)->where('user_id', $user->id)->first();
            if ($deviceId !== null && ! $device) {
                throw new SyncProtocolException('device_required', 409);
            }
            $this->crypto->validateNewSession($user, $protocolVersion, $deviceId);
            $refresh = null;
            $refreshFields = [];
            if ($renewable) {
                if ($protocolVersion !== 2 || ! $device) {
                    throw new SyncProtocolException('native_session_required', 409);
                }
                if (SyncSession::where('user_id', $user->id)->whereNotNull('refresh_hash')
                    ->where('refresh_expires_at', '>', now())->count() >= SyncRefreshService::MAX_SESSIONS) {
                    throw new SyncProtocolException('refresh_session_capacity', 409);
                }
                $refresh = SyncRefreshService::newToken();
                $refreshFields = [
                    'refresh_hash' => SyncRefreshService::tokenHash($refresh),
                    'refresh_expires_at' => now()->addSeconds(SyncRefreshService::REFRESH_LIFETIME),
                    'refreshed_at' => now(),
                    'refresh_identity' => $this->identity->forUser($user) + ['device_id' => $device->device_id],
                ];
            }
            $token = Str::random(64);
            $tokenHash = hash('sha256', $token);
            $ttl = $renewable ? SyncRefreshService::accessLifetime() : (int) config('services.sync.token_ttl', 3600);

            $session = SyncSession::create([
                'user_id' => $user->id,
                'device_id' => $deviceId,
                'token_hash' => $tokenHash,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent ? substr($userAgent, 0, 512) : null,
                'expires_at' => now()->addSeconds($ttl),
                'created_at' => now(),
                'protocol_version' => $protocolVersion,
            ] + $refreshFields);

            return [
                'token' => $token,
                'expires_at' => $session->expires_at->toIso8601String(),
                'expires_in' => $ttl,
            ] + ($refresh === null ? [] : [
                'refresh_version' => 1,
                'refresh_token' => $refresh,
                'refresh_expires_at' => $session->refresh_expires_at->toIso8601String(),
            ]);
        }, 5);
    }

    public function validateToken(string $token): ?SyncSession
    {
        $hash = hash('sha256', $token);

        $session = SyncSession::findByTokenHash($hash);
        if ($session && ($session->protocol_version !== 2 || ! $session->device_id ||
            ! Device::whereKey($session->device_id)->where('user_id', $session->user_id)->exists())) {
            return null;
        }

        return $session;
    }

    public function revokeDevice(User $user, string $deviceId): ?int
    {
        return DB::transaction(function () use ($user, $deviceId) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $device = $user->devices()->where('device_id', $deviceId)->lockForUpdate()->first();
            if (! $device) {
                return null;
            }
            $revoked = SyncSession::where('device_id', $device->id)->delete();
            $device->delete();

            return $revoked;
        }, 5);
    }

    public function revokeToken(string $token): bool
    {
        $session = SyncSession::where('token_hash', hash('sha256', $token))->first();

        return $session !== null && $this->revokeSession($session->user_id, $session->id);
    }

    public function revokeSession(int $userId, string $sessionId): bool
    {
        return DB::transaction(function () use ($userId, $sessionId) {
            if (! User::whereKey($userId)->lockForUpdate()->first()) {
                return false;
            }

            return SyncSession::where('user_id', $userId)->whereKey($sessionId)->delete() > 0;
        }, 5);
    }

    public function revokeAllForUser(int $userId): int
    {
        return DB::transaction(function () use ($userId) {
            if (! User::whereKey($userId)->lockForUpdate()->first()) {
                return 0;
            }

            return SyncSession::where('user_id', $userId)->delete();
        }, 5);
    }

    public function cleanupExpired(): int
    {
        return SyncSession::inactive()->delete();
    }
}
