<?php

namespace Tests\Feature\Library;

use App\Models\Library\LibraryCollection;
use App\Models\Library\LibraryLink;
use App\Models\User;
use App\Services\SyncAuthService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LibraryAtomicEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_and_web_edits_roll_back_fields_tags_and_feed_together(): void
    {
        $user = User::factory()->create();
        $token = app(SyncAuthService::class)->createSessionToken($user)['token'];
        $api = LibraryLink::create(['user_id' => $user->id, 'url' => 'https://example.com/api', 'title' => 'Original API']);
        $web = LibraryLink::create(['user_id' => $user->id, 'url' => 'https://example.com/web', 'title' => 'Original Web']);
        $before = DB::table('library_link_changes')->count();
        DB::unprepared(<<<'SQL'
CREATE FUNCTION midori_test_reject_link_tag() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'synthetic tag failure';
END;
$$;
CREATE TRIGGER midori_test_reject_link_tag_trigger
BEFORE INSERT ON library_link_tag
FOR EACH ROW EXECUTE FUNCTION midori_test_reject_link_tag();
SQL);

        $this->withoutExceptionHandling();
        try {
            $this->withToken($token)->patchJson("/api/library/links/{$api->id}", [
                'title' => 'Changed API', 'tags' => ['reading'],
            ]);
            $this->fail('The tag write should fail.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('synthetic tag failure', $error->getMessage());
        }
        try {
            $this->actingAs($user)->patch("/library/{$web->id}", [
                'title' => 'Changed Web', 'tags' => 'reading',
            ]);
            $this->fail('The tag write should fail.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('synthetic tag failure', $error->getMessage());
        }

        $this->assertSame('Original API', $api->fresh()->title);
        $this->assertSame('Original Web', $web->fresh()->title);
        $this->assertDatabaseCount('library_tags', 0);
        $this->assertSame($before, DB::table('library_link_changes')->count());
    }

    public function test_bulk_edit_rolls_back_all_links_when_one_write_fails(): void
    {
        $user = User::factory()->create();
        $token = app(SyncAuthService::class)->createSessionToken($user)['token'];
        $first = LibraryLink::create(['id' => '00000000-0000-4000-8000-000000000001',
            'user_id' => $user->id, 'url' => 'https://example.com/first']);
        $second = LibraryLink::create(['id' => '00000000-0000-4000-8000-000000000002',
            'user_id' => $user->id, 'url' => 'https://example.com/fail']);
        $before = DB::table('library_link_changes')->count();
        DB::unprepared(<<<'SQL'
CREATE FUNCTION midori_test_reject_bulk_link() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.url = 'https://example.com/fail' AND NEW.is_archived THEN
        RAISE EXCEPTION 'synthetic bulk failure';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER midori_test_reject_bulk_link_trigger
BEFORE UPDATE ON library_links
FOR EACH ROW EXECUTE FUNCTION midori_test_reject_bulk_link();
SQL);

        $this->withoutExceptionHandling();
        try {
            $this->withToken($token)->postJson('/api/library/links/bulk', [
                'ids' => [$first->id, $second->id], 'action' => 'archive',
            ]);
            $this->fail('The second link write should fail.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('synthetic bulk failure', $error->getMessage());
        }

        $this->assertFalse($first->fresh()->is_archived);
        $this->assertFalse($second->fresh()->is_archived);
        $this->assertSame($before, DB::table('library_link_changes')->count());
    }

    public function test_collection_validation_does_not_accept_another_account(): void
    {
        $user = User::factory()->create();
        $token = app(SyncAuthService::class)->createSessionToken($user)['token'];
        $other = User::factory()->create();
        $collection = LibraryCollection::create(['user_id' => $other->id, 'name' => 'Private']);
        $link = LibraryLink::create(['user_id' => $user->id, 'url' => 'https://example.com/owned']);

        $this->withToken($token)->patchJson("/api/library/links/{$link->id}", [
            'library_collection_id' => $collection->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('library_collection_id');
        $this->actingAs($user)->patch("/library/{$link->id}", [
            'library_collection_id' => $collection->id,
        ])->assertSessionHasErrors('library_collection_id');
        $this->assertNull($link->fresh()->library_collection_id);
        $this->assertDatabaseCount('library_link_changes', 1);
    }
}
