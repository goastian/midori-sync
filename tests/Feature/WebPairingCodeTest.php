<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SyncIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class WebPairingCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sync.local_dev' => true]);
    }

    public function test_authenticated_web_account_can_generate_and_redeem_a_native_code(): void
    {
        $user = User::factory()->create(['authentik_issuer' => SyncIdentityService::DEVELOPMENT_ISSUER]);

        $generated = $this->actingAs($user)->postJson('/devices/pairing-code')
            ->assertOk()->assertJsonStructure(['pairing_token', 'expires_in'])
            ->assertHeader('Cache-Control', 'no-store, private');

        $code = $generated->json('pairing_token');
        $this->assertMatchesRegularExpression('/^[A-F0-9]{16}$/', $code);
        $this->assertSame(300, $generated->json('expires_in'));
        $this->assertDatabaseHas('sync_pairing_codes', [
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $code),
        ]);
        $this->assertDatabaseMissing('sync_pairing_codes', ['token_hash' => $code]);

        $paired = $this->postJson('/api/v1/pair/redeem', [
            'pairing_token' => $code,
            'device_name' => 'Midori Desktop',
            'native_client' => true,
            'native_refresh' => true,
        ])->assertCreated()->assertJsonPath('identity.subject', $user->authentik_id);
        $this->assertNotEmpty($paired->json('refresh_token'));
        $this->assertDatabaseHas('devices', ['user_id' => $user->id, 'name' => 'Midori Desktop']);
        $this->postJson('/api/v1/pair/redeem', [
            'pairing_token' => $code,
            'device_name' => 'Midori Desktop',
            'native_client' => true,
        ])->assertNotFound();
    }

    public function test_web_code_requires_a_logged_in_account_with_a_bound_identity(): void
    {
        $this->postJson('/devices/pairing-code')->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/devices/pairing-code')
            ->assertStatus(409)->assertJsonPath('error', 'native_identity_required');

        $user->update(['authentik_issuer' => 'https://other.example.test/']);
        $this->postJson('/devices/pairing-code')
            ->assertStatus(409)->assertJsonPath('error', 'identity_issuer_mismatch');
        $this->assertSame(0, DB::table('sync_pairing_codes')->count());
    }

    public function test_web_code_generation_is_limited_per_account(): void
    {
        $user = User::factory()->create(['authentik_issuer' => SyncIdentityService::DEVELOPMENT_ISSUER]);
        $this->actingAs($user);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/devices/pairing-code')->assertOk();
        }
        $this->postJson('/devices/pairing-code')->assertStatus(429);
        $this->assertSame(5, DB::table('sync_pairing_codes')->count());
    }

    public function test_isolated_local_launcher_can_open_devices_and_generate_a_code(): void
    {
        $this->assertTrue(Route::has('auth.local'));
        $user = User::where('authentik_id', 'midori-local-development')->first();
        if (! $user) {
            User::factory()->create([
                'authentik_id' => 'midori-local-development',
                'authentik_issuer' => SyncIdentityService::DEVELOPMENT_ISSUER,
            ]);
        }

        $this->get('/devices')->assertRedirect('/');
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost'])
            ->post('/auth/local')->assertRedirect('/devices');
        $this->get('/devices')->assertOk();
        $this->postJson('/devices/pairing-code')->assertOk();
    }

    public function test_local_launcher_login_rejects_non_loopback_clients(): void
    {
        $this->assertTrue(Route::has('auth.local'));
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10', 'HTTP_HOST' => 'localhost'])
            ->post('/auth/local')->assertNotFound();
    }

    public function test_web_login_binds_a_previously_unbound_account_to_the_configured_oidc_issuer(): void
    {
        config([
            'services.sync.local_dev' => false,
            'services.authentik.issuer' => 'https://accounts.example.test/application/o/midori/',
        ]);
        $user = User::factory()->create(['authentik_id' => 'existing-subject', 'authentik_issuer' => null]);
        $identity = Mockery::mock();
        $identity->shouldReceive('getId')->andReturn('existing-subject');
        $identity->shouldReceive('getEmail')->andReturn($user->email);
        $identity->shouldReceive('getName')->andReturn($user->name);
        $identity->shouldReceive('getAvatar')->andReturn(null);
        $provider = Mockery::mock();
        $provider->shouldReceive('user')->once()->andReturn($identity);
        Socialite::shouldReceive('driver')->with('authentik')->andReturn($provider);

        $this->get('/auth/callback')->assertRedirect('/dashboard');
        $this->assertSame(config('services.authentik.issuer'), $user->fresh()->authentik_issuer);
        $this->postJson('/devices/pairing-code')->assertOk();
    }

    public function test_existing_web_session_can_pair_after_issuer_is_configured_without_signing_in_again(): void
    {
        config(['services.sync.local_dev' => false, 'services.authentik.issuer' => null]);
        $user = User::factory()->create(['authentik_issuer' => null]);
        $this->actingAs($user)->postJson('/devices/pairing-code')
            ->assertStatus(503)->assertJsonPath('error', 'server_issuer_not_configured');
        $this->assertSame(0, DB::table('sync_pairing_codes')->count());

        $issuer = 'https://accounts.example.test/application/o/midori-sync/';
        config(['services.authentik.issuer' => $issuer]);
        $code = $this->postJson('/devices/pairing-code')->assertOk()->json('pairing_token');
        $this->assertSame($issuer, $user->fresh()->authentik_issuer);
        $this->postJson('/api/v1/pair/redeem', [
            'pairing_token' => $code,
            'device_name' => 'Midori Desktop',
            'native_client' => true,
        ])->assertCreated()->assertJsonPath('identity.issuer', $issuer);
    }
}
