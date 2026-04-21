<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\User;
use App\Services\SyncStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncStorageServiceTest extends TestCase
{
    use RefreshDatabase;

    private SyncStorageService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SyncStorageService::class);

        // Seed collections
        $this->seed(\Database\Seeders\CollectionSeeder::class);

        $this->user = User::factory()->create([
            'storage_quota_bytes' => 104857600, // 100MB
        ]);
    }

    public function test_upsert_and_get_record(): void
    {
        $result = $this->service->upsertRecord(
            userId: $this->user->id,
            collectionName: 'bookmarks',
            recordId: 'bk-1',
            payload: base64_encode('encrypted-data'),
        );

        $this->assertNotNull($result);
        $this->assertEquals('bk-1', $result['id']);
        $this->assertEquals(1, $result['version']);

        // Get it back
        $record = $this->service->getRecord($this->user->id, 'bookmarks', 'bk-1');
        $this->assertNotNull($record);
        $this->assertEquals('bk-1', $record['id']);
    }

    public function test_upsert_increments_version(): void
    {
        $this->service->upsertRecord(
            userId: $this->user->id,
            collectionName: 'bookmarks',
            recordId: 'bk-1',
            payload: base64_encode('v1'),
        );

        $result = $this->service->upsertRecord(
            userId: $this->user->id,
            collectionName: 'bookmarks',
            recordId: 'bk-1',
            payload: base64_encode('v2'),
        );

        $this->assertEquals(2, $result['version']);
    }

    public function test_get_records_with_delta_sync(): void
    {
        $this->service->upsertRecord(
            userId: $this->user->id,
            collectionName: 'bookmarks',
            recordId: 'bk-1',
            payload: base64_encode('data-1'),
        );

        $midpoint = microtime(true);
        usleep(10000); // 10ms

        $this->service->upsertRecord(
            userId: $this->user->id,
            collectionName: 'bookmarks',
            recordId: 'bk-2',
            payload: base64_encode('data-2'),
        );

        // Delta from midpoint should return only bk-2
        $records = $this->service->getRecords(
            userId: $this->user->id,
            collectionName: 'bookmarks',
            since: $midpoint,
        );

        $this->assertCount(1, $records);
        $this->assertEquals('bk-2', $records[0]['id']);
    }

    public function test_soft_delete_record(): void
    {
        $this->service->upsertRecord(
            userId: $this->user->id,
            collectionName: 'bookmarks',
            recordId: 'bk-1',
            payload: base64_encode('data'),
        );

        $this->service->deleteRecord($this->user->id, 'bookmarks', 'bk-1');

        // Should not appear in active records
        $records = $this->service->getRecords(userId: $this->user->id, collectionName: 'bookmarks');
        $this->assertCount(0, $records);

        // Should appear with includeDeleted
        $records = $this->service->getRecords(
            userId: $this->user->id,
            collectionName: 'bookmarks',
            includeDeleted: true,
        );
        $this->assertCount(1, $records);
        $this->assertTrue($records[0]['deleted']);
    }

    public function test_batch_upsert(): void
    {
        $records = [];
        for ($i = 1; $i <= 5; $i++) {
            $records[] = [
                'id' => "bk-$i",
                'payload' => base64_encode("data-$i"),
            ];
        }

        $results = $this->service->batchUpsert($this->user->id, 'bookmarks', $records);

        $this->assertCount(5, $results);

        $allRecords = $this->service->getRecords(userId: $this->user->id, collectionName: 'bookmarks');
        $this->assertCount(5, $allRecords);
    }

    public function test_delete_collection(): void
    {
        $this->service->upsertRecord(
            userId: $this->user->id,
            collectionName: 'bookmarks',
            recordId: 'bk-1',
            payload: base64_encode('d1'),
        );
        $this->service->upsertRecord(
            userId: $this->user->id,
            collectionName: 'bookmarks',
            recordId: 'bk-2',
            payload: base64_encode('d2'),
        );

        $this->service->deleteCollection($this->user->id, 'bookmarks');

        $records = $this->service->getRecords(userId: $this->user->id, collectionName: 'bookmarks');
        $this->assertCount(0, $records);
    }

    public function test_sync_info_returns_usage_and_quota(): void
    {
        $this->service->upsertRecord(
            userId: $this->user->id,
            collectionName: 'bookmarks',
            recordId: 'bk-1',
            payload: base64_encode('some-data'),
        );

        $info = $this->service->getSyncInfo($this->user->id);

        $this->assertArrayHasKey('quota_bytes', $info);
        $this->assertArrayHasKey('used_bytes', $info);
        $this->assertEquals(104857600, $info['quota_bytes']);
        $this->assertGreaterThan(0, $info['used_bytes']);
    }

    public function test_collection_status(): void
    {
        $this->service->upsertRecord(
            userId: $this->user->id,
            collectionName: 'bookmarks',
            recordId: 'bk-1',
            payload: base64_encode('data'),
        );
        $this->service->upsertRecord(
            userId: $this->user->id,
            collectionName: 'history',
            recordId: 'h-1',
            payload: base64_encode('data'),
        );

        $status = $this->service->getCollectionStatus($this->user->id);

        $this->assertArrayHasKey('bookmarks', $status);
        $this->assertArrayHasKey('history', $status);
        $this->assertEquals(1, $status['bookmarks']['record_count']);
        $this->assertEquals(1, $status['history']['record_count']);
    }

    public function test_delete_all_user_data(): void
    {
        $this->service->upsertRecord(
            userId: $this->user->id,
            collectionName: 'bookmarks',
            recordId: 'bk-1',
            payload: base64_encode('d1'),
        );
        $this->service->upsertRecord(
            userId: $this->user->id,
            collectionName: 'history',
            recordId: 'h-1',
            payload: base64_encode('d2'),
        );

        $this->service->deleteAllUserData($this->user->id);

        $this->assertCount(0, $this->service->getRecords(userId: $this->user->id, collectionName: 'bookmarks'));
        $this->assertCount(0, $this->service->getRecords(userId: $this->user->id, collectionName: 'history'));
    }

    public function test_conflict_detection_with_unmodified_since(): void
    {
        $result = $this->service->upsertRecord(
            userId: $this->user->id,
            collectionName: 'bookmarks',
            recordId: 'bk-1',
            payload: base64_encode('v1'),
        );

        $staleTimestamp = $result['modified_at'] - 1;

        // This should throw a conflict
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Conflict');

        $this->service->upsertRecord(
            userId: $this->user->id,
            collectionName: 'bookmarks',
            recordId: 'bk-1',
            payload: base64_encode('v2'),
            ifUnmodifiedSince: $staleTimestamp,
        );
    }
}
