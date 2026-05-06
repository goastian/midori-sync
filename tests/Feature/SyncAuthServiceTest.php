<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SyncSession;
use App\Models\User;
use App\Services\SyncAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Unit-style coverage of SyncAuthService.
 *
 * Verifies that:
 *   - tokens are returned in plaintext but stored as SHA-256 hashes
 *   - validation rejects expired / unknown tokens
 *   - revocation works per-token and per-user
 *   - cleanupExpired only removes rows whose expires_at is past
 */
class SyncAuthServiceTest extends TestCase
{
    use RefreshDatabase;

    private SyncAuthService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SyncAuthService::class);
        $this->user = User::factory()->create();
    }

    public function test_create_session_token_returns_plaintext_and_persists_hash(): void
    {
        $result = $this->service->createSessionToken($this->user, null, '203.0.113.5', 'Midori/1.0');

        $this->assertArrayHasKey('token', $result);
        $this->assertArrayHasKey('expires_at', $result);
        $this->assertArrayHasKey('expires_in', $result);
        $this->assertSame((int) config('services.sync.token_ttl', 3600), $result['expires_in']);

        $session = SyncSession::where('user_id', $this->user->id)->firstOrFail();
        $this->assertSame(hash('sha256', $result['token']), $session->token_hash);
        $this->assertNotSame($result['token'], $session->token_hash);
        $this->assertSame('203.0.113.5', $session->ip_address);
        $this->assertSame('Midori/1.0', $session->user_agent);
        $this->assertTrue($session->expires_at->isFuture());
    }

    public function test_create_session_token_truncates_long_user_agent(): void
    {
        $longUa = str_repeat('A', 1024);
        $result = $this->service->createSessionToken($this->user, null, null, $longUa);

        $session = SyncSession::where('token_hash', hash('sha256', $result['token']))->firstOrFail();
        $this->assertSame(512, strlen($session->user_agent));
    }

    public function test_validate_token_returns_session_for_valid_token(): void
    {
        $result = $this->service->createSessionToken($this->user);
        $session = $this->service->validateToken($result['token']);

        $this->assertNotNull($session);
        $this->assertSame($this->user->id, $session->user_id);
    }

    public function test_validate_token_returns_null_for_unknown_token(): void
    {
        $this->assertNull($this->service->validateToken('not-a-real-token'));
    }

    public function test_validate_token_returns_null_for_expired_session(): void
    {
        $result = $this->service->createSessionToken($this->user);

        SyncSession::query()->update(['expires_at' => now()->subMinute()]);

        $this->assertNull($this->service->validateToken($result['token']));
    }

    public function test_revoke_token_removes_only_target_session(): void
    {
        $a = $this->service->createSessionToken($this->user);
        $b = $this->service->createSessionToken($this->user);

        $this->assertTrue($this->service->revokeToken($a['token']));
        $this->assertNull($this->service->validateToken($a['token']));
        $this->assertNotNull($this->service->validateToken($b['token']));
    }

    public function test_revoke_token_returns_false_when_no_match(): void
    {
        $this->assertFalse($this->service->revokeToken('nope'));
    }

    public function test_revoke_all_for_user_removes_every_session(): void
    {
        $other = User::factory()->create();
        $this->service->createSessionToken($this->user);
        $this->service->createSessionToken($this->user);
        $survivor = $this->service->createSessionToken($other);

        $deleted = $this->service->revokeAllForUser($this->user->id);

        $this->assertSame(2, $deleted);
        $this->assertSame(0, SyncSession::where('user_id', $this->user->id)->count());
        $this->assertNotNull($this->service->validateToken($survivor['token']));
    }

    public function test_cleanup_expired_only_removes_past_sessions(): void
    {
        $live = $this->service->createSessionToken($this->user);
        $stale = $this->service->createSessionToken($this->user);

        SyncSession::where('token_hash', hash('sha256', $stale['token']))
            ->update(['expires_at' => now()->subHour()]);

        $deleted = $this->service->cleanupExpired();

        $this->assertSame(1, $deleted);
        $this->assertNotNull($this->service->validateToken($live['token']));
        $this->assertNull($this->service->validateToken($stale['token']));
    }

    public function test_create_session_token_persists_device_link(): void
    {
        $device = Device::create([
            'user_id' => $this->user->id,
            'device_id' => 'dev-auth-1',
            'name' => 'Auth Tester',
            'type' => 'desktop',
        ]);

        $this->service->createSessionToken($this->user, $device->id);

        $this->assertSame(
            $device->id,
            SyncSession::where('user_id', $this->user->id)->value('device_id'),
        );
    }
}
