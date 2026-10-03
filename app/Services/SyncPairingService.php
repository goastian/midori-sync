<?php

namespace App\Services;

use App\Exceptions\SyncProtocolException;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncPairingService
{
    public function __construct(private SyncAuthService $auth, private SyncIdentityService $identity) {}

    public function generate(User $user): array
    {
        $token = strtoupper(bin2hex(random_bytes(8)));
        $ttl = (int) config('services.sync.pairing_ttl', 300);
        DB::table('sync_pairing_codes')->insert([
            'token_hash' => hash('sha256', $token),
            'user_id' => $user->id,
            'expires_at' => now()->addSeconds($ttl),
            'created_at' => now(),
        ]);

        return ['pairing_token' => $token, 'expires_in' => $ttl];
    }

    public function redeem(string $token, string $deviceName, string $deviceType, ?string $ip, ?string $userAgent, bool $renewable = false): array
    {
        $hash = hash('sha256', strtoupper(str_replace('-', '', trim($token))));

        return DB::transaction(function () use ($hash, $deviceName, $deviceType, $ip, $userAgent, $renewable) {
            $code = DB::table('sync_pairing_codes')->where('token_hash', $hash)
                ->where('expires_at', '>', now())->lockForUpdate()->first();
            if (! $code || Carbon::parse($code->expires_at)->lessThanOrEqualTo(now())) {
                throw new SyncProtocolException('Invalid or expired pairing token', 404);
            }

            $user = User::findOrFail($code->user_id);
            $identity = $this->identity->forUser($user);
            $device = $user->devices()->create([
                'device_id' => (string) Str::uuid(),
                'name' => $deviceName,
                'type' => $deviceType,
            ]);
            $tokenData = $this->auth->createSessionToken($user, $device->id, $ip, $userAgent, 2, $renewable);
            DB::table('sync_pairing_codes')->where('token_hash', $hash)->delete();

            return $tokenData + [
                'user' => [
                    'id' => (string) $user->id,
                    'email' => $user->email,
                    'name' => $user->name,
                    'avatar_url' => $user->avatar_url,
                ],
                'device' => [
                    'id' => $device->device_id,
                    'name' => $device->name,
                    'type' => $device->type,
                ],
                'identity' => $identity,
            ];
        }, 5);
    }
}
