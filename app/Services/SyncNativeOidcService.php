<?php

namespace App\Services;

use App\Exceptions\SyncProtocolException;
use App\Models\Device;
use App\Models\User;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class SyncNativeOidcService
{
    private const MAX_DOCUMENT_BYTES = 131072;

    public function __construct(private SyncAuthService $auth, private SyncIdentityService $identity) {}

    public function configuration(): ?array
    {
        $issuer = $this->identity->configuredIssuer();
        $clientId = config('services.authentik.native_client_id');
        $discovery = config('services.authentik.native_discovery_url');
        if ($this->identity->isDevelopment() || ! is_string($issuer) || ! is_string($clientId)
            || $clientId === '' || strlen($clientId) > 255 || preg_match('/[\x00-\x20\x7f]/', $clientId)
            || ! is_string($discovery) || strlen($discovery) > 2048
            || ! str_ends_with($discovery, '/.well-known/openid-configuration')
            || ! $this->sameOrigin($discovery, $issuer)) {
            return null;
        }

        return ['issuer' => $issuer, 'client_id' => $clientId, 'discovery_url' => $discovery];
    }

    public function exchange(string $idToken, string $accessToken, string $nonce, string $deviceName,
        ?string $ip, ?string $userAgent): array
    {
        $configuration = $this->configuration();
        if (! $configuration) {
            throw new SyncProtocolException('oidc_unavailable', 503);
        }
        $metadata = $this->document($configuration['discovery_url']);
        if (($metadata['issuer'] ?? null) !== $configuration['issuer']) {
            throw new SyncProtocolException('oidc_unavailable', 503);
        }
        foreach (['jwks_uri', 'userinfo_endpoint'] as $field) {
            if (! is_string($metadata[$field] ?? null)
                || ! $this->sameOrigin($metadata[$field], $configuration['issuer'])) {
                throw new SyncProtocolException('oidc_unavailable', 503);
            }
        }
        $claims = $this->claims($idToken, $metadata['jwks_uri'], $configuration, $nonce);
        $userinfo = $this->userinfo($metadata['userinfo_endpoint'], $accessToken, $claims->sub);

        return DB::transaction(function () use ($idToken, $claims, $userinfo, $configuration, $deviceName, $ip, $userAgent) {
            if (DB::table('sync_native_oidc_exchanges')->insertOrIgnore([
                'token_hash' => hash('sha256', $idToken),
                'expires_at' => now()->setTimestamp((int) $claims->exp),
                'created_at' => now(),
            ]) !== 1) {
                throw new SyncProtocolException('oidc_token_reused', 409);
            }
            $user = User::where('authentik_id', $claims->sub)->lockForUpdate()->first();
            if ($user && $user->authentik_issuer !== null && $user->authentik_issuer !== $configuration['issuer']) {
                throw new SyncProtocolException('identity_issuer_mismatch', 409);
            }
            if (User::where('email', $userinfo['email'])->where(function ($query) use ($claims) {
                $query->where('authentik_id', '!=', $claims->sub)->orWhereNull('authentik_id');
            })->exists()) {
                throw new SyncProtocolException('invalid_account_identity', 409);
            }
            if (! $user) {
                $user = User::create([
                    'authentik_id' => $claims->sub,
                    'authentik_issuer' => $configuration['issuer'],
                    'email' => $userinfo['email'],
                    'name' => $userinfo['name'],
                    'avatar_url' => $userinfo['picture'],
                ]);
            } else {
                $user->update([
                    'authentik_issuer' => $configuration['issuer'],
                    'email' => $userinfo['email'],
                    'name' => $userinfo['name'],
                    'avatar_url' => $userinfo['picture'],
                ]);
            }
            $device = Device::create([
                'user_id' => $user->id,
                'device_id' => (string) Str::uuid(),
                'name' => $deviceName,
                'type' => 'desktop',
            ]);
            $session = $this->auth->createSessionToken($user, $device->id, $ip, $userAgent, 2, true);

            return $session + [
                'identity' => $this->identity->forUser($user),
                'user' => ['id' => (string) $user->id, 'name' => $user->name, 'email' => $user->email,
                    'avatar_url' => $user->avatar_url],
                'device' => ['id' => $device->device_id, 'name' => $device->name, 'type' => 'desktop'],
            ];
        }, 5);
    }

    private function claims(string $token, string $jwksUrl, array $configuration, string $nonce): object
    {
        $document = $this->document($jwksUrl);
        if (! is_array($document['keys'] ?? null)) {
            throw new SyncProtocolException('oidc_unavailable', 503);
        }
        $keys = [];
        foreach ($document['keys'] as $key) {
            if (! is_array($key) || ! is_string($key['kid'] ?? null) || $key['kid'] === ''
                || strlen($key['kid']) > 128 || isset($keys[$key['kid']])
                || ! in_array($key['use'] ?? 'sig', ['sig'], true)) {
                continue;
            }
            $algorithm = match ($key['kty'] ?? null) {
                'RSA' => 'RS256',
                'EC' => ($key['crv'] ?? null) === 'P-256' ? 'ES256' : null,
                default => null,
            };
            if ($algorithm && (! isset($key['alg']) || $key['alg'] === $algorithm)) {
                $keys[$key['kid']] = $key + ['alg' => $algorithm];
            }
        }
        if ($keys === [] || count($keys) > 20) {
            throw new SyncProtocolException('oidc_unavailable', 503);
        }
        try {
            $claims = JWT::decode($token, JWK::parseKeySet(['keys' => array_values($keys)]));
        } catch (Throwable) {
            throw new SyncProtocolException('invalid_oidc_token', 401);
        }
        $audience = $claims->aud ?? null;
        $audiences = is_string($audience) ? [$audience] : (is_array($audience) ? $audience : []);
        if (($claims->iss ?? null) !== $configuration['issuer']
            || ! in_array($configuration['client_id'], $audiences, true)
            || (isset($claims->azp) && $claims->azp !== $configuration['client_id'])
            || (count($audiences) > 1 && ! isset($claims->azp))
            || ($claims->nonce ?? null) !== $nonce
            || ! is_string($claims->sub ?? null) || $claims->sub === '' || strlen($claims->sub) > 255
            || ! is_int($claims->exp ?? null) || $claims->exp <= time()
            || $claims->exp > time() + 86400) {
            throw new SyncProtocolException('invalid_oidc_token', 401);
        }

        return $claims;
    }

    private function userinfo(string $url, string $accessToken, string $subject): array
    {
        try {
            $response = Http::acceptJson()->withToken($accessToken)->connectTimeout(3)->timeout(5)
                ->withoutRedirecting()->get($url);
        } catch (Throwable) {
            throw new SyncProtocolException('oidc_unavailable', 503);
        }
        if (! $response->ok() || strlen($response->body()) > self::MAX_DOCUMENT_BYTES) {
            throw new SyncProtocolException('invalid_oidc_token', 401);
        }
        $data = $response->json();
        $email = $data['email'] ?? null;
        $name = $data['name'] ?? $data['preferred_username'] ?? null;
        $picture = $data['picture'] ?? null;
        if (! is_array($data) || ($data['sub'] ?? null) !== $subject
            || ($data['email_verified'] ?? null) !== true
            || ! is_string($email) || strlen($email) > 255 || ! filter_var($email, FILTER_VALIDATE_EMAIL)
            || ! is_string($name) || $name === '' || strlen($name) > 255
            || ($picture !== null && (! is_string($picture) || strlen($picture) > 2048))) {
            throw new SyncProtocolException('invalid_oidc_token', 401);
        }

        return ['email' => $email, 'name' => $name, 'picture' => $picture];
    }

    private function document(string $url): array
    {
        return Cache::remember('sync:oidc:'.hash('sha256', $url), now()->addMinutes(5), function () use ($url) {
            try {
                $response = Http::acceptJson()->connectTimeout(3)->timeout(5)->withoutRedirecting()->get($url);
            } catch (Throwable) {
                throw new SyncProtocolException('oidc_unavailable', 503);
            }
            if (! $response->ok() || strlen($response->body()) > self::MAX_DOCUMENT_BYTES
                || ! is_array($response->json())) {
                throw new SyncProtocolException('oidc_unavailable', 503);
            }

            return $response->json();
        });
    }

    private function sameOrigin(string $url, string $issuer): bool
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return false;
        }
        $target = parse_url($url);
        $origin = parse_url($issuer);

        return is_array($target) && is_array($origin)
            && ($target['scheme'] ?? null) === 'https' && ($origin['scheme'] ?? null) === 'https'
            && ($target['host'] ?? null) === ($origin['host'] ?? null)
            && ($target['port'] ?? 443) === ($origin['port'] ?? 443)
            && ! isset($target['user']) && ! isset($target['pass'])
            && ! isset($target['query']) && ! isset($target['fragment']);
    }
}
