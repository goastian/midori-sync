<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Record;
use App\Models\User;
use App\Services\SyncStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Focused tests for the native UPSERT path of SyncStorageService::batchUpsert.
 * Guarantees behaviour parity with the previous iterative implementation:
 *   - inserts new records with version=1
 *   - increments version for existing records (server-side)
 *   - preserves result ordering and reports per-item errors
 *   - soft-deletes via the deleted flag
 */
class BatchUpsertTest extends TestCase
{
    use RefreshDatabase;

    private SyncStorageService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CollectionSeeder::class);
        $this->service = app(SyncStorageService::class);
        $this->user = User::factory()->create(['storage_quota_bytes' => 104857600]);
    }

    public function test_inserts_new_records_with_version_one(): void
    {
        $results = $this->service->batchUpsert($this->user->id, 'bookmarks', [
            ['id' => 'bk-1', 'payload' => base64_encode('a')],
            ['id' => 'bk-2', 'payload' => base64_encode('b')],
        ]);

        $this->assertCount(2, $results);
        $this->assertSame('bk-1', $results[0]['id']);
        $this->assertSame('bk-2', $results[1]['id']);

        $collection = Collection::findByName('bookmarks');
        $versions = Record::where('collection_id', $collection->id)
            ->pluck('version', 'record_id');
        $this->assertEquals(1, $versions['bk-1']);
        $this->assertEquals(1, $versions['bk-2']);
    }

    public function test_bumps_version_for_existing_records_on_conflict(): void
    {
        $this->service->batchUpsert($this->user->id, 'bookmarks', [
            ['id' => 'bk-1', 'payload' => base64_encode('v1')],
        ]);
        $this->service->batchUpsert($this->user->id, 'bookmarks', [
            ['id' => 'bk-1', 'payload' => base64_encode('v2')],
            ['id' => 'bk-2', 'payload' => base64_encode('b-new')],
        ]);

        $collection = Collection::findByName('bookmarks');
        $rows = Record::where('collection_id', $collection->id)
            ->get(['record_id', 'version', 'payload'])
            ->keyBy('record_id');

        $this->assertEquals(2, $rows['bk-1']->version, 'existing record version must bump');
        $this->assertEquals(base64_encode('v2'), $rows['bk-1']->payload);
        $this->assertEquals(1, $rows['bk-2']->version, 'fresh insert stays at v=1');
    }

    public function test_reports_per_item_errors_without_aborting_batch(): void
    {
        $results = $this->service->batchUpsert($this->user->id, 'bookmarks', [
            ['id' => 'bk-1', 'payload' => base64_encode('ok')],
            ['payload' => base64_encode('missing id')],
            ['id' => 'bk-3', 'payload' => base64_encode('ok2')],
        ]);

        $this->assertSame('bk-1', $results[0]['id'] ?? null);
        $this->assertArrayHasKey('error', $results[1]);
        $this->assertSame('bk-3', $results[2]['id'] ?? null);
    }

    public function test_honors_deleted_flag_via_upsert(): void
    {
        $this->service->batchUpsert($this->user->id, 'bookmarks', [
            ['id' => 'bk-1', 'payload' => base64_encode('x')],
        ]);
        $this->service->batchUpsert($this->user->id, 'bookmarks', [
            ['id' => 'bk-1', 'payload' => '', 'deleted' => true],
        ]);

        $collection = Collection::findByName('bookmarks');
        $row = Record::where('collection_id', $collection->id)
            ->where('record_id', 'bk-1')->first();
        $this->assertTrue((bool) $row->deleted);
    }
}
