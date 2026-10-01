<?php

namespace Tests\Feature\Library;

use App\Jobs\Library\FetchLinkMetadata;
use App\Models\Library\LibraryCollection;
use App\Models\Library\LibraryLink;
use App\Models\User;
use App\Services\Library\LinkWriter;
use App\Services\SyncAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NativeSaveTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        config(['library.billing_enabled' => false]);
        $this->user = User::factory()->create();
        $this->token = app(SyncAuthService::class)->createSessionToken($this->user)['token'];
    }

    public function test_replay_returns_the_original_small_receipt_after_edit_and_deletion(): void
    {
        $data = ['operation_id' => (string) Str::uuid(), 'url' => 'https://93.184.216.34/article', 'title' => 'Original', 'tags' => ['Two', 'one']];
        $first = $this->withToken($this->token)->postJson('/api/library/v1/saves', $data)->assertCreated()
            ->assertExactJson(['version' => 1, 'operation_id' => $data['operation_id'], 'link_id' => LibraryLink::sole()->id, 'created' => true]);
        $this->assertStringContainsString('no-store', $first->headers->get('Cache-Control'));
        $link = LibraryLink::sole();
        $link->update(['title' => 'Changed elsewhere', 'readability_html' => str_repeat('x', 100000)]);
        $reordered = array_replace($data, ['tags' => ['one', 'two', 'ONE'], 'operation_id' => strtoupper($data['operation_id'])]);
        $this->withToken($this->token)->postJson('/api/library/v1/saves', $reordered)->assertCreated()->assertExactJson($first->json());
        $this->assertSame('Changed elsewhere', $link->fresh()->title);
        $link->delete();
        $this->withToken($this->token)->postJson('/api/library/v1/saves', $data)->assertCreated()->assertExactJson($first->json());
        $link->forceDelete();
        $this->withToken($this->token)->postJson('/api/library/v1/saves', $data)->assertCreated()->assertExactJson($first->json());
        $this->assertDatabaseCount('library_links', 0);
        $this->assertDatabaseCount('library_save_receipts', 1);
        $this->assertLessThan(256, strlen($first->getContent()));
        Bus::assertDispatchedTimes(FetchLinkMetadata::class, 1);
    }

    public function test_reusing_an_operation_for_different_data_conflicts_without_changes(): void
    {
        $data = ['operation_id' => (string) Str::uuid(), 'url' => 'https://93.184.216.34/article', 'title' => 'Keep'];
        $first = $this->withToken($this->token)->postJson('/api/library/v1/saves', $data)->assertCreated();
        foreach ([['url' => 'https://93.184.216.34/other'], ['title' => 'Replace'], ['tags' => ['new']]] as $change) {
            $this->withToken($this->token)->postJson('/api/library/v1/saves', array_replace($data, $change))
                ->assertConflict()->assertJsonPath('error', 'idempotency_conflict');
        }
        $this->assertDatabaseCount('library_links', 1);
        $this->assertDatabaseCount('library_tags', 0);
        $this->assertDatabaseHas('library_links', ['id' => $first->json('link_id'), 'title' => 'Keep']);
        Bus::assertDispatchedTimes(FetchLinkMetadata::class, 1);
    }

    public function test_canonical_duplicates_have_distinct_receipts_without_overwriting_existing_data(): void
    {
        config(['library.selfhost_limits.max_links' => 1]);
        $legacy = $this->withToken($this->token)->postJson('/api/library/links', [
            'url' => 'https://93.184.216.34/one', 'title' => 'Keep', 'tags' => ['keep'],
        ])->assertCreated();
        $data = ['operation_id' => (string) Str::uuid(), 'url' => 'https://93.184.216.34/one?utm_source=repeat', 'title' => 'Replace'];
        $receipt = $this->withToken($this->token)->postJson('/api/library/v1/saves', $data)->assertOk()
            ->assertJsonPath('created', false)->assertJsonPath('link_id', $legacy->json('id'));
        $this->withToken($this->token)->postJson('/api/library/v1/saves', $data)->assertOk()->assertExactJson($receipt->json());
        $this->assertSame('Keep', LibraryLink::sole()->title);
        $this->assertDatabaseCount('library_save_receipts', 1);
        Bus::assertDispatchedTimes(FetchLinkMetadata::class, 1);
    }

    public function test_failed_quota_and_foreign_collection_do_not_reserve_the_operation(): void
    {
        $data = ['operation_id' => (string) Str::uuid(), 'url' => 'https://93.184.216.34/new'];
        config(['library.selfhost_limits.max_links' => 0]);
        $this->withToken($this->token)->postJson('/api/library/v1/saves', $data)->assertStatus(402);
        $this->assertDatabaseCount('library_save_receipts', 0);
        config(['library.selfhost_limits.max_links' => 1]);
        $collection = LibraryCollection::create(['user_id' => User::factory()->create()->id, 'name' => 'Foreign']);
        $this->withToken($this->token)->postJson('/api/library/v1/saves', $data + ['library_collection_id' => $collection->id])->assertNotFound();
        $this->assertDatabaseCount('library_save_receipts', 0);
        $this->assertDatabaseCount('library_links', 0);
        Bus::assertNothingDispatched();
        $this->withToken($this->token)->postJson('/api/library/v1/saves', $data)->assertCreated();
        $this->assertDatabaseCount('library_save_receipts', 1);
    }

    public function test_receipts_are_scoped_to_the_account_and_survive_a_deleted_collection(): void
    {
        $collection = LibraryCollection::create(['user_id' => $this->user->id, 'name' => 'Owned']);
        $data = ['operation_id' => (string) Str::uuid(), 'url' => 'https://93.184.216.34/scoped', 'library_collection_id' => $collection->id];
        $first = $this->withToken($this->token)->postJson('/api/library/v1/saves', $data)->assertCreated();
        $collection->delete();
        $this->withToken($this->token)->postJson('/api/library/v1/saves', $data)->assertCreated()->assertExactJson($first->json());
        $other = User::factory()->create();
        $token = app(SyncAuthService::class)->createSessionToken($other)['token'];
        unset($data['library_collection_id']);
        $second = $this->withToken($token)->postJson('/api/library/v1/saves', $data)->assertCreated();
        $this->assertNotSame($first->json('link_id'), $second->json('link_id'));
        $this->assertDatabaseCount('library_save_receipts', 2);
        $this->user->delete();
        $this->assertDatabaseCount('library_save_receipts', 1);
        $this->withToken($token)->postJson('/api/library/v1/saves', $data)->assertCreated()->assertExactJson($second->json());
    }

    public function test_transaction_rollback_removes_link_receipt_tags_and_jobs(): void
    {
        $data = ['operation_id' => (string) Str::uuid(), 'url' => 'https://93.184.216.34/rollback', 'tags' => ['rollback']];
        DB::beginTransaction();
        app(LinkWriter::class)->saveOperation($this->user, $data);
        $this->assertDatabaseCount('library_save_receipts', 1);
        DB::rollBack();
        $this->assertDatabaseCount('library_save_receipts', 0);
        $this->assertDatabaseCount('library_links', 0);
        $this->assertDatabaseCount('library_tags', 0);
        Bus::assertNothingDispatched();
        $this->withToken($this->token)->postJson('/api/library/v1/saves', $data)->assertCreated();
        Bus::assertDispatchedTimes(FetchLinkMetadata::class, 1);
    }

    public function test_validation_and_request_limits_do_not_create_data(): void
    {
        $data = ['operation_id' => (string) Str::uuid(), 'url' => 'https://93.184.216.34/valid'];
        $this->postJson('/api/library/v1/saves', $data)->assertUnauthorized();
        foreach ([['operation_id' => 'not-a-uuid'], ['tags' => ['name' => 'invalid']], ['url' => 'http://127.0.0.1/private'], ['library_collection_id' => 0]] as $invalid) {
            $this->withToken($this->token)->postJson('/api/library/v1/saves', array_replace($data, $invalid))->assertUnprocessable();
        }
        $this->withToken($this->token)->postJson('/api/library/v1/saves', $data + ['padding' => str_repeat('x', LinkWriter::MAX_SAVE_BYTES)])
            ->assertStatus(413)->assertJsonPath('error', 'request_too_large');
        $this->assertDatabaseCount('library_links', 0);
        $this->assertDatabaseCount('library_save_receipts', 0);
        Bus::assertNothingDispatched();
    }

    public function test_capabilities_advertise_only_the_available_link_save_contract(): void
    {
        $this->getJson('/api/v1/capabilities')->assertOk()->assertJsonPath('native_ready', false)
            ->assertJsonPath('link', [
                'save_version' => 1, 'save_request_bytes' => LinkWriter::MAX_SAVE_BYTES,
                'save_receipts_per_account' => LinkWriter::MAX_SAVE_RECEIPTS,
                'browse_version' => 1, 'browse_page_limit' => 50,
                'feed_version' => 1, 'feed_page_limit' => 50, 'feed_page_bytes' => 524288,
            ]);
    }

    public function test_receipt_capacity_preserves_replays_and_rejects_new_operations_atomically(): void
    {
        $data = ['operation_id' => (string) Str::uuid(), 'url' => 'https://93.184.216.34/capacity'];
        $first = $this->withToken($this->token)->postJson('/api/library/v1/saves', $data)->assertCreated();
        DB::statement('INSERT INTO library_save_receipts (user_id, operation_id, fingerprint, link_id, created, created_at)
            SELECT ?, md5(value::text)::uuid, ?, ?::uuid, false, CURRENT_TIMESTAMP
            FROM generate_series(1, CAST(? AS integer)) AS value', [
            $this->user->id, str_repeat('0', 64), $first->json('link_id'), LinkWriter::MAX_SAVE_RECEIPTS - 1,
        ]);
        $this->withToken($this->token)->postJson('/api/library/v1/saves', $data)->assertCreated()->assertExactJson($first->json());
        $data['operation_id'] = (string) Str::uuid();
        $data['url'] = 'https://93.184.216.34/over-capacity';
        $this->withToken($this->token)->postJson('/api/library/v1/saves', $data)->assertConflict()->assertJsonPath('error', 'operation_capacity');
        $this->assertDatabaseCount('library_links', 1);
        $this->assertDatabaseCount('library_save_receipts', LinkWriter::MAX_SAVE_RECEIPTS);
        Bus::assertDispatchedTimes(FetchLinkMetadata::class, 1);
    }
}
