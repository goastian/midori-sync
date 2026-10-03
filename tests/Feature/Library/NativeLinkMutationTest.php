<?php

namespace Tests\Feature\Library;

use App\Jobs\Library\FetchLinkMetadata;
use App\Models\Library\LibraryCollection;
use App\Models\Library\LibraryLink;
use App\Models\User;
use App\Services\Library\PublicPageFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NativeLinkMutationTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): string
    {
        return $this->createNativeSessionToken($user)['token'];
    }

    private function save(string $token, string $url, ?string $operationId = null): array
    {
        return $this->withToken($token)->postJson('/api/library/v1/saves', [
            'operation_id' => $operationId ?? (string) Str::uuid(), 'url' => $url,
        ])->assertCreated()->json();
    }

    public function test_metadata_processing_keeps_a_new_save_undoable(): void
    {
        config(['library.billing_enabled' => false, 'library.fetch_enabled' => false]);
        $user = User::factory()->create();
        $token = $this->token($user);
        $receipt = $this->withToken($token)->postJson('/api/library/v1/saves?receipt_version=2', [
            'operation_id' => (string) Str::uuid(), 'url' => 'https://93.184.216.34/undoable',
            'title' => 'Chosen title',
        ])->assertCreated()->json();
        (new FetchLinkMetadata($receipt['link_id']))->handle(app(PublicPageFetcher::class));
        $link = LibraryLink::findOrFail($receipt['link_id']);
        $this->assertSame('failed', $link->metadata_status);
        $this->assertSame($receipt['revision'], (string) $link->revision);
        $this->assertSame('Chosen title', $link->title);

        $this->withToken($token)->deleteJson("/api/library/v1/links/{$link->id}", [
            'operation_id' => (string) Str::uuid(), 'expected_revision' => $receipt['revision'],
        ])->assertOk()->assertJsonPath('deleted', true);
    }

    public function test_edits_and_deletes_publish_revisions_with_replayable_receipts(): void
    {
        Bus::fake();
        config(['library.billing_enabled' => false]);
        $user = User::factory()->create();
        $token = $this->token($user);
        $id = $this->save($token, 'https://93.184.216.34/mutation')['link_id'];
        $firstRevision = (string) LibraryLink::findOrFail($id)->revision;
        $edit = ['operation_id' => (string) Str::uuid(), 'expected_revision' => $firstRevision,
            'title' => 'Updated', 'tags' => ['Work']];
        $edited = $this->withToken($token)->patchJson("/api/library/v1/links/{$id}", $edit)
            ->assertOk()->assertJsonPath('deleted', false)->json();
        $this->assertSame($edited, $this->withToken($token)
            ->patchJson("/api/library/v1/links/{$id}", $edit)->assertOk()->json());
        $this->assertSame($edited['revision'], (string) LibraryLink::findOrFail($id)->revision);
        $this->assertSame('Updated', LibraryLink::findOrFail($id)->title);

        $delete = ['operation_id' => (string) Str::uuid(), 'expected_revision' => $edited['revision']];
        $deleted = $this->withToken($token)->deleteJson("/api/library/v1/links/{$id}", $delete)
            ->assertOk()->assertJsonPath('deleted', true)->json();
        $this->assertSame($deleted, $this->withToken($token)
            ->deleteJson("/api/library/v1/links/{$id}", $delete)->assertOk()->json());
        $this->assertSoftDeleted('library_links', ['id' => $id]);
        $this->assertSame($deleted['revision'], (string) LibraryLink::withTrashed()->findOrFail($id)->revision);
        $this->assertDatabaseCount('library_link_operation_receipts', 2);

        $changes = $this->withToken($token)->getJson('/api/library/v1/changes')->assertOk()->json('changes');
        $this->assertSame('Updated', $changes[count($changes) - 2]['value']['title']);
        $this->assertSame(['work'], $changes[count($changes) - 2]['value']['tags']);
        $this->assertSame($deleted['revision'], $changes[count($changes) - 1]['revision']);
        $this->assertTrue($changes[count($changes) - 1]['deleted']);
    }

    public function test_mutations_enforce_owner_revision_collection_and_global_operation_identity(): void
    {
        Bus::fake();
        config(['library.billing_enabled' => false]);
        $user = User::factory()->create();
        $other = User::factory()->create();
        $token = $this->token($user);
        $id = $this->save($token, 'https://93.184.216.34/owned')['link_id'];
        $foreign = $this->save($this->token($other), 'https://93.184.216.34/foreign')['link_id'];
        $collection = LibraryCollection::create(['user_id' => $other->id, 'name' => 'Other']);
        $revision = (string) LibraryLink::findOrFail($id)->revision;
        $operationId = (string) Str::uuid();
        $base = ['operation_id' => $operationId, 'expected_revision' => $revision];

        $this->withToken($token)->patchJson("/api/library/v1/links/{$foreign}", $base + ['title' => 'No'])
            ->assertNotFound()->assertJsonPath('error', 'link_not_found');
        $this->withToken($token)->patchJson("/api/library/v1/links/{$id}",
            $base + ['library_collection_id' => $collection->id])
            ->assertNotFound()->assertJsonPath('error', 'collection_not_found');
        $this->withToken($token)->patchJson("/api/library/v1/links/{$id}",
            ['operation_id' => $operationId, 'expected_revision' => '999', 'title' => 'No'])
            ->assertStatus(409)->assertJsonPath('error', 'revision_conflict');
        $this->withToken($token)->patchJson("/api/library/v1/links/{$id}", $base + ['title' => 'Yes'])
            ->assertOk();
        $this->withToken($token)->patchJson("/api/library/v1/links/{$id}", $base + ['title' => 'Different'])
            ->assertStatus(409)->assertJsonPath('error', 'idempotency_conflict');
        $this->withToken($token)->postJson('/api/library/v1/saves', [
            'operation_id' => $operationId, 'url' => 'https://93.184.216.34/new',
        ])->assertStatus(409)->assertJsonPath('error', 'idempotency_conflict');
        $saveOperationId = (string) Str::uuid();
        $this->save($token, 'https://93.184.216.34/second', $saveOperationId);
        $this->withToken($token)->deleteJson("/api/library/v1/links/{$id}", [
            'operation_id' => $saveOperationId, 'expected_revision' => (string) LibraryLink::findOrFail($id)->revision,
        ])->assertStatus(409)->assertJsonPath('error', 'idempotency_conflict');
        $this->withToken($token)->patchJson("/api/library/v1/links/{$id}",
            ['operation_id' => (string) Str::uuid(), 'expected_revision' => $revision])
            ->assertUnprocessable()->assertJsonPath('error', 'empty_link_edit');
        $this->assertSame(1, DB::table('library_link_operation_receipts')->where('user_id', $user->id)->count());
        $this->getJson('/api/v1/capabilities')->assertOk()
            ->assertJsonPath('link.mutation_version', 1)
            ->assertJsonPath('link.mutation_request_bytes', 32768);
    }
}
