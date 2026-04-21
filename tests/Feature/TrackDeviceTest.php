<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SyncSession;
use App\Models\User;
use App\Services\SyncAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers App\Http\Middleware\TrackDevice. The middleware is attached to
 * both /api/v1 and /api/ext authenticated groups, so we exercise it
 * through real routed requests rather than unit-testing it in isolation.
 */
class TrackDeviceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;
    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CollectionSeeder::class);

        $this->user = User::factory()->create(['storage_quota_bytes' => 104857600]);
        $this->device = Device::create([
            'user_id' => $this->user->id,
            'device_id' => 'dev-track-1',
            'name' => 'Track Tester',
            'type' => 'desktop',
            'os' => 'Linux',
            'browser_version' => 'Midori 13.0',
        ]);

        /** @var SyncAuthService $auth */
        $auth = app(SyncAuthService::class);
        $result = $auth->createSessionToken($this->user, $this->device->id);
        $this->token = $result['token'];
    }

    public function test_updates_device_last_sync_at_on_authenticated_request(): void
    {
        $this->device->forceFill(['last_sync_at' => null])->save();

        $this->withToken($this->token)
            ->getJson('/api/v1/sync/info')
            ->assertOk();

        $this->device->refresh();
        $this->assertNotNull($this->device->last_sync_at);
    }

    public function test_bumps_session_last_used_at(): void
    {
        $session = SyncSession::where('user_id', $this->user->id)->first();
        $session->forceFill(['last_used_at' => null])->save();

        $this->withToken($this->token)
            ->getJson('/api/v1/sync/info')
            ->assertOk();

        $session->refresh();
        $this->assertNotNull($session->last_used_at);
    }

    public function test_no_bookkeeping_on_unauthenticated_request(): void
    {
        // No token -> ValidateSyncToken short-circuits and TrackDevice
        // should never touch the device/session rows.
        $before = $this->device->last_sync_at;

        $this->getJson('/api/v1/sync/info')->assertUnauthorized();

        $this->device->refresh();
        $this->assertEquals($before, $this->device->last_sync_at);
    }
}
