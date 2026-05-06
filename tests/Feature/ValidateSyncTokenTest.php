<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SyncAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers App\Http\Middleware\ValidateSyncToken via real routed requests
 * on /api/v1, since the middleware is wired through the route group.
 */
class ValidateSyncTokenTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CollectionSeeder::class);
        $this->user = User::factory()->create();
    }

    public function test_missing_token_returns_401(): void
    {
        $this->getJson('/api/v1/sync/info')
            ->assertStatus(401)
            ->assertJson(['error' => 'Authentication required']);
    }

    public function test_invalid_token_returns_401(): void
    {
        $this->withToken('not-a-real-token')
            ->getJson('/api/v1/sync/info')
            ->assertStatus(401)
            ->assertJson(['error' => 'Invalid or expired token']);
    }

    public function test_expired_token_returns_401(): void
    {
        $token = app(SyncAuthService::class)->createSessionToken($this->user)['token'];

        \App\Models\SyncSession::query()->update(['expires_at' => now()->subSecond()]);

        $this->withToken($token)
            ->getJson('/api/v1/sync/info')
            ->assertStatus(401);
    }

    public function test_revoked_token_returns_401(): void
    {
        $auth = app(SyncAuthService::class);
        $token = $auth->createSessionToken($this->user)['token'];

        $auth->revokeToken($token);

        $this->withToken($token)
            ->getJson('/api/v1/sync/info')
            ->assertStatus(401);
    }

    public function test_valid_token_resolves_user_and_passes_through(): void
    {
        $token = app(SyncAuthService::class)->createSessionToken($this->user)['token'];

        $response = $this->withToken($token)->getJson('/api/v1/sync/info');

        $response->assertOk();
    }
}
