<?php

namespace App\Services;

use App\Models\SyncSession;
use App\Models\User;
use Illuminate\Support\Str;

class SyncAuthService
{
    public function createSessionToken(User $user, ?int $deviceId = null, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        $token = Str::random(64);
        $tokenHash = hash('sha256', $token);
        $ttl = (int) config('services.sync.token_ttl', 3600);

        $session = SyncSession::create([
            'user_id' => $user->id,
            'device_id' => $deviceId,
            'token_hash' => $tokenHash,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent ? substr($userAgent, 0, 512) : null,
            'expires_at' => now()->addSeconds($ttl),
            'created_at' => now(),
        ]);

        return [
            'token' => $token,
            'expires_at' => $session->expires_at->toIso8601String(),
            'expires_in' => $ttl,
        ];
    }

    public function validateToken(string $token): ?SyncSession
    {
        $hash = hash('sha256', $token);
        return SyncSession::findByTokenHash($hash);
    }

    public function revokeToken(string $token): bool
    {
        $hash = hash('sha256', $token);
        return SyncSession::where('token_hash', $hash)->delete() > 0;
    }

    public function revokeAllForUser(int $userId): int
    {
        return SyncSession::where('user_id', $userId)->delete();
    }

    public function cleanupExpired(): int
    {
        return SyncSession::where('expires_at', '<=', now())->delete();
    }
}
