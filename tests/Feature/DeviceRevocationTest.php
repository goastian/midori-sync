<?php

namespace Tests\Feature;

use App\Exceptions\SyncProtocolException;
use App\Models\Device;
use App\Models\SyncSession;
use App\Models\User;
use App\Services\SyncAuthService;
use App\Services\SyncIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeviceRevocationTest extends TestCase
{
    use RefreshDatabase;

    public static function surfaces(): array
    {
        return [['/api/v1/devices'], ['/devices']];
    }

    #[DataProvider('surfaces')]
    public function test_revoking_a_device_invalidates_its_actual_tokens_on_every_surface(string $path): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $device = Device::create(['user_id' => $user->id, 'device_id' => 'target', 'name' => 'Target', 'type' => 'desktop']);
        $foreign = Device::create(['user_id' => $other->id, 'device_id' => 'target', 'name' => 'Other account', 'type' => 'desktop']);
        $auth = app(SyncAuthService::class);
        $manager = $auth->createSessionToken($user)['token'];
        $target = $auth->createSessionToken($user, $device->id)['token'];
        $expired = $auth->createSessionToken($user, $device->id)['token'];
        $survivor = $auth->createSessionToken($other, $foreign->id)['token'];
        SyncSession::where('token_hash', hash('sha256', $expired))->update(['expires_at' => now()->subMinute()]);

        if ($path === '/devices') {
            $this->actingAs($user)->delete($path.'/target')->assertRedirect();
        } else {
            $this->withToken($manager)->deleteJson($path.'/target')->assertNoContent();
        }
        $this->assertDatabaseMissing('devices', ['id' => $device->id]);
        $this->assertDatabaseMissing('sync_sessions', ['token_hash' => hash('sha256', $target)]);
        $this->assertDatabaseMissing('sync_sessions', ['token_hash' => hash('sha256', $expired)]);
        $this->assertNotNull($auth->validateToken($manager));
        $this->assertNotNull($auth->validateToken($survivor));
        $this->withToken($target)->getJson('/api/v1/account')->assertUnauthorized();
        $this->withToken($target)->getJson('/api/library/links')->assertUnauthorized();
    }

    #[DataProvider('surfaces')]
    public function test_another_accounts_device_cannot_be_revoked(string $path): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $device = Device::create(['user_id' => $other->id, 'device_id' => 'foreign', 'name' => 'Foreign', 'type' => 'desktop']);
        $auth = app(SyncAuthService::class);
        $token = $auth->createSessionToken($user)['token'];
        $foreign = $auth->createSessionToken($other, $device->id)['token'];
        if ($path === '/devices') {
            $this->actingAs($user)->delete($path.'/foreign')->assertRedirect();
        } else {
            $this->withToken($token)->deleteJson($path.'/foreign')->assertNotFound();
        }
        $this->assertDatabaseHas('devices', ['id' => $device->id]);
        $this->assertNotNull($auth->validateToken($foreign));
    }

    public function test_native_tokens_with_missing_or_foreign_devices_are_rejected(): void
    {
        config(['services.sync.local_dev' => true]);
        $user = User::factory()->create(['authentik_issuer' => SyncIdentityService::DEVELOPMENT_ISSUER]);
        $other = User::factory()->create();
        $device = Device::create(['user_id' => $user->id, 'device_id' => 'native', 'name' => 'Native', 'type' => 'desktop']);
        $foreign = Device::create(['user_id' => $other->id, 'device_id' => 'foreign', 'name' => 'Foreign', 'type' => 'desktop']);
        $auth = app(SyncAuthService::class);
        $token = $auth->createSessionToken($user, $device->id, protocolVersion: 2)['token'];
        $this->assertNotNull($auth->validateToken($token));
        SyncSession::where('token_hash', hash('sha256', $token))->update(['device_id' => $foreign->id]);
        $this->assertNull($auth->validateToken($token));
        SyncSession::where('token_hash', hash('sha256', $token))->update(['device_id' => $device->id]);
        $device->delete();
        $this->assertNull($auth->validateToken($token));
        $this->withToken($token)->getJson('/api/library/links')->assertUnauthorized();
    }

    public function test_session_creation_cannot_attach_a_deleted_or_foreign_device_even_in_legacy_mode(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $foreign = Device::create(['user_id' => $other->id, 'device_id' => 'foreign', 'name' => 'Foreign', 'type' => 'desktop']);
        $auth = app(SyncAuthService::class);
        foreach ([$foreign->id, $foreign->id + 1000] as $id) {
            try {
                $auth->createSessionToken($user, $id);
                $this->fail('A session must not outlive its device ownership check.');
            } catch (SyncProtocolException $error) {
                $this->assertSame('device_required', $error->error);
            }
        }
        $this->assertDatabaseCount('sync_sessions', 0);
    }

    public function test_device_revocation_participates_in_the_callers_transaction(): void
    {
        $user = User::factory()->create();
        $device = Device::create(['user_id' => $user->id, 'device_id' => 'rollback', 'name' => 'Rollback', 'type' => 'desktop']);
        $auth = app(SyncAuthService::class);
        $token = $auth->createSessionToken($user, $device->id)['token'];
        try {
            DB::transaction(function () use ($auth, $user) {
                $this->assertSame(1, $auth->revokeDevice($user, 'rollback'));
                throw new \RuntimeException('synthetic rollback');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('synthetic rollback', $error->getMessage());
        }
        $this->assertDatabaseHas('devices', ['id' => $device->id]);
        $this->assertNotNull($auth->validateToken($token));
    }
}
