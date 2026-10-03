<?php

namespace App\Services;

use App\Exceptions\SyncProtocolException;
use App\Models\User;

class SyncIdentityService
{
    public const DEVELOPMENT_ISSUER = 'urn:midori:sync:local';

    public function isDevelopment(): bool
    {
        return app()->environment(['local', 'testing']) && config('services.sync.local_dev') === true;
    }

    public function configuredIssuer(): ?string
    {
        if ($this->isDevelopment()) {
            return self::DEVELOPMENT_ISSUER;
        }
        $issuer = config('services.authentik.issuer');
        if (! is_string($issuer) || strlen($issuer) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $issuer)) {
            return null;
        }
        $url = parse_url($issuer);
        if (! is_array($url) || ($url['scheme'] ?? null) !== 'https' || empty($url['host'])
            || isset($url['user']) || isset($url['pass'])
            || isset($url['query']) || isset($url['fragment']) || ($url['port'] ?? null) === 0) {
            return null;
        }

        return $issuer;
    }

    public function bindAuthenticatedWebUser(User $user, bool $freshLogin = false): void
    {
        $issuer = $this->configuredIssuer();
        if (! $this->isDevelopment() && $issuer &&
            ($user->authentik_issuer === null || ($freshLogin && $user->authentik_issuer !== $issuer))) {
            $user->update(['authentik_issuer' => $issuer]);
        }
    }

    public function forUser(User $user): array
    {
        $issuer = $this->configuredIssuer();
        if (! $issuer || ! $user->authentik_issuer) {
            throw new SyncProtocolException('native_identity_required', 409);
        }
        if ($user->authentik_issuer !== $issuer) {
            throw new SyncProtocolException('identity_issuer_mismatch', 409);
        }
        $subject = $user->authentik_id;
        if (! is_string($subject) || $subject === '' || strlen($subject) > 255 || preg_match('/\p{Cc}/u', $subject) !== 0) {
            throw new SyncProtocolException('invalid_account_identity', 409);
        }

        return ['issuer' => $issuer, 'subject' => $subject, 'kind' => $this->isDevelopment() ? 'development' : 'oidc'];
    }
}
