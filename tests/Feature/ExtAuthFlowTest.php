<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

/**
 * Covers the extension OAuth handshake:
 *   GET  /api/ext/auth/start  -> returns auth_url + state, stores pending entry
 *   GET  /api/ext/auth/poll   -> returns 'pending' until backend completes,
 *                                 then returns the full payload and deletes
 *                                 the cache entry on first successful read.
 *
 * The Authentik redirect is mocked so the test does not depend on real
 * OAuth configuration.
 */
class ExtAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Socialite/Authentik config defaults are null in test env; supply
        // values so that any non-mocked path would not blow up obscurely.
        config()->set('services.authentik', [
            'client_id' => 'test-client',
            'client_secret' => 'test-secret',
            'base_url' => 'https://auth.example.test',
            'redirect' => 'https://app.example.test/auth/callback',
        ]);
    }

    public function test_start_returns_auth_url_and_state_and_caches_pending_entry(): void
    {
        $provider = Mockery::mock(Provider::class);
        $redirect = Mockery::mock();
        $redirect->shouldReceive('getTargetUrl')->andReturn('https://auth.example.test/authorize?state=stub');
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('with')->andReturnSelf();
        $provider->shouldReceive('redirect')->andReturn($redirect);

        Socialite::shouldReceive('driver')->with('authentik')->andReturn($provider);

        $response = $this->getJson('/api/ext/auth/start?device_name=Laptop&device_type=desktop&device_id=lap-1');

        $response->assertOk()->assertJsonStructure(['auth_url', 'state']);

        $state = $response->json('state');
        $this->assertStringStartsWith('ext_', $state);

        $cached = Cache::get("ext_auth:{$state}");
        $this->assertNotNull($cached);
        $this->assertSame('pending', $cached['status']);
        $this->assertSame('Laptop', $cached['device_name']);
        $this->assertSame('desktop', $cached['device_type']);
        $this->assertSame('lap-1', $cached['device_id']);
    }

    public function test_poll_requires_state_parameter(): void
    {
        $this->getJson('/api/ext/auth/poll')
            ->assertStatus(400)
            ->assertJson(['error' => 'Missing state parameter']);
    }

    public function test_poll_returns_404_for_unknown_state(): void
    {
        $this->getJson('/api/ext/auth/poll?state=ext_does_not_exist')
            ->assertStatus(404);
    }

    public function test_poll_returns_pending_while_handshake_is_in_flight(): void
    {
        $state = 'ext_pending_state';
        Cache::put("ext_auth:{$state}", [
            'device_name' => 'Lap',
            'device_type' => 'desktop',
            'device_id' => '',
            'status' => 'pending',
        ], now()->addMinutes(10));

        $this->getJson("/api/ext/auth/poll?state={$state}")
            ->assertOk()
            ->assertJson(['status' => 'pending']);

        // Cache entry must still exist while pending.
        $this->assertNotNull(Cache::get("ext_auth:{$state}"));
    }

    public function test_poll_returns_complete_payload_and_consumes_cache_entry(): void
    {
        $state = 'ext_done_state';
        $payload = [
            'status' => 'complete',
            'token' => 'sync-token-xxx',
            'expires_at' => now()->addHour()->toIso8601String(),
            'user' => ['id' => 1, 'email' => 'a@b.test', 'name' => 'A', 'avatar_url' => null],
            'device' => ['id' => 1, 'name' => 'Lap', 'type' => 'desktop'],
        ];
        Cache::put("ext_auth:{$state}", $payload, now()->addMinutes(10));

        $this->getJson("/api/ext/auth/poll?state={$state}")
            ->assertOk()
            ->assertJson([
                'status' => 'complete',
                'token' => 'sync-token-xxx',
            ]);

        // First successful read must consume the entry to prevent reuse.
        $this->assertNull(Cache::get("ext_auth:{$state}"));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
