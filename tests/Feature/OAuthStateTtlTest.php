<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SyncAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

/**
 * Pins the TTL of OAuth state cache entries (`ext_auth:*`) and pairing
 * codes. Both must expire and their endpoints must answer 404 once expired.
 */
class OAuthStateTtlTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_ext_auth_state_uses_configured_ttl(): void
    {
        config()->set('services.sync.oauth_state_ttl', 123);

        $provider = Mockery::mock();
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('with')->andReturnSelf();
        $provider->shouldReceive('redirect')->andReturnSelf();
        $provider->shouldReceive('getTargetUrl')->andReturn('https://idp.example/authorize');

        Socialite::shouldReceive('driver')
            ->with('authentik')->andReturn($provider);

        $response = $this->getJson('/api/ext/auth/start?device_name=Test');
        $response->assertOk();
        $state = $response->json('state');

        $this->assertNotEmpty($state);
        $this->assertNotNull(Cache::get("ext_auth:{$state}"));

        Carbon::setTestNow(now()->addSeconds(124));
        $this->assertNull(Cache::get("ext_auth:{$state}"));

        $this->getJson("/api/ext/auth/poll?state={$state}")
            ->assertStatus(404);

        Carbon::setTestNow();
    }

    public function test_pairing_token_uses_configured_ttl(): void
    {
        config()->set('services.sync.pairing_ttl', 60);

        $user = User::factory()->create();
        $token = app(SyncAuthService::class)
            ->createSessionToken($user)['token'];

        $response = $this->withToken($token)->postJson('/api/ext/pair');
        $response->assertOk();
        $pairing = $response->json('pairing_token');
        $this->assertSame(60, $response->json('expires_in'));
        $this->assertDatabaseHas('sync_pairing_codes', ['token_hash' => hash('sha256', $pairing)]);

        Carbon::setTestNow(now()->addSeconds(61));
        $this->postJson('/api/v1/pair/redeem', ['pairing_token' => $pairing, 'device_name' => 'Late device'])
            ->assertNotFound();

        Carbon::setTestNow();
    }
}
