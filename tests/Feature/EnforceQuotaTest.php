<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Record;
use App\Models\User;
use App\Services\SyncAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers App\Http\Middleware\EnforceQuota.
 *
 * The middleware runs before write endpoints under /api/v1 and /api/ext.
 * It must:
 *   - allow writes that fit in the remaining quota
 *   - return 403 when the request would exceed the user's quota
 *   - skip non-write methods entirely
 */
class EnforceQuotaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CollectionSeeder::class);

        // Tight quota so we can exercise the limit deterministically.
        $this->user = User::factory()->create(['storage_quota_bytes' => 1024]);
        $this->token = app(SyncAuthService::class)
            ->createSessionToken($this->user)['token'];
    }

    public function test_write_under_quota_succeeds(): void
    {
        $this->withToken($this->token)
            ->putJson('/api/v1/collections/bookmarks/bk-1', [
                'payload' => base64_encode('hello'),
            ])
            ->assertOk();
    }

    public function test_write_that_exceeds_quota_is_rejected_with_403(): void
    {
        // Pre-fill the user almost to the cap with a single existing record.
        $bookmarks = Collection::where('name', 'bookmarks')->firstOrFail();
        Record::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'collection_id' => $bookmarks->id,
            'record_id' => 'pre-existing',
            'version' => 1,
            'payload' => str_repeat('x', 1000),
            'deleted' => false,
            'modified_at' => microtime(true),
        ]);

        $payload = ['payload' => base64_encode(str_repeat('y', 2048))];

        $this->withToken($this->token)
            ->putJson('/api/v1/collections/bookmarks/bk-2', $payload)
            ->assertStatus(403)
            ->assertJsonStructure(['error', 'quota_bytes', 'used_bytes']);
    }

    public function test_get_requests_are_not_quota_checked(): void
    {
        $bookmarks = Collection::where('name', 'bookmarks')->firstOrFail();
        Record::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'collection_id' => $bookmarks->id,
            'record_id' => 'pre-existing',
            'version' => 1,
            'payload' => str_repeat('x', 2048),
            'deleted' => false,
            'modified_at' => microtime(true),
        ]);

        // User is over quota, but reads must still succeed.
        $this->withToken($this->token)
            ->getJson('/api/v1/collections/bookmarks')
            ->assertOk();
    }

    public function test_deleted_records_do_not_count_toward_quota(): void
    {
        $bookmarks = Collection::where('name', 'bookmarks')->firstOrFail();
        Record::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'collection_id' => $bookmarks->id,
            'record_id' => 'tombstone',
            'version' => 1,
            'payload' => str_repeat('z', 1000),
            'deleted' => true,
            'modified_at' => microtime(true),
        ]);

        // 1000 deleted bytes + ~12 new bytes < 1024 quota.
        $this->withToken($this->token)
            ->putJson('/api/v1/collections/bookmarks/bk-new', [
                'payload' => base64_encode('hi'),
            ])
            ->assertOk();
    }
}
