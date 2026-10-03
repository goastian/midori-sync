<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SyncSession;
use App\Models\User;
use App\Services\SyncNotificationTickets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncNotificationTicketTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/api/v1/sync/notifications/ticket';

    public function test_native_session_issues_a_one_use_device_bound_ticket(): void
    {
        [$user, $device, $session, $token] = $this->createNativeSessionFixture();
        $this->postJson(self::PATH)->assertUnauthorized();

        $first = $this->withToken($token)->postJson(self::PATH)->assertOk()
            ->assertJsonPath('version', 1)->json('ticket');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $first);
        $this->assertDatabaseHas('sync_notification_tickets', [
            'token_hash' => hash('sha256', $first), 'session_id' => $session->id,
            'user_id' => $user->id, 'device_id' => $device->id,
        ]);
        $this->assertDatabaseMissing('sync_notification_tickets', ['token_hash' => $first]);

        $tickets = app(SyncNotificationTickets::class);
        $this->assertSame([
            'user_id' => $user->id, 'session_id' => $session->id, 'device_id' => $device->id,
        ], $tickets->consume($first));
        $this->assertNull($tickets->consume($first));
    }

    public function test_reissue_expiry_and_revocation_invalidate_pending_tickets(): void
    {
        [, , $session, $token] = $this->createNativeSessionFixture();
        $tickets = app(SyncNotificationTickets::class);

        $first = $this->withToken($token)->postJson(self::PATH)->assertOk()->json('ticket');
        $second = $this->withToken($token)->postJson(self::PATH)->assertOk()->json('ticket');
        $this->assertNull($tickets->consume($first));
        DB::table('sync_notification_tickets')->where('token_hash', hash('sha256', $second))
            ->update(['expires_at' => now()->subSecond()]);
        $this->assertNull($tickets->consume($second));

        $third = $this->withToken($token)->postJson(self::PATH)->assertOk()->json('ticket');
        $session->delete();
        $this->assertNull($tickets->consume($third));
    }

    public function test_legacy_session_cannot_issue_notification_ticket(): void
    {
        [, , $session, $token] = $this->createNativeSessionFixture();
        $session->forceFill(['protocol_version' => 1])->save();
        $this->withToken($token)->postJson(self::PATH)->assertUnauthorized();
        $this->assertDatabaseCount('sync_notification_tickets', 0);
    }

    private function createNativeSessionFixture(): array
    {
        $user = User::factory()->create();
        $device = Device::create([
            'user_id' => $user->id, 'device_id' => (string) Str::uuid(),
            'name' => 'Native browser', 'type' => 'desktop',
        ]);
        $token = Str::random(64);
        $session = SyncSession::create([
            'user_id' => $user->id, 'device_id' => $device->id,
            'token_hash' => hash('sha256', $token), 'protocol_version' => 2,
            'expires_at' => now()->addHour(), 'created_at' => now(),
        ]);

        return [$user, $device, $session, $token];
    }
}
