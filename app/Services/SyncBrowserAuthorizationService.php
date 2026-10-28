<?php

namespace App\Services;

use App\Exceptions\SyncProtocolException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncBrowserAuthorizationService
{
    public function __construct(private SyncAuthService $auth, private SyncIdentityService $identity) {}

    public function available(): bool
    {
        if ($this->identity->isDevelopment() || $this->identity->configuredIssuer() === null) {
            return false;
        }
        foreach (['client_id', 'client_secret', 'base_url', 'redirect'] as $key) {
            if (! is_string(config("services.authentik.$key")) || config("services.authentik.$key") === '') {
                return false;
            }
        }

        return true;
    }

    public function issue(User $user, string $challenge, string $deviceType = 'desktop'): string
    {
        if (! in_array($deviceType, ['desktop', 'mobile'], true)) {
            throw new SyncProtocolException('invalid_device_type', 422);
        }
        if (! $this->available()) {
            throw new SyncProtocolException('browser_login_unavailable', 503);
        }
        $this->identity->forUser($user);
        $code = bin2hex(random_bytes(32));
        DB::table('sync_browser_authorizations')->insert([
            'code_hash' => hash('sha256', $code),
            'user_id' => $user->id,
            'challenge' => $challenge,
            'device_type' => $deviceType,
            'expires_at' => now()->addMinutes(3),
            'created_at' => now(),
        ]);

        return $code;
    }

    public function redeem(string $code, string $verifier, string $deviceName, ?string $ip, ?string $userAgent): array
    {
        if (! $this->available()) {
            throw new SyncProtocolException('browser_login_unavailable', 503);
        }
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return DB::transaction(function () use ($code, $challenge, $deviceName, $ip, $userAgent) {
            $hash = hash('sha256', $code);
            $authorization = DB::table('sync_browser_authorizations')->where('code_hash', $hash)
                ->where('expires_at', '>', now())->lockForUpdate()->first();
            if (! $authorization || ! hash_equals($authorization->challenge, $challenge)) {
                throw new SyncProtocolException('invalid_browser_authorization', 404);
            }
            $user = User::findOrFail($authorization->user_id);
            $identity = $this->identity->forUser($user);
            $device = $user->devices()->create([
                'device_id' => (string) Str::uuid(),
                'name' => $deviceName,
                'type' => $authorization->device_type,
            ]);
            $session = $this->auth->createSessionToken($user, $device->id, $ip, $userAgent, 2, true);
            DB::table('sync_browser_authorizations')->where('code_hash', $hash)->delete();

            return $session + [
                'identity' => $identity,
                'user' => ['id' => (string) $user->id, 'name' => $user->name,
                    'email' => $user->email, 'avatar_url' => $user->avatar_url],
                'device' => ['id' => $device->device_id, 'name' => $device->name, 'type' => $device->type],
            ];
        }, 5);
    }
}
