<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class BrowserSyncLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.sync.local_dev' => false,
            'services.authentik.client_id' => 'existing-web-client',
            'services.authentik.client_secret' => 'server-only-secret',
            'services.authentik.base_url' => 'https://accounts.example.invalid',
            'services.authentik.issuer' => 'https://accounts.example.invalid/application/o/midori-sync/',
            'services.authentik.redirect' => 'https://sync.example.invalid/auth/callback',
            'services.authentik.native_client_id' => null,
            'services.authentik.native_discovery_url' => null,
        ]);
    }

    public function test_existing_web_login_authorizes_one_native_device_with_pkce(): void
    {
        $user = User::factory()->create(['authentik_issuer' => config('services.authentik.issuer')]);
        $verifier = str_repeat('v', 43);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state = str_repeat('s', 43);
        $redirect = 'http://127.0.0.1:41831/midori-sync/callback';
        $this->getJson('/api/v1/capabilities')->assertOk()
            ->assertJsonPath('authentication.browser_login.version', 1)
            ->assertJsonPath('authentication.oidc', null);

        $this->actingAs($user)->get('/auth/desktop?'.http_build_query([
            'state' => $state, 'code_challenge' => $challenge, 'redirect_uri' => $redirect,
        ]))->assertRedirect(route('auth.redirect'));
        $this->get('/auth/desktop/confirm')->assertForbidden();
        $this->post('/auth/desktop/confirm')->assertForbidden();
        $this->completeWebLogin($user);
        $response = $this->get('/auth/desktop/confirm')->assertRedirect();
        $response->assertHeader('Cache-Control', 'no-store, private');
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith($redirect.'?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $parameters);
        $this->assertSame($state, $parameters['state']);
        $this->get('/auth/desktop/confirm')->assertStatus(410);

        $body = ['code' => $parameters['code'], 'code_verifier' => str_repeat('x', 43),
            'device_name' => 'Midori Desktop'];
        $this->postJson('/api/v1/auth/browser-token', $body)->assertNotFound();
        $body['code_verifier'] = $verifier;
        $result = $this->postJson('/api/v1/auth/browser-token', $body)->assertCreated()
            ->assertJsonPath('identity.issuer', config('services.authentik.issuer'))
            ->assertJsonPath('identity.subject', $user->authentik_id);
        $this->assertNotEmpty($result->json('refresh_token'));
        $this->withToken($result->json('token'))->getJson('/api/v1/account')->assertOk()
            ->assertJsonPath('device.id', $result->json('device.id'));
        $this->postJson('/api/v1/auth/browser-token', $body)->assertNotFound();
        $this->assertDatabaseCount('devices', 1);
        $this->assertDatabaseCount('sync_sessions', 1);
        $this->assertDatabaseCount('sync_browser_authorizations', 0);
    }

    public function test_browser_redirect_is_limited_to_the_local_callback(): void
    {
        $user = User::factory()->create(['authentik_issuer' => config('services.authentik.issuer')]);
        foreach (['https://attacker.example.invalid/callback',
            'http://127.0.0.1:41831/other', 'http://localhost:41831/midori-sync/callback',
            'http://127.0.0.1:99999/midori-sync/callback'] as $redirect) {
            $this->actingAs($user)->get('/auth/desktop?'.http_build_query([
                'state' => str_repeat('s', 43), 'code_challenge' => str_repeat('c', 43),
                'redirect_uri' => $redirect,
            ]))->assertStatus(422);
        }
        $this->assertDatabaseCount('sync_browser_authorizations', 0);
    }

    public function test_desktop_completion_cannot_reuse_authentication_from_another_request_or_user(): void
    {
        $user = User::factory()->create(['authentik_issuer' => config('services.authentik.issuer')]);
        $query = http_build_query([
            'state' => str_repeat('s', 43), 'code_challenge' => str_repeat('c', 43),
            'redirect_uri' => 'http://127.0.0.1:41831/midori-sync/callback',
            'authenticated_user_id' => (string) $user->id,
        ]);
        $this->actingAs($user)->get('/auth/desktop?'.$query)->assertRedirect(route('auth.redirect'));
        $this->get('/auth/desktop/confirm')->assertForbidden();
        $this->completeWebLogin($user);

        $other = User::factory()->create(['authentik_issuer' => config('services.authentik.issuer')]);
        $this->actingAs($other)->get('/auth/desktop/confirm')->assertForbidden();
        $this->post('/auth/desktop/confirm')->assertForbidden();

        $this->actingAs($user)->get('/auth/desktop?'.$query)->assertRedirect(route('auth.redirect'));
        $this->get('/auth/desktop/confirm')->assertForbidden();
        $this->assertDatabaseCount('sync_browser_authorizations', 0);
        $this->assertDatabaseCount('devices', 0);
    }

    public function test_mobile_login_binds_the_device_type_before_redeeming_the_pkce_code(): void
    {
        $user = User::factory()->create(['authentik_issuer' => config('services.authentik.issuer')]);
        $verifier = str_repeat('v', 43);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $redirect = 'org.midorinext.android:/midori-sync/callback';
        $this->getJson('/api/v1/capabilities')->assertOk()
            ->assertJsonPath('authentication.browser_login.device_types', ['desktop', 'mobile']);
        $this->actingAs($user)->get('/auth/desktop?'.http_build_query([
            'state' => str_repeat('s', 43), 'code_challenge' => $challenge,
            'redirect_uri' => $redirect, 'device_type' => 'mobile',
        ]))->assertRedirect(route('auth.redirect'));
        $this->get('/auth/desktop/confirm')->assertForbidden();
        $this->post('/auth/desktop/confirm')->assertForbidden();
        $this->completeWebLogin($user);
        $other = User::factory()->create(['authentik_issuer' => config('services.authentik.issuer')]);
        $this->actingAs($other)->get('/auth/desktop/confirm')->assertForbidden();
        $this->actingAs($user);
        $response = $this->get('/auth/desktop/confirm')->assertRedirect();
        $response->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/auth/desktop/confirm')->assertStatus(410);
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);
        $this->assertStringStartsWith($redirect.'?', $response->headers->get('Location'));
        $body = ['code' => $parameters['code'], 'code_verifier' => $verifier,
            'device_name' => 'Huawei integration', 'device_type' => 'desktop'];
        $result = $this->postJson('/api/v1/auth/browser-token', $body)->assertCreated()
            ->assertJsonPath('device.type', 'mobile')
            ->assertJsonPath('identity.subject', $user->authentik_id);
        $this->withToken($result->json('token'))->getJson('/api/v1/account')->assertOk()
            ->assertJsonPath('device.type', 'mobile');
        $this->postJson('/api/v1/auth/browser-token', $body)->assertNotFound();
    }

    public function test_mobile_callback_is_exact_and_cannot_authorize_a_desktop_grant(): void
    {
        $user = User::factory()->create(['authentik_issuer' => config('services.authentik.issuer')]);
        foreach ([['org.midorinext.android:/midori-sync/callback', 'desktop'],
            ['http://127.0.0.1:41831/midori-sync/callback', 'mobile'],
            ['org.midorinext.android://attacker/midori-sync/callback', 'mobile'],
            ['org.midorinext.android:/midori-sync/callback?injected=1', 'mobile'],
            ['org.midorinext.android:/midori-sync/callback/extra', 'mobile']] as [$redirect, $type]) {
            $this->actingAs($user)->get('/auth/desktop?'.http_build_query([
                'state' => str_repeat('s', 43), 'code_challenge' => str_repeat('c', 43),
                'redirect_uri' => $redirect, 'device_type' => $type,
            ]))->assertStatus(422);
        }
        $this->assertDatabaseCount('sync_browser_authorizations', 0);
        $this->assertDatabaseCount('devices', 0);
    }

    public function test_interactive_login_has_time_for_mfa_and_expired_requests_cannot_authorize(): void
    {
        $user = User::factory()->create(['authentik_issuer' => config('services.authentik.issuer')]);
        $this->actingAs($user)->get('/auth/desktop?'.http_build_query([
            'state' => str_repeat('s', 43), 'code_challenge' => str_repeat('c', 43),
            'redirect_uri' => 'http://127.0.0.1:41831/midori-sync/callback',
        ]))->assertRedirect(route('auth.redirect'));
        $this->travel(4)->minutes();
        $this->completeWebLogin($user);
        $this->travel(7)->minutes();
        $this->get('/auth/desktop/confirm')->assertStatus(410)
            ->assertSee('This connection request has expired')->assertDontSee('Exception trace');
        $this->assertNull(session('sync_browser_authorization'));
        $this->post('/auth/desktop/confirm')->assertStatus(410)->assertSee('This connection request has expired');
        $this->assertDatabaseCount('sync_browser_authorizations', 0);
        $this->assertDatabaseCount('devices', 0);
        $this->assertAuthenticatedAs($user);
    }

    public function test_authentik_callback_returns_to_the_pending_desktop_connection(): void
    {
        $user = User::factory()->create(['authentik_issuer' => null]);
        $this->get('/auth/desktop?'.http_build_query([
            'state' => str_repeat('s', 43), 'code_challenge' => str_repeat('c', 43),
            'redirect_uri' => 'http://127.0.0.1:41831/midori-sync/callback',
        ]))->assertRedirect(route('auth.redirect'));

        $this->completeWebLogin($user);
        $response = $this->get('/auth/desktop/confirm')->assertRedirect();
        $this->assertStringStartsWith('http://127.0.0.1:41831/midori-sync/callback?', $response->headers->get('Location'));
        $this->get('/auth/desktop/confirm')->assertStatus(410);
        $this->assertSame(config('services.authentik.issuer'), $user->fresh()->authentik_issuer);
    }

    private function completeWebLogin(User $user): void
    {
        $identity = Mockery::mock();
        $identity->shouldReceive('getId')->andReturn($user->authentik_id);
        $identity->shouldReceive('getEmail')->andReturn($user->email);
        $identity->shouldReceive('getName')->andReturn($user->name);
        $identity->shouldReceive('getAvatar')->andReturn(null);
        $provider = Mockery::mock();
        $provider->shouldReceive('user')->once()->andReturn($identity);
        Socialite::shouldReceive('driver')->with('authentik')->andReturn($provider);

        $this->get('/auth/callback')->assertRedirect(route('auth.desktop.confirm'));
    }
}
