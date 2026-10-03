<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\CollectionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Verifies the `sync` rate limiter splits read and write buckets so that
 * traffic on one method does not exhaust the budget of the other.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CollectionSeeder::class);

        config()->set('services.sync.rate_limit_read', 3);
        config()->set('services.sync.rate_limit_write', 2);

        $this->user = User::factory()->create(['storage_quota_bytes' => 104857600]);
        $this->token = $this->createNativeSessionToken($this->user)['token'];

        RateLimiter::clear("sync:r:{$this->user->id}");
        RateLimiter::clear("sync:w:{$this->user->id}");
    }

    public function test_read_limit_is_enforced_per_bucket(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->withToken($this->token)->getJson('/api/v1/devices')->assertOk();
        }

        $this->withToken($this->token)
            ->getJson('/api/v1/devices')
            ->assertStatus(429);
    }

    public function test_read_traffic_does_not_consume_write_budget(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->withToken($this->token)->getJson('/api/v1/devices')->assertOk();
        }

        $this->withToken($this->token)
            ->deleteJson('/api/v1/devices/not-found')->assertNotFound();
    }
}
