<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Record;
use App\Models\SyncSession;
use App\Models\User;
use App\Services\SyncAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the extension-side device management surface:
 *   GET    /api/ext/devices          -> list devices
 *   PATCH  /api/ext/devices/{id}     -> rename
 *   DELETE /api/ext/devices/{id}     -> revoke (drops device + sessions)
 *   DELETE /api/ext/data             -> wipe all user data on the server
 */
class ExtDeviceFlowTest extends TestCase
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

    public function test_index_lists_user_devices_only(): void
    {
        $other = User::factory()->create();
        Device::create([
            'user_id' => $this->user->id,
            'device_id' => 'mine-1',
            'name' => 'Mine',
            'type' => 'desktop',
        ]);
        Device::create([
            'user_id' => $other->id,
            'device_id' => 'theirs-1',
            'name' => 'Theirs',
            'type' => 'desktop',
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/ext/devices')
            ->assertOk();

        $ids = collect($response->json('devices'))->pluck('id')->all();
        $this->assertContains('mine-1', $ids);
        $this->assertNotContains('theirs-1', $ids);
    }

    public function test_rename_updates_only_owned_device(): void
    {
        Device::create([
            'user_id' => $this->user->id,
            'device_id' => 'mine-1',
            'name' => 'Old',
            'type' => 'desktop',
        ]);

        $this->withToken($this->token)
            ->patchJson('/api/ext/devices/mine-1', ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('name', 'Renamed');

        $this->assertSame('Renamed', Device::where('device_id', 'mine-1')->first()->name);
    }

    public function test_rename_returns_404_for_unknown_or_other_user_device(): void
    {
        $other = User::factory()->create();
        Device::create([
            'user_id' => $other->id,
            'device_id' => 'theirs-1',
            'name' => 'Theirs',
            'type' => 'desktop',
        ]);

        $this->withToken($this->token)
            ->patchJson('/api/ext/devices/theirs-1', ['name' => 'Hijack'])
            ->assertStatus(404);
    }

    public function test_revoke_drops_device_and_its_sync_sessions(): void
    {
        $device = Device::create([
            'user_id' => $this->user->id,
            'device_id' => 'mine-1',
            'name' => 'Mine',
            'type' => 'desktop',
        ]);
        // Attach a session bound to that device.
        SyncSession::create([
            'user_id' => $this->user->id,
            'device_id' => $device->id,
            'token_hash' => hash('sha256', 'extra-token'),
            'expires_at' => now()->addDay(),
            'created_at' => now(),
        ]);

        $this->withToken($this->token)
            ->deleteJson('/api/ext/devices/mine-1')
            ->assertNoContent();

        $this->assertNull(Device::find($device->id));
        $this->assertSame(0, SyncSession::where('user_id', $this->user->id)
            ->where('device_id', $device->id)->count());
    }

    public function test_wipe_deletes_all_user_records(): void
    {
        $collectionId = \App\Models\Collection::first()->id;
        Record::create([
            'user_id' => $this->user->id,
            'collection_id' => $collectionId,
            'record_id' => 'r1',
            'payload' => 'enc',
            'modified_at' => 1000,
            'version' => 1,
            'deleted' => false,
        ]);

        $this->withToken($this->token)
            ->deleteJson('/api/ext/data')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(0, Record::where('user_id', $this->user->id)->count());
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->getJson('/api/ext/devices')->assertStatus(401);
        $this->patchJson('/api/ext/devices/x', ['name' => 'y'])->assertStatus(401);
        $this->deleteJson('/api/ext/devices/x')->assertStatus(401);
        $this->deleteJson('/api/ext/data')->assertStatus(401);
    }
}
