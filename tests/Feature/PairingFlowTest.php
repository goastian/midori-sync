<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SyncAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Covers the extension pairing flow:
 *   POST /api/ext/pair         (authenticated) -> returns pairing_token
 *   POST /api/ext/pair/redeem  (unauthenticated) -> exchanges pairing_token
 *                                                    for a sync session token
 *
 * Tokens are one-shot (Cache::pull) and short-lived (5 minutes).
 */
class PairingFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CollectionSeeder::class);

        $this->user = User::factory()->create();
        $this->token = app(SyncAuthService::class)
            ->createSessionToken($this->user)['token'];
    }

    public function test_generate_returns_pairing_token_for_authenticated_user(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/ext/pair');

        $response->assertOk()->assertJsonStructure(['pairing_token', 'expires_in']);
        $this->assertSame(300, $response->json('expires_in'));

        $cached = Cache::get("pairing:{$response->json('pairing_token')}");
        $this->assertSame($this->user->id, $cached['user_id']);
    }

    public function test_generate_requires_authentication(): void
    {
        $this->postJson('/api/ext/pair')->assertStatus(401);
    }

    public function test_redeem_exchanges_pairing_token_for_sync_token_and_creates_device(): void
    {
        $generate = $this->withToken($this->token)
            ->postJson('/api/ext/pair')
            ->assertOk();

        $pairingToken = $generate->json('pairing_token');

        $redeem = $this->postJson('/api/ext/pair/redeem', [
            'pairing_token' => $pairingToken,
            'device_name' => 'New Device',
            'device_type' => 'mobile',
        ]);

        $redeem->assertCreated()
            ->assertJsonStructure(['token', 'expires_at', 'user' => ['id'], 'device' => ['id', 'name', 'type']])
            ->assertJsonPath('user.id', $this->user->id)
            ->assertJsonPath('device.name', 'New Device')
            ->assertJsonPath('device.type', 'mobile');

        // The new sync token must be valid against the auth service.
        $this->assertNotNull(
            app(SyncAuthService::class)->validateToken($redeem->json('token')),
        );

        // The pairing token is one-shot.
        $this->assertNull(Cache::get("pairing:{$pairingToken}"));
    }

    public function test_redeem_rejects_unknown_or_expired_pairing_token(): void
    {
        $this->postJson('/api/ext/pair/redeem', [
            'pairing_token' => 'totally-bogus',
            'device_name' => 'Whatever',
        ])->assertStatus(404);
    }

    public function test_redeem_cannot_be_replayed(): void
    {
        $pairingToken = $this->withToken($this->token)
            ->postJson('/api/ext/pair')
            ->json('pairing_token');

        $this->postJson('/api/ext/pair/redeem', [
            'pairing_token' => $pairingToken,
            'device_name' => 'Device A',
        ])->assertCreated();

        $this->postJson('/api/ext/pair/redeem', [
            'pairing_token' => $pairingToken,
            'device_name' => 'Device A',
        ])->assertStatus(404);
    }

    public function test_redeem_validates_input(): void
    {
        $this->postJson('/api/ext/pair/redeem', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pairing_token', 'device_name']);
    }
}
