<?php

namespace Tests\Feature;

use Tests\TestCase;

class SyncCheckAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.sync.local_dev' => false,
            'services.authentik.issuer' => 'https://accounts.example.invalid/application/o/midori-sync/',
            'services.authentik.client_id' => 'web-client',
            'services.authentik.client_secret' => 'server-only-secret',
            'services.authentik.base_url' => 'https://accounts.example.invalid',
            'services.authentik.redirect' => 'https://sync.example.invalid/auth/callback',
            'services.authentik.native_client_id' => null,
            'services.authentik.native_discovery_url' => null,
        ]);
    }

    public function test_web_client_supports_desktop_login_without_a_public_client(): void
    {
        $this->artisan('sync:check-auth')
            ->expectsOutputToContain('Desktop sign-in is configured.')
            ->expectsOutputToContain('Browser login: enabled')
            ->expectsOutputToContain('Direct public OIDC: disabled')
            ->assertSuccessful();
        $this->getJson('/api/v1/capabilities')->assertOk()
            ->assertJsonPath('authentication.browser_login.version', 1)
            ->assertJsonPath('authentication.oidc', null);
    }

    public function test_missing_or_invalid_issuer_blocks_desktop_login_even_with_a_web_client(): void
    {
        foreach ([null, '', 'http://accounts.example.invalid/'] as $issuer) {
            config(['services.authentik.issuer' => $issuer]);
            $this->artisan('sync:check-auth')
                ->expectsOutputToContain('AUTHENTIK_ISSUER must be the exact HTTPS issuer')
                ->assertFailed();
            $this->getJson('/api/v1/capabilities')->assertOk()
                ->assertJsonPath('authentication.issuer', null)
                ->assertJsonPath('authentication.browser_login', null);
            $this->get('/auth/desktop')->assertStatus(503);
        }
    }

    public function test_incomplete_web_configuration_cannot_pass_the_check(): void
    {
        foreach (['client_id', 'client_secret', 'base_url', 'redirect'] as $key) {
            $value = config("services.authentik.$key");
            config(["services.authentik.$key" => '']);
            $this->artisan('sync:check-auth')
                ->expectsOutputToContain('Desktop sign-in is unavailable.')
                ->assertFailed();
            config(["services.authentik.$key" => $value]);
        }
    }

    public function test_public_oidc_can_support_desktop_login_without_the_web_client(): void
    {
        config([
            'services.authentik.client_secret' => null,
            'services.authentik.native_client_id' => 'public-desktop-client',
            'services.authentik.native_discovery_url' => config('services.authentik.issuer').'.well-known/openid-configuration',
        ]);
        $this->artisan('sync:check-auth')
            ->expectsOutputToContain('Browser login: disabled')
            ->expectsOutputToContain('Direct public OIDC: enabled')
            ->assertSuccessful();
    }

    public function test_development_pairing_is_accepted_only_outside_production(): void
    {
        config(['services.sync.local_dev' => true, 'services.authentik.issuer' => null]);
        $this->artisan('sync:check-auth')
            ->expectsOutputToContain('Local development pairing is enabled.')
            ->assertSuccessful();
        $this->app['env'] = 'production';
        $this->artisan('sync:check-auth')
            ->expectsOutputToContain('AUTHENTIK_ISSUER must be the exact HTTPS issuer')
            ->assertFailed();
    }
}
