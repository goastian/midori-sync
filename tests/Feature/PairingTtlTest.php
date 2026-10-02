<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SyncAuthService;
use App\Services\SyncIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pairing codes expire and cannot be redeemed after their TTL.
 */
class PairingTtlTest extends TestCase
{
    use RefreshDatabase;

    public function test_pairing_token_uses_configured_ttl(): void
    {
        config()->set('services.sync.pairing_ttl', 60);

        config(['services.sync.local_dev' => true]);
        $user = User::factory()->create(['authentik_issuer' => SyncIdentityService::DEVELOPMENT_ISSUER]);
        $token = app(SyncAuthService::class)
            ->createSessionToken($user)['token'];

        $response = $this->withToken($token)->postJson('/api/v1/pair');
        $response->assertOk();
        $pairing = $response->json('pairing_token');
        $this->assertSame(60, $response->json('expires_in'));
        $this->assertDatabaseHas('sync_pairing_codes', ['token_hash' => hash('sha256', $pairing)]);

        Carbon::setTestNow(now()->addSeconds(61));
        $this->postJson('/api/v1/pair/redeem', ['pairing_token' => $pairing, 'device_name' => 'Late device', 'native_client' => true])
            ->assertNotFound();

        Carbon::setTestNow();
    }
}
