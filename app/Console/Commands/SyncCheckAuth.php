<?php

namespace App\Console\Commands;

use App\Services\SyncBrowserAuthorizationService;
use App\Services\SyncIdentityService;
use App\Services\SyncNativeOidcService;
use Illuminate\Console\Command;

class SyncCheckAuth extends Command
{
    protected $signature = 'sync:check-auth';

    protected $description = 'Check the effective configuration for Midori Desktop sign-in';

    public function handle(SyncIdentityService $identity, SyncBrowserAuthorizationService $browser,
        SyncNativeOidcService $oidc): int
    {
        if ($identity->isDevelopment()) {
            $this->info('Local development pairing is enabled.');

            return self::SUCCESS;
        }

        $issuer = $identity->configuredIssuer();
        if ($issuer === null) {
            $this->error('AUTHENTIK_ISSUER must be the exact HTTPS issuer from the Authentik OpenID discovery document.');

            return self::FAILURE;
        }

        $browserAvailable = $browser->available();
        $oidcAvailable = $oidc->configuration() !== null;
        if (! $browserAvailable && ! $oidcAvailable) {
            $this->error('Desktop sign-in is unavailable. Configure AUTHENTIK_CLIENT_ID, AUTHENTIK_CLIENT_SECRET, AUTHENTIK_BASE_URL and AUTHENTIK_REDIRECT_URI for browser login.');

            return self::FAILURE;
        }

        $this->info('Desktop sign-in is configured.');
        $this->line('Issuer: '.$issuer);
        $this->line('Browser login: '.($browserAvailable ? 'enabled' : 'disabled'));
        $this->line('Direct public OIDC: '.($oidcAvailable ? 'enabled' : 'disabled'));

        return self::SUCCESS;
    }
}
