<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SyncAuthService;
use App\Services\SyncIdentityService;
use Database\Seeders\CollectionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers the native pairing flow:
 *   POST /api/v1/pair         (authenticated) -> returns pairing_token
 *   POST /api/v1/pair/redeem  (unauthenticated) -> exchanges pairing_token
 *                                                    for a sync session token
 *
 * Tokens are hashed, consumed in a transaction and short-lived (5 minutes).
 */
class PairingFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CollectionSeeder::class);

        config(['services.sync.local_dev' => true]);
        $this->user = User::factory()->create(['authentik_issuer' => SyncIdentityService::DEVELOPMENT_ISSUER]);
        $this->token = $this->createNativeSessionToken($this->user)['token'];
    }

    public function test_generate_returns_pairing_token_for_authenticated_user(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/v1/pair');

        $response->assertOk()->assertJsonStructure(['pairing_token', 'expires_in']);
        $this->assertSame(300, $response->json('expires_in'));

        $this->assertDatabaseHas('sync_pairing_codes', [
            'token_hash' => hash('sha256', $response->json('pairing_token')),
            'user_id' => $this->user->id,
        ]);
    }

    public function test_generate_requires_authentication(): void
    {
        $this->postJson('/api/v1/pair')->assertStatus(401);
    }

    public function test_redeem_exchanges_pairing_token_for_sync_token_and_creates_device(): void
    {
        $generate = $this->withToken($this->token)
            ->postJson('/api/v1/pair')
            ->assertOk();

        $pairingToken = $generate->json('pairing_token');

        $redeem = $this->postJson('/api/v1/pair/redeem', [
            'pairing_token' => $pairingToken,
            'device_name' => 'New Device',

            'device_type' => 'mobile',
        ]);

        $redeem->assertCreated()
            ->assertJsonStructure(['token', 'expires_at', 'user' => ['id'], 'device' => ['id', 'name', 'type']])
            ->assertJsonPath('user.id', (string) $this->user->id)
            ->assertJsonPath('device.name', 'New Device')
            ->assertJsonPath('device.type', 'mobile');

        // The new sync token must be valid against the auth service.
        $this->assertNotNull(
            app(SyncAuthService::class)->validateToken($redeem->json('token')),
        );
        $this->assertDatabaseHas('sync_sessions', [
            'token_hash' => hash('sha256', $redeem->json('token')),
            'protocol_version' => 2,
        ]);

        // The pairing token is one-shot.
        $this->assertDatabaseMissing('sync_pairing_codes', ['token_hash' => hash('sha256', $pairingToken)]);
    }

    public function test_redeem_rejects_unknown_or_expired_pairing_token(): void
    {
        $this->postJson('/api/v1/pair/redeem', [
            'pairing_token' => 'totally-bogus',
            'device_name' => 'Whatever',

        ])->assertStatus(404);
    }

    public function test_redeem_cannot_be_replayed(): void
    {
        $pairingToken = $this->withToken($this->token)
            ->postJson('/api/v1/pair')
            ->json('pairing_token');

        $this->postJson('/api/v1/pair/redeem', [
            'pairing_token' => $pairingToken,
            'device_name' => 'Device A',

        ])->assertCreated();

        $this->postJson('/api/v1/pair/redeem', [
            'pairing_token' => $pairingToken,
            'device_name' => 'Device A',

        ])->assertStatus(404);
    }

    public function test_redeem_validates_input(): void
    {
        $this->postJson('/api/v1/pair/redeem', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pairing_token', 'device_name']);
    }

    public function test_canonical_pairing_binds_distinct_devices_even_with_the_same_name(): void
    {
        $devices = [];
        for ($i = 0; $i < 2; $i++) {
            $code = $this->withToken($this->token)->postJson('/api/v1/pair')->assertOk()->json('pairing_token');
            $redeemed = $this->postJson('/api/v1/pair/redeem', [
                'pairing_token' => strtolower(implode('-', str_split($code, 4))),
                'device_name' => 'Midori Desktop',

            ])->assertCreated();
            $devices[] = $redeemed->json('device.id');
            $this->withToken($redeemed->json('token'))->getJson('/api/v1/sync/collections/bookmarks/changes')->assertOk();
        }
        $this->assertNotSame($devices[0], $devices[1]);
    }

    public function test_expired_code_is_not_redeemable_and_creates_no_session(): void
    {
        $code = $this->withToken($this->token)->postJson('/api/v1/pair')->assertOk()->json('pairing_token');
        DB::table('sync_pairing_codes')->update(['expires_at' => now()->subSecond()]);
        $this->postJson('/api/v1/pair/redeem', ['pairing_token' => $code, 'device_name' => 'Expired'])
            ->assertNotFound();
        $this->assertDatabaseCount('devices', 1);
        $this->assertDatabaseCount('sync_sessions', 1);
    }
}
