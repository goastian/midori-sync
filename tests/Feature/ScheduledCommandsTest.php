<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Record;
use App\Models\SyncSession;
use App\Models\User;
use App\Models\UserCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the artisan commands that wrap SyncAuthService::cleanupExpired
 * and SyncStorageService::cleanupExpiredRecords. The services are unit
 * tested elsewhere; this file pins the command surface itself (signature,
 * exit code, output, side effects).
 */
class ScheduledCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CollectionSeeder::class);
    }

    public function test_cleanup_expired_removes_records_and_sessions(): void
    {
        $user = User::factory()->create();
        $collection = Collection::first();

        Record::create([
            'id' => Str::uuid(),
            'user_id' => $user->id,
            'collection_id' => $collection->id,
            'record_id' => 'expired',
            'version' => 1,
            'payload' => 'a',
            'modified_at' => microtime(true),
            'ttl' => now()->subMinute(),
        ]);

        Record::create([
            'id' => Str::uuid(),
            'user_id' => $user->id,
            'collection_id' => $collection->id,
            'record_id' => 'fresh',
            'version' => 1,
            'payload' => 'b',
            'modified_at' => microtime(true),
            'ttl' => now()->addHour(),
        ]);

        SyncSession::create([
            'user_id' => $user->id,
            'token_hash' => str_repeat('e', 64),
            'expires_at' => now()->subMinute(),
            'created_at' => now(),
        ]);

        SyncSession::create([
            'user_id' => $user->id,
            'token_hash' => str_repeat('f', 64),
            'expires_at' => now()->addHour(),
            'created_at' => now(),
        ]);

        $this->artisan('sync:cleanup-expired')
            ->expectsOutputToContain('Cleaned up 1 expired records')
            ->expectsOutputToContain('1 expired sessions')
            ->assertSuccessful();

        $this->assertDatabaseMissing('records', ['record_id' => 'expired']);
        $this->assertDatabaseHas('records', ['record_id' => 'fresh']);
        $this->assertSame(1, SyncSession::count());
    }

    public function test_recalculate_usage_for_specific_user(): void
    {
        $user = User::factory()->create();
        $collection = Collection::first();

        Record::create([
            'id' => Str::uuid(),
            'user_id' => $user->id,
            'collection_id' => $collection->id,
            'record_id' => 'r1',
            'version' => 1,
            'payload' => str_repeat('x', 100),
            'modified_at' => microtime(true),
        ]);

        UserCollection::create([
            'user_id' => $user->id,
            'collection_id' => $collection->id,
            'record_count' => 999,
            'size_bytes' => 999,
            'last_modified' => 0,
        ]);

        $this->artisan('sync:recalculate-usage', ['--user' => $user->id])
            ->assertSuccessful();

        $stats = UserCollection::where('user_id', $user->id)
            ->where('collection_id', $collection->id)
            ->first();

        $this->assertSame(1, (int) $stats->record_count);
        $this->assertSame(100, (int) $stats->size_bytes);
    }

    public function test_recalculate_usage_for_all_users(): void
    {
        $u1 = User::factory()->create();
        $u2 = User::factory()->create();
        $collection = Collection::first();

        foreach ([$u1, $u2] as $user) {
            Record::create([
                'id' => Str::uuid(),
                'user_id' => $user->id,
                'collection_id' => $collection->id,
                'record_id' => 'r-' . $user->id,
                'version' => 1,
                'payload' => str_repeat('y', 50),
                'modified_at' => microtime(true),
            ]);
        }

        $this->artisan('sync:recalculate-usage')->assertSuccessful();

        foreach ([$u1, $u2] as $user) {
            $stats = UserCollection::where('user_id', $user->id)
                ->where('collection_id', $collection->id)
                ->first();
            $this->assertSame(1, (int) $stats->record_count);
            $this->assertSame(50, (int) $stats->size_bytes);
        }
    }
}
