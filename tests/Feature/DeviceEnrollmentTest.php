<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceEnrollment;
use App\Models\SyncSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeviceEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/api/v1/crypto/enrollments';

    private function key(): string
    {
        return rtrim(strtr(base64_encode("\x04".random_bytes(64)), '+/', '-_'), '=');
    }

    private function device(User $user): string
    {
        $device = Device::create([
            'user_id' => $user->id, 'device_id' => (string) Str::uuid(), 'name' => 'Dev', 'type' => 'desktop',
        ]);
        $token = Str::random(64);
        SyncSession::create([
            'user_id' => $user->id, 'device_id' => $device->id,
            'token_hash' => hash('sha256', $token), 'protocol_version' => 2,
            'expires_at' => now()->addHour(), 'created_at' => now(),
        ]);

        return $token;
    }

    private function enroll(string $token, ?string $key = null)
    {
        return $this->withToken($token)->postJson(self::PATH, ['public_key' => $key ?? $this->key(), 'device_name' => 'Laptop']);
    }

    public function test_create_and_list_without_payload(): void
    {
        $user = User::factory()->create();
        [$a, $b] = [$this->device($user), $this->device($user)];
        $id = $this->enroll($b)->assertCreated()->assertJsonPath('status', 'pending')
            ->assertJsonStructure(['id', 'status', 'expires_at'])->json('id');

        $this->withToken($a)->postJson(self::PATH."/$id/approve", [
            'approver_public_key' => $this->key(), 'payload' => 'secret',
        ])->assertNoContent();
        $id2 = $this->enroll($b)->json('id');

        $list = $this->withToken($a)->getJson(self::PATH)->assertOk();
        $list->assertJsonCount(1, 'enrollments')->assertJsonPath('enrollments.0.id', $id2)
            ->assertJsonPath('enrollments.0.device_name', 'Laptop');
        $this->assertStringNotContainsString('payload', $list->getContent());
        $this->assertStringNotContainsString('secret', $list->getContent());
    }

    public function test_invalid_public_keys_are_rejected(): void
    {
        $b = $this->device(User::factory()->create());
        foreach (['short', str_repeat('A', 87), base64_encode(random_bytes(64)), str_repeat('!', 87), str_repeat('A', 200)] as $bad) {
            $this->enroll($b, $bad)->assertUnprocessable();
        }
        $this->enroll($b, base64_encode("\x04".random_bytes(64)))->assertCreated();
    }

    public function test_approve_flow_and_creator_only_read(): void
    {
        $user = User::factory()->create();
        [$a, $b] = [$this->device($user), $this->device($user)];
        $id = $this->enroll($b)->json('id');

        $this->withToken($b)->getJson(self::PATH."/$id")->assertOk()->assertJsonPath('status', 'pending')
            ->assertJsonMissingPath('payload');
        $this->withToken($a)->getJson(self::PATH."/$id")->assertNotFound();

        $apk = $this->key();
        $this->withToken($a)->postJson(self::PATH."/$id/approve", ['approver_public_key' => $apk, 'payload' => 'opaque'])
            ->assertNoContent();
        $this->withToken($b)->getJson(self::PATH."/$id")->assertOk()->assertJsonPath('status', 'approved')
            ->assertJsonPath('approver_public_key', $apk)->assertJsonPath('payload', 'opaque');
        $this->withToken($a)->postJson(self::PATH."/$id/approve", ['approver_public_key' => $apk, 'payload' => 'x'])
            ->assertStatus(409);
        $this->withToken($a)->postJson(self::PATH."/$id/deny")->assertStatus(409);

        $this->withToken($b)->deleteJson(self::PATH."/$id")->assertNoContent();
        $this->assertDatabaseCount('sync_device_enrollments', 0);
    }

    public function test_deny(): void
    {
        $user = User::factory()->create();
        [$a, $b] = [$this->device($user), $this->device($user)];
        $id = $this->enroll($b)->json('id');
        $this->withToken($a)->postJson(self::PATH."/$id/deny")->assertNoContent();
        $this->withToken($b)->getJson(self::PATH."/$id")->assertOk()->assertJsonPath('status', 'denied')
            ->assertJsonMissingPath('payload');
    }

    public function test_cancel_is_creator_only(): void
    {
        $user = User::factory()->create();
        [$a, $b] = [$this->device($user), $this->device($user)];
        $id = $this->enroll($b)->json('id');
        $this->withToken($a)->deleteJson(self::PATH."/$id")->assertNotFound();
        $this->withToken($b)->deleteJson(self::PATH."/$id")->assertNoContent();
        $this->withToken($b)->deleteJson(self::PATH."/$id")->assertNotFound();
    }

    public function test_self_approval_is_rejected_even_from_another_session_of_same_device(): void
    {
        $user = User::factory()->create();
        $b = $this->device($user);
        $id = $this->enroll($b)->json('id');
        $body = ['approver_public_key' => $this->key(), 'payload' => 'x'];
        $this->withToken($b)->postJson(self::PATH."/$id/approve", $body)->assertForbidden();
        $this->withToken($b)->postJson(self::PATH."/$id/deny")->assertForbidden();

        $second = Str::random(64);
        SyncSession::create([
            'user_id' => $user->id, 'device_id' => Device::where('user_id', $user->id)->value('id'),
            'token_hash' => hash('sha256', $second), 'protocol_version' => 2,
            'expires_at' => now()->addHour(), 'created_at' => now(),
        ]);
        $this->withToken($second)->postJson(self::PATH."/$id/approve", $body)->assertForbidden();
    }

    public function test_expiry(): void
    {
        $user = User::factory()->create();
        [$a, $b] = [$this->device($user), $this->device($user)];
        $id = $this->enroll($b)->json('id');
        DB::table('sync_device_enrollments')->update(['expires_at' => now()->subSecond()]);

        $this->withToken($b)->getJson(self::PATH."/$id")->assertOk()->assertJsonPath('status', 'expired');
        $this->withToken($a)->getJson(self::PATH)->assertJsonCount(0, 'enrollments');
        $this->withToken($a)->postJson(self::PATH."/$id/approve", ['approver_public_key' => $this->key(), 'payload' => 'x'])
            ->assertStatus(409);

        $this->artisan('sync:cleanup-expired')->assertSuccessful();
        $this->assertSame(0, DeviceEnrollment::count());
    }

    public function test_cross_user_isolation(): void
    {
        $owner = User::factory()->create();
        $b = $this->device($owner);
        $other = $this->device(User::factory()->create());
        $id = $this->enroll($b)->json('id');

        $this->withToken($other)->getJson(self::PATH)->assertJsonCount(0, 'enrollments');
        $this->withToken($other)->getJson(self::PATH."/$id")->assertNotFound();
        $this->withToken($other)->postJson(self::PATH."/$id/approve", ['approver_public_key' => $this->key(), 'payload' => 'x'])
            ->assertNotFound();
        $this->withToken($other)->postJson(self::PATH."/$id/deny")->assertNotFound();
        $this->withToken($other)->deleteJson(self::PATH."/$id")->assertNotFound();
    }

    public function test_limit_of_three_and_replacement_by_same_session(): void
    {
        $user = User::factory()->create();
        $tokens = [$this->device($user), $this->device($user), $this->device($user), $this->device($user)];
        $first = $this->enroll($tokens[0])->assertCreated()->json('id');
        $again = $this->enroll($tokens[0])->assertCreated()->json('id');
        $this->assertNotSame($first, $again);
        $this->assertDatabaseCount('sync_device_enrollments', 1);

        $this->enroll($tokens[1])->assertCreated();
        $this->enroll($tokens[2])->assertCreated();
        $this->enroll($tokens[3])->assertStatus(429)->assertJsonPath('error', 'too_many_enrollments');
        $this->enroll($tokens[0])->assertCreated();
    }

    public function test_requires_authentication_and_capability_is_advertised(): void
    {
        $this->postJson(self::PATH, ['public_key' => $this->key()])->assertUnauthorized();
        $this->getJson('/api/v1/capabilities')->assertOk()->assertJsonPath('crypto.enrollment', true)
            ->assertJsonPath('client_recovery.registry_auth_version', 2);
    }
}
