<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\SyncSession;
use App\Models\User;
use App\Services\SyncAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\CollectionSeeder::class);

        $this->user = User::factory()->create([
            'storage_quota_bytes' => 104857600,
        ]);

        // Create a valid sync session token
        $authService = app(SyncAuthService::class);
        $result = $authService->createSessionToken($this->user);
        $this->token = $result['token'];
    }

    public function test_get_sync_info(): void
    {
        $response = $this->withToken($this->token)
            ->getJson('/api/v1/sync/info');

        $response->assertOk()
            ->assertJsonStructure(['quota_bytes', 'used_bytes', 'last_modified']);
    }

    public function test_get_sync_status(): void
    {
        $response = $this->withToken($this->token)
            ->getJson('/api/v1/sync/status');

        $response->assertOk();
    }

    public function test_upsert_record(): void
    {
        $response = $this->withToken($this->token)
            ->putJson('/api/v1/collections/bookmarks/bk-1', [
                'payload' => base64_encode('encrypted-bookmark'),
            ]);

        $response->assertOk()
            ->assertJsonFragment(['id' => 'bk-1']);
    }

    public function test_get_record(): void
    {
        // Create first
        $this->withToken($this->token)
            ->putJson('/api/v1/collections/bookmarks/bk-1', [
                'payload' => base64_encode('data'),
            ]);

        // Read
        $response = $this->withToken($this->token)
            ->getJson('/api/v1/collections/bookmarks/bk-1');

        $response->assertOk()
            ->assertJsonFragment(['id' => 'bk-1']);
    }

    public function test_list_records(): void
    {
        $this->withToken($this->token)
            ->putJson('/api/v1/collections/bookmarks/bk-1', ['payload' => base64_encode('d1')]);
        $this->withToken($this->token)
            ->putJson('/api/v1/collections/bookmarks/bk-2', ['payload' => base64_encode('d2')]);

        $response = $this->withToken($this->token)
            ->getJson('/api/v1/collections/bookmarks');

        $response->assertOk()
            ->assertJsonCount(2, 'records');
    }

    public function test_delete_record(): void
    {
        $this->withToken($this->token)
            ->putJson('/api/v1/collections/bookmarks/bk-1', ['payload' => base64_encode('d1')]);

        $response = $this->withToken($this->token)
            ->deleteJson('/api/v1/collections/bookmarks/bk-1');

        $response->assertNoContent();
        $list = $this->withToken($this->token)
            ->getJson('/api/v1/collections/bookmarks');
        $list->assertJsonCount(0, 'records');
    }

    public function test_batch_upsert(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/v1/collections/bookmarks', [
                'records' => [
                    ['id' => 'bk-1', 'payload' => base64_encode('d1')],
                    ['id' => 'bk-2', 'payload' => base64_encode('d2')],
                    ['id' => 'bk-3', 'payload' => base64_encode('d3')],
                ],
            ]);

        $response->assertOk();

        $list = $this->withToken($this->token)
            ->getJson('/api/v1/collections/bookmarks');
        $list->assertJsonCount(3, 'records');
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/v1/sync/info');
        $response->assertUnauthorized();
    }

    public function test_expired_token_returns_401(): void
    {
        // Expire the session
        SyncSession::query()->update(['expires_at' => now()->subHour()]);

        $response = $this->withToken($this->token)
            ->getJson('/api/v1/sync/info');

        $response->assertUnauthorized();
    }

    public function test_device_upsert(): void
    {
        $response = $this->withToken($this->token)
            ->putJson('/api/v1/devices/dev-123', [
                'name' => 'Test Device',
                'type' => 'desktop',
                'os' => 'Linux',
                'browser_version' => 'Midori 12.0',
            ]);

        $response->assertOk()
            ->assertJsonFragment(['id' => 'dev-123']);
    }

    public function test_device_list(): void
    {
        $this->withToken($this->token)
            ->putJson('/api/v1/devices/dev-1', [
                'name' => 'Device 1',
                'type' => 'desktop',
            ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/v1/devices');

        $response->assertOk();
    }

    public function test_crypto_key_bundle(): void
    {
        $bundle = base64_encode('encrypted-key-bundle');

        // Store
        $response = $this->withToken($this->token)
            ->postJson('/api/v1/crypto/keys', [
                'encrypted_bundle' => $bundle,
            ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['version' => 1]);

        // Retrieve
        $response = $this->withToken($this->token)
            ->getJson('/api/v1/crypto/keys');

        $response->assertOk()
            ->assertJsonFragment(['encrypted_bundle' => $bundle]);
    }

    public function test_delete_token_revokes_session(): void
    {
        $response = $this->withToken($this->token)
            ->deleteJson('/api/v1/auth/token');

        $response->assertNoContent();

        // Token should now be invalid
        $response = $this->withToken($this->token)
            ->getJson('/api/v1/sync/info');

        $response->assertUnauthorized();
    }
}
