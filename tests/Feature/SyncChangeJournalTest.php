<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Device;
use App\Models\Record;
use App\Models\User;
use App\Services\SyncAuthService;
use App\Services\SyncStorageService;
use Database\Seeders\CollectionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SyncChangeJournalTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Device $device;

    private string $token;

    private SyncStorageService $storage;

    private const FEED = '/api/v1/sync/collections/bookmarks/changes';

    private const ACK = '/api/v1/sync/collections/bookmarks/ack';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CollectionSeeder::class);
        $this->user = User::factory()->create(['storage_quota_bytes' => 104857600]);
        $this->device = $this->createDevice($this->user, 'device-a');
        $this->token = $this->createNativeSessionToken($this->user, $this->device)['token'];
        $this->storage = app(SyncStorageService::class);
    }

    public function test_capabilities_are_public_but_do_not_claim_the_native_client_is_ready(): void
    {
        $this->getJson('/api/v1/capabilities')->assertOk()
            ->assertJsonPath('protocol', 'MSP')->assertJsonPath('native_ready', false);
        $this->getJson(self::FEED)->assertUnauthorized();
    }

    public function test_snapshot_pages_retain_old_values_while_later_writes_wait_for_next_poll(): void
    {
        $this->writeRecord('a', 'first');
        $this->writeRecord('b', 'second');
        $first = $this->page(null, 1);
        $this->assertSame('2', $first['snapshot_sequence']);
        $this->assertTrue($first['has_more']);

        $this->writeRecord('b', 'changed');
        $this->storage->deleteRecord($this->user->id, 'bookmarks', 'a');
        $second = $this->page($first['next_cursor'], 1);
        $this->assertSame('second', $second['changes'][0]['record']['payload']);
        $this->assertSame('1', $second['changes'][0]['record']['revision']);
        $this->assertFalse($second['has_more']);
        $this->assertSame('2', $second['snapshot_sequence']);

        $later = $this->page($second['next_cursor']);
        $this->assertSame(['3', '4'], array_column($later['changes'], 'sequence'));
        $this->assertSame('changed', $later['changes'][0]['record']['payload']);
        $this->assertTrue($later['changes'][1]['record']['deleted']);
        $this->assertSame('', $later['changes'][1]['record']['payload']);
        $this->assertSame('2', $later['changes'][1]['record']['revision']);
        $this->assertSame([], $this->page($later['next_cursor'])['changes']);
    }

    public function test_partial_page_replay_is_stable_and_get_does_not_acknowledge(): void
    {
        $this->writeRecord('a', 'first');
        $this->writeRecord('b', 'second');
        $first = $this->page(null, 1);
        $second = $this->page($first['next_cursor'], 1);
        $this->writeRecord('c', 'third');
        $replayed = $this->page($first['next_cursor'], 1);

        $this->assertSame($second['changes'], $replayed['changes']);
        $this->assertSame($second['snapshot_sequence'], $replayed['snapshot_sequence']);
        $this->assertDatabaseCount('sync_device_cursors', 0);
        $this->withToken($this->token)->postJson(self::ACK, ['cursor' => $second['next_cursor']])
            ->assertOk()->assertJsonPath('acknowledged_sequence', '2');
        $this->withToken($this->token)->postJson(self::ACK, ['cursor' => $first['next_cursor']])
            ->assertOk()->assertJsonPath('acknowledged_sequence', '2');
    }

    public function test_legacy_records_are_bootstrapped_once_before_the_first_mutation(): void
    {
        Record::create([
            'user_id' => $this->user->id,
            'collection_id' => Collection::findByName('bookmarks')->id,
            'record_id' => 'legacy',
            'version' => 7,
            'payload' => 'original',
            'modified_at' => 1,
        ]);
        $this->writeRecord('legacy', 'updated');

        $page = $this->page();
        $this->assertCount(2, $page['changes']);
        $this->assertSame('7', $page['changes'][0]['record']['revision']);
        $this->assertSame('original', $page['changes'][0]['record']['payload']);
        $this->assertSame('8', $page['changes'][1]['record']['revision']);
        $this->assertSame([], $this->page($page['next_cursor'])['changes']);
    }

    public function test_cursor_cannot_cross_accounts_collections_or_devices(): void
    {
        $this->writeRecord('a', 'private');
        $cursor = $this->page()['next_cursor'];
        $this->withToken($this->token)->getJson('/api/v1/sync/collections/history/changes?'.http_build_query(['cursor' => $cursor]))
            ->assertStatus(400)->assertJsonPath('error', 'invalid_cursor');

        $secondDevice = $this->createDevice($this->user, 'device-b');
        $otherToken = $this->createNativeSessionToken($this->user, $secondDevice)['token'];
        $this->withToken($otherToken)->postJson(self::ACK, ['cursor' => $cursor])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_cursor');

        $other = User::factory()->create();
        $otherDevice = $this->createDevice($other, 'device-a');
        $otherToken = $this->createNativeSessionToken($other, $otherDevice)['token'];
        $this->withToken($otherToken)->getJson(self::FEED.'?'.http_build_query(['cursor' => $cursor]))
            ->assertStatus(400)->assertJsonPath('error', 'invalid_cursor');
    }

    public function test_tampered_cursor_and_unbound_session_are_rejected(): void
    {
        $this->withToken($this->token)->getJson(self::FEED.'?cursor=not-a-cursor')
            ->assertStatus(400)->assertJsonPath('error', 'invalid_cursor');
        $unbound = app(SyncAuthService::class)->createSessionToken($this->user)['token'];
        $this->withToken($unbound)->getJson(self::FEED)->assertUnauthorized();
    }

    public function test_wipe_changes_generation_and_invalidates_old_cursors_and_acknowledgements(): void
    {
        $this->writeRecord('a', 'first');
        $before = $this->page();
        $this->withToken($this->token)->postJson(self::ACK, ['cursor' => $before['next_cursor']])->assertOk();
        $this->storage->deleteAllUserData($this->user->id);
        $this->withToken($this->token)->getJson(self::FEED.'?'.http_build_query(['cursor' => $before['next_cursor']]))
            ->assertStatus(409)->assertJsonPath('error', 'reset_required');
        $this->withToken($this->token)->postJson(self::ACK, ['cursor' => $before['next_cursor']])
            ->assertStatus(409)->assertJsonPath('error', 'reset_required');

        $after = $this->page();
        $this->assertNotSame($before['generation'], $after['generation']);
        $this->assertSame([], $after['changes']);
        $this->assertDatabaseCount('sync_device_cursors', 0);
    }

    public function test_collection_deletion_and_ttl_expiry_emit_tombstones_once(): void
    {
        $this->writeRecord('a', 'first');
        $this->writeRecord('b', 'second');
        $before = $this->page();
        $this->assertSame(2, $this->storage->deleteCollection($this->user->id, 'bookmarks'));
        $deleted = $this->page($before['next_cursor']);
        $this->assertCount(2, $deleted['changes']);
        foreach ($deleted['changes'] as $change) {
            $this->assertTrue($change['record']['deleted']);
            $this->assertSame('2', $change['record']['revision']);
        }
        $this->assertSame(0, $this->storage->deleteCollection($this->user->id, 'bookmarks'));

        $this->storage->upsertRecord($this->user->id, 'bookmarks', 'expires', 'expiring', ttl: now()->subMinute()->toIso8601String());
        $beforeExpiry = $this->page($deleted['next_cursor']);
        $this->assertSame(1, $this->storage->cleanupExpiredRecords());
        $this->assertSame(0, $this->storage->cleanupExpiredRecords());
        $expired = $this->page($beforeExpiry['next_cursor']);
        $this->assertCount(1, $expired['changes']);
        $this->assertTrue($expired['changes'][0]['record']['deleted']);
    }

    public function test_batch_writes_are_visible_in_the_same_feed(): void
    {
        $this->storage->batchUpsert($this->user->id, 'bookmarks', [
            ['id' => 'a', 'payload' => 'first'],
        ]);
        $before = $this->page();
        $this->assertSame('first', $before['changes'][0]['record']['payload']);
        $results = $this->storage->batchUpsert($this->user->id, 'bookmarks', [
            ['id' => 'a', 'payload' => 'must-not-survive-delete', 'deleted' => true],
            ['id' => 'b', 'payload' => 'new'],
            ['id' => 'b', 'payload' => 'duplicate'],
        ]);
        $this->assertArrayHasKey('error', $results[2]);
        $after = $this->page($before['next_cursor']);
        $this->assertCount(2, $after['changes']);
        $this->assertSame('', $after['changes'][0]['record']['payload']);
        $this->assertSame('2', $after['changes'][0]['record']['revision']);
    }

    public function test_rolled_back_write_leaves_neither_record_nor_sequence_gap(): void
    {
        $this->writeRecord('a', 'first');
        try {
            DB::transaction(function () {
                $this->writeRecord('b', 'rolled-back');
                throw new \RuntimeException('simulate failure');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('simulate failure', $e->getMessage());
        }
        $this->writeRecord('c', 'committed');
        $page = $this->page();
        $this->assertSame(['1', '2'], array_column($page['changes'], 'sequence'));
        $this->assertDatabaseMissing('records', ['record_id' => 'b']);
    }

    public function test_invalid_page_size_and_unknown_collection_do_not_create_streams(): void
    {
        $this->withToken($this->token)->getJson(self::FEED.'?limit=101')->assertUnprocessable();
        $this->withToken($this->token)->getJson('/api/v1/sync/collections/unknown/changes')->assertNotFound();
        $this->assertDatabaseCount('sync_streams', 0);
    }

    private function writeRecord(string $id, string $payload): void
    {
        $this->storage->upsertRecord($this->user->id, 'bookmarks', $id, $payload);
    }

    private function page(?string $cursor = null, int $limit = 100): array
    {
        return $this->withToken($this->token)->getJson(self::FEED.'?'.http_build_query(array_filter([
            'cursor' => $cursor,
            'limit' => $limit,
        ], fn ($value) => $value !== null)))->assertOk()->json();
    }

    private function createDevice(User $user, string $id): Device
    {
        return Device::create(['user_id' => $user->id, 'device_id' => $id, 'name' => $id, 'type' => 'desktop']);
    }
}
