<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SyncSession;
use App\Models\User;
use App\Services\SyncAuthService;
use App\Services\SyncIdentityService;
use App\Services\SyncPairingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NativeAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sync.local_dev' => true]);
    }

    private function nativeUser(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['authentik_issuer' => SyncIdentityService::DEVELOPMENT_ISSUER]);
    }

    public function test_native_pairing_and_account_return_the_same_stable_identity(): void
    {
        $user = $this->nativeUser(['authentik_id' => 'usuario-ñ']);
        $code = app(SyncPairingService::class)->generate($user)['pairing_token'];
        $paired = $this->postJson('/api/v1/pair/redeem', [
            'pairing_token' => $code, 'device_name' => 'Perfil A',
        ])->assertCreated()->assertJsonPath('identity.subject', 'usuario-ñ')
            ->assertJsonPath('identity.issuer', SyncIdentityService::DEVELOPMENT_ISSUER)
            ->assertJsonPath('identity.kind', 'development');
        $account = $this->withToken($paired->json('token'))->getJson('/api/v1/account')
            ->assertOk()->assertJsonPath('account_version', 1)->assertJsonPath('user.id', (string) $user->id)
            ->assertJsonPath('device.id', $paired->json('device.id'))
            ->assertJsonPath('expires_at', $paired->json('expires_at'))
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($paired->json('identity'), $account->json('identity'));
        $this->assertArrayNotHasKey('token', $account->json());
        $this->deleteJson('/api/v1/auth/token')->assertNoContent();
        $this->getJson('/api/v1/account')->assertUnauthorized();
    }

    public function test_native_pairing_does_not_consume_codes_or_create_sessions_without_bound_identity(): void
    {
        foreach ([null, 'https://different.example.invalid/'] as $issuer) {
            $user = $this->nativeUser(['authentik_issuer' => $issuer]);
            $code = app(SyncPairingService::class)->generate($user)['pairing_token'];
            $this->postJson('/api/v1/pair/redeem', [
                'pairing_token' => $code, 'device_name' => 'Native',
            ])->assertStatus(409);
            $this->assertDatabaseHas('sync_pairing_codes', ['user_id' => $user->id, 'token_hash' => hash('sha256', $code)]);
        }
        $this->assertDatabaseCount('devices', 0);
        $this->assertDatabaseCount('sync_sessions', 0);
    }

    public function test_account_requires_an_owned_device_and_live_session(): void
    {
        $user = $this->nativeUser();
        $token = app(SyncAuthService::class)->createSessionToken($user, protocolVersion: 1)['token'];
        $this->withToken($token)->getJson('/api/v1/account')->assertUnauthorized();
        $other = $this->nativeUser();
        $device = Device::create(['user_id' => $other->id, 'device_id' => 'foreign', 'name' => 'Foreign', 'type' => 'desktop']);
        SyncSession::where('token_hash', hash('sha256', $token))->update(['device_id' => $device->id]);
        $this->getJson('/api/v1/account')->assertUnauthorized();
    }

    public function test_production_never_treats_the_development_issuer_as_oidc(): void
    {
        $user = $this->nativeUser();
        $this->app['env'] = 'production';
        config(['services.authentik.issuer' => 'https://accounts.example.invalid/application/o/midori/']);
        $this->getJson('/api/v1/capabilities')->assertOk()
            ->assertJsonPath('authentication.development', false)
            ->assertJsonPath('authentication.issuer', 'https://accounts.example.invalid/application/o/midori/');
        $code = app(SyncPairingService::class)->generate($user)['pairing_token'];
        $this->postJson('/api/v1/pair/redeem', ['pairing_token' => $code, 'device_name' => 'Native'])
            ->assertStatus(409)->assertJsonPath('error', 'identity_issuer_mismatch');
        $this->assertDatabaseCount('sync_sessions', 0);
    }

    public function test_configured_oidc_identity_preserves_issuer_bytes_and_subject(): void
    {
        config(['services.sync.local_dev' => false, 'services.authentik.issuer' => 'https://accounts.example.invalid/application/o/midori/']);
        $user = $this->nativeUser(['authentik_issuer' => config('services.authentik.issuer')]);
        $code = app(SyncPairingService::class)->generate($user)['pairing_token'];
        $response = $this->postJson('/api/v1/pair/redeem', ['pairing_token' => $code, 'device_name' => 'Native'])
            ->assertCreated()->assertJsonPath('identity.kind', 'oidc')
            ->assertJsonPath('identity.subject', $user->authentik_id)
            ->assertJsonPath('identity.issuer', config('services.authentik.issuer'));
        config(['services.authentik.issuer' => 'https://accounts.example.invalid/application/o/midori']);
        $this->withToken($response->json('token'))->getJson('/api/v1/account')
            ->assertStatus(409)->assertJsonPath('error', 'identity_issuer_mismatch');
    }

    public function test_invalid_issuer_configuration_does_not_advertise_native_pairing(): void
    {
        config(['services.sync.local_dev' => false]);
        foreach ([null, '', 'http://accounts.example.invalid/', 'https://user@accounts.example.invalid/',
            'https://accounts.example.invalid/?redirect=other', 'https://accounts.example.invalid/#fragment',
            "https://accounts.example.invalid/\n", 'urn:midori:sync:local'] as $issuer) {
            config(['services.authentik.issuer' => $issuer]);
            $this->getJson('/api/v1/capabilities')->assertOk()
                ->assertJsonPath('native_ready', false)
                ->assertJsonPath('authentication.pairing', false)
                ->assertJsonPath('authentication.issuer', null);
        }
    }
}
