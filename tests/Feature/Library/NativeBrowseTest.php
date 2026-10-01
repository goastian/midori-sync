<?php

namespace Tests\Feature\Library;

use App\Models\Library\LibraryLink;
use App\Models\Library\LibraryTag;
use App\Models\User;
use App\Services\SyncAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NativeBrowseTest extends TestCase
{
    use RefreshDatabase;

    public function test_cursor_browsing_is_stable_scoped_and_bounded(): void
    {
        $user = User::factory()->create();
        $token = app(SyncAuthService::class)->createSessionToken($user)['token'];
        $timestamp = now()->subDay()->startOfSecond();
        $links = [];
        for ($i = 0; $i < 4; $i++) {
            $links[] = LibraryLink::create([
                'user_id' => $user->id,
                'url' => "https://example.com/article-{$i}",
                'title' => "Article {$i}",
                'readability_html' => str_repeat('private content', 100),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }
        DB::table('library_links')->whereIn('id', array_map(fn (LibraryLink $link) => $link->id, $links))
            ->update(['created_at' => $timestamp, 'updated_at' => $timestamp]);
        $tag = LibraryTag::create(['user_id' => $user->id, 'name' => 'reading']);
        $links[0]->tags()->attach($tag->id);
        $links[3]->delete();
        LibraryLink::create([
            'user_id' => User::factory()->create()->id,
            'url' => 'https://example.com/foreign', 'title' => 'Foreign',
        ]);

        $first = $this->withToken($token)->getJson('/api/library/v1/links?per_page=2')->assertOk()
            ->assertJsonPath('version', 1)->assertJsonCount(2, 'items');
        $this->assertStringContainsString('no-store', $first->headers->get('Cache-Control'));
        $this->assertNotNull($first->json('next_cursor'));
        $this->assertStringNotContainsString('private content', $first->getContent());
        $second = $this->withToken($token)->getJson('/api/library/v1/links?per_page=2&cursor='
            .rawurlencode($first->json('next_cursor')))->assertOk()
            ->assertJsonPath('next_cursor', null)->assertJsonCount(1, 'items');
        $seen = array_merge(array_column($first->json('items'), 'id'), array_column($second->json('items'), 'id'));
        $this->assertEqualsCanonicalizing([$links[0]->id, $links[1]->id, $links[2]->id], $seen);
        $this->assertCount(3, array_unique($seen));
        $items = array_merge($first->json('items'), $second->json('items'));
        $tagged = collect($items)->firstWhere('id', $links[0]->id);
        $this->assertSame(['reading'], $tagged['tags']);
        $this->getJson('/api/v1/capabilities')->assertOk()
            ->assertJsonPath('link.browse_version', 1)
            ->assertJsonPath('link.browse_page_limit', 50);
        $this->withToken($token)->getJson('/api/library/v1/links?per_page=51')->assertUnprocessable();
        $this->withToken($token)->getJson('/api/library/v1/links?cursor=invalid')->assertUnprocessable();
    }

    public function test_browse_requires_a_session(): void
    {
        $this->getJson('/api/library/v1/links')->assertUnauthorized();
    }
}
