<?php

namespace Tests\Feature\Library;

use App\Models\Library\LibraryLink;
use App\Models\Library\LibraryTag;
use App\Models\User;
use App\Services\SyncAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NativeFeedTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): string
    {
        return app(SyncAuthService::class)->createSessionToken($user)['token'];
    }

    public function test_native_web_job_tag_and_delete_changes_share_a_revisioned_feed(): void
    {
        Bus::fake();
        config(['library.billing_enabled' => false]);
        $user = User::factory()->create();
        $token = $this->token($user);
        $saved = $this->withToken($token)->postJson('/api/library/v1/saves', [
            'operation_id' => (string) Str::uuid(), 'url' => 'https://93.184.216.34/feed',
            'title' => 'Original', 'tags' => ['reading'],
        ])->assertCreated();
        $id = $saved->json('link_id');
        $tag = LibraryTag::where('user_id', $user->id)->sole();
        $tag->update(['name' => 'renamed']);
        $this->withToken($token)->patchJson("/api/library/links/{$id}", ['title' => 'Edited'])->assertOk();
        LibraryLink::findOrFail($id)->update(['metadata_status' => 'ready', 'readability_html' => '<p>private article</p>']);
        $this->withToken($token)->deleteJson("/api/library/links/{$id}")->assertNoContent();

        $page = $this->withToken($token)->getJson('/api/library/v1/changes')->assertOk()
            ->assertJsonPath('version', 1)->assertJsonPath('has_more', false);
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
        $changes = $page->json('changes');
        $this->assertCount(6, $changes);
        $this->assertSame(['1', '2', '3', '4', '5', '6'], array_column($changes, 'sequence'));
        $this->assertSame(['1', '2', '3', '4', '5', '6'], array_column($changes, 'revision'));
        $this->assertSame([], $changes[0]['value']['tags']);
        $this->assertSame(['reading'], $changes[1]['value']['tags']);
        $this->assertSame(['renamed'], $changes[2]['value']['tags']);
        $this->assertSame('Edited', $changes[3]['value']['title']);
        $this->assertNull($changes[5]['value']);
        $this->assertTrue($changes[5]['deleted']);
        $this->assertStringNotContainsString('private article', $page->getContent());
        $this->assertDatabaseHas('library_link_streams', ['user_id' => $user->id, 'sequence' => 6]);
    }

    public function test_fenced_cursor_excludes_later_writes_until_the_next_poll_and_is_account_bound(): void
    {
        Bus::fake();
        config(['library.billing_enabled' => false]);
        $user = User::factory()->create();
        $token = $this->token($user);
        for ($i = 0; $i < 2; $i++) {
            $this->withToken($token)->postJson('/api/library/v1/saves', [
                'operation_id' => (string) Str::uuid(), 'url' => "https://93.184.216.34/page-{$i}",
            ])->assertCreated();
        }
        $first = $this->withToken($token)->getJson('/api/library/v1/changes?limit=1')->assertOk()
            ->assertJsonPath('has_more', true)->assertJsonPath('snapshot_sequence', '2');
        $this->withToken($token)->postJson('/api/library/v1/saves', [
            'operation_id' => (string) Str::uuid(), 'url' => 'https://93.184.216.34/page-later',
        ])->assertCreated();
        $second = $this->withToken($token)->getJson('/api/library/v1/changes?limit=1&cursor='
            .rawurlencode($first->json('next_cursor')))->assertOk()
            ->assertJsonPath('has_more', false)->assertJsonPath('snapshot_sequence', '2');
        $this->assertSame(['2'], array_column($second->json('changes'), 'sequence'));
        $next = $this->withToken($token)->getJson('/api/library/v1/changes?cursor='
            .rawurlencode($second->json('next_cursor')))->assertOk()
            ->assertJsonPath('snapshot_sequence', '3');
        $this->assertSame(['3'], array_column($next->json('changes'), 'sequence'));

        $other = User::factory()->create();
        $this->withToken($this->token($other))->getJson('/api/library/v1/changes?cursor='
            .rawurlencode($second->json('next_cursor')))->assertUnprocessable()->assertJsonPath('error', 'invalid_cursor');
        $this->withToken($token)->getJson('/api/library/v1/changes?cursor=invalid')
            ->assertUnprocessable()->assertJsonPath('error', 'invalid_cursor');
    }

    public function test_rolled_back_links_leave_no_feed_event_and_account_deletion_cleans_it(): void
    {
        $user = User::factory()->create();
        try {
            DB::transaction(function () use ($user) {
                LibraryLink::create(['user_id' => $user->id, 'url' => 'https://example.org/rollback']);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('rollback', $error->getMessage());
        }
        $this->assertDatabaseCount('library_link_changes', 0);
        $this->assertDatabaseCount('library_link_streams', 0);
        $link = LibraryLink::create(['user_id' => $user->id, 'url' => 'https://example.org/keep']);
        $this->assertSame('1', (string) $link->fresh()->revision);
        $link->forceDelete();
        $this->assertDatabaseHas('library_link_changes', ['user_id' => $user->id, 'sequence' => 2, 'deleted' => true]);
        $user->delete();
        $this->assertDatabaseCount('library_link_changes', 0);
        $this->assertDatabaseCount('library_link_streams', 0);
    }

    public function test_migration_backfills_live_links_without_resurrecting_deleted_links(): void
    {
        $migration = require database_path('migrations/2026_10_01_000003_create_library_link_feed.php');
        $migration->down();
        $user = User::factory()->create();
        $live = LibraryLink::create(['user_id' => $user->id, 'url' => 'https://example.org/existing', 'title' => 'Existing']);
        $deleted = LibraryLink::create(['user_id' => $user->id, 'url' => 'https://example.org/deleted']);
        $deleted->delete();

        $migration->up();

        $this->assertDatabaseHas('library_link_streams', ['user_id' => $user->id, 'sequence' => 1]);
        $event = DB::table('library_link_changes')->where('user_id', $user->id)->sole();
        $this->assertSame($live->id, $event->link_id);
        $this->assertSame(1, (int) $event->revision);
        $this->assertSame('Existing', json_decode($event->value, true, flags: JSON_THROW_ON_ERROR)['title']);
        $this->assertFalse((bool) $event->deleted);
    }
}
