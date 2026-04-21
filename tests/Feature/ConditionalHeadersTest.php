<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SyncAuthService;
use App\Services\SyncStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for ETag / If-None-Match / If-Modified-Since on /api/v1 read endpoints.
 */
class ConditionalHeadersTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CollectionSeeder::class);
        $this->user = User::factory()->create(['storage_quota_bytes' => 104857600]);
        $this->token = app(SyncAuthService::class)
            ->createSessionToken($this->user)['token'];
    }

    public function test_sync_info_returns_etag_and_honors_if_none_match(): void
    {
        app(SyncStorageService::class)->upsertRecord(
            userId: $this->user->id,
            collectionName: 'bookmarks',
            recordId: 'bk-1',
            payload: base64_encode('x'),
        );

        $first = $this->withToken($this->token)->getJson('/api/v1/sync/info');
        $first->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $second = $this->withToken($this->token)
            ->withHeaders(['If-None-Match' => $etag])
            ->getJson('/api/v1/sync/info');
        $second->assertStatus(304);
        $this->assertEmpty($second->getContent());
    }

    public function test_collection_index_returns_etag_and_304_on_match(): void
    {
        app(SyncStorageService::class)->upsertRecord(
            userId: $this->user->id,
            collectionName: 'bookmarks',
            recordId: 'bk-1',
            payload: base64_encode('x'),
        );

        $first = $this->withToken($this->token)->getJson('/api/v1/collections/bookmarks');
        $first->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->withToken($this->token)
            ->withHeaders(['If-None-Match' => $etag])
            ->getJson('/api/v1/collections/bookmarks')
            ->assertStatus(304);
    }

    public function test_etag_invalidated_on_new_write(): void
    {
        $svc = app(SyncStorageService::class);
        $svc->upsertRecord($this->user->id, 'bookmarks', 'bk-1', base64_encode('x'));

        $etag = $this->withToken($this->token)
            ->getJson('/api/v1/collections/bookmarks')
            ->headers->get('ETag');

        usleep(2000);
        $svc->upsertRecord($this->user->id, 'bookmarks', 'bk-2', base64_encode('y'));

        $this->withToken($this->token)
            ->withHeaders(['If-None-Match' => $etag])
            ->getJson('/api/v1/collections/bookmarks')
            ->assertOk();
    }
}
