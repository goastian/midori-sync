<?php

namespace Tests\Feature\Library;

use App\Jobs\Library\FetchLinkMetadata;
use App\Jobs\Library\SnapshotPage;
use App\Models\Library\LibraryLink;
use App\Models\Library\LibrarySnapshot;
use App\Models\User;
use App\Services\Library\MetadataExtractor;
use App\Services\Library\PublicPageFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LibraryWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_crud_with_tags_and_flags(): void
    {
        config(['library.fetch_enabled' => true]);
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->app->instance(PublicPageFetcher::class, new class extends PublicPageFetcher
        {
            public function fetch(string $url, int $maxBytes, int $timeout, string $userAgent): array
            {
                return [
                    'body' => '<html><head><title>Fake Title</title><meta name="description" content="Fake desc"></head>'
                        .'<body><article><h1>Hola</h1><p>'.str_repeat('palabra ', 80).'</p></article></body></html>',
                    'content_type' => 'text/html',
                    'url' => $url,
                ];
            }
        });

        $this->post('/library', ['url' => 'https://example.com/a', 'tags' => 'paper, leer'])
            ->assertRedirect();
        $link = LibraryLink::first();
        $this->assertNotNull($link);
        $this->assertEquals('https://example.com/a', $link->canonical_url);
        $this->assertCount(2, $link->tags);

        $this->patch("/library/{$link->id}", ['is_favorite' => true, 'tags' => 'paper, urgente'])
            ->assertRedirect();
        $link->refresh();
        $this->assertTrue($link->is_favorite);
        $this->assertCount(2, $link->tags()->get());
        $this->assertTrue($link->tags()->where('name', 'urgente')->exists());

        $this->post("/library/{$link->id}/highlights", ['quote' => 'cita', 'note' => 'nota'])
            ->assertRedirect();
        $this->assertDatabaseHas('library_highlights', ['quote' => 'cita']);

        $this->post("/library/{$link->id}/shares", [])->assertRedirect();
        $this->assertEquals(1, $link->shares()->count());

        $this->post("/library/{$link->id}/refresh", [])->assertRedirect();
        // With QUEUE_CONNECTION=sync the job runs inline against the faked HTTP.
        $this->assertEquals('ready', $link->fresh()->metadata_status);
        $this->assertEquals('Fake Title', $link->fresh()->title);

        $this->delete("/library/{$link->id}")->assertRedirect('/library');
        $this->assertSoftDeleted('library_links', ['id' => $link->id]);
    }

    public function test_api_token_still_works_for_native_client(): void
    {
        $user = User::factory()->create();
        $token = $this->createNativeSessionToken($user)['token'];
        config(['library.billing_enabled' => false]);

        $this->withToken($token)->postJson('/api/library/links', ['url' => 'https://example.com/ext'])
            ->assertCreated();
    }

    public function test_disabled_fetch_does_not_leave_metadata_pending(): void
    {
        config(['library.fetch_enabled' => false]);
        $user = User::factory()->create();
        $link = LibraryLink::create([
            'user_id' => $user->id,
            'url' => 'https://example.com/article',
            'canonical_url' => 'https://example.com/article',
            'host' => 'example.com',
            'title' => 'Article',
        ]);

        (new FetchLinkMetadata($link->id))->handle(app(PublicPageFetcher::class));

        $this->assertSame('failed', $link->fresh()->metadata_status);
    }

    public function test_metadata_fetch_preserves_a_title_and_description_edited_during_download(): void
    {
        config(['library.fetch_enabled' => true]);
        $user = User::factory()->create();
        $link = LibraryLink::create([
            'user_id' => $user->id,
            'url' => 'https://example.com/article',
            'canonical_url' => 'https://example.com/article',
            'host' => 'example.com',
            'title' => 'Original choice',
            'metadata_status' => 'pending',
        ]);
        $fetcher = new class($link) extends PublicPageFetcher
        {
            public function __construct(private LibraryLink $link) {}

            public function fetch(string $url, int $maxBytes, int $timeout, string $userAgent): array
            {
                $this->link->update(['title' => 'Edited while fetching', 'description' => 'Personal note']);

                return ['body' => '<html><head><title>Remote title</title>'
                    .'<meta name="description" content="Remote description"></head><body>Article</body></html>',
                    'content_type' => 'text/html', 'url' => $url];
            }
        };

        (new FetchLinkMetadata($link->id))->handle($fetcher);

        $this->assertSame('Edited while fetching', $link->fresh()->title);
        $this->assertSame('Personal note', $link->fresh()->description);
        $this->assertSame('ready', $link->fresh()->metadata_status);
        $this->assertSame('2', (string) $link->fresh()->revision);
        $this->assertSame(3, DB::table('library_link_changes')->where('link_id', $link->id)->count());
        $this->assertSame('2', (string) DB::table('library_link_changes')->where('link_id', $link->id)
            ->orderByDesc('sequence')->value('revision'));
        $link->fresh()->update(['is_favorite' => true]);
        $this->assertSame('3', (string) $link->fresh()->revision);
    }

    public function test_snapshot_sanitizes_downloaded_html_before_storing_it(): void
    {
        config(['library.snapshots_enabled' => true, 'library.snapshot_disk' => 'local']);
        Storage::fake('local');
        $user = User::factory()->create();
        $link = LibraryLink::create([
            'user_id' => $user->id,
            'url' => 'https://example.com/article',
            'canonical_url' => 'https://example.com/article',
            'host' => 'example.com',
            'title' => 'Article',
        ]);
        $fetcher = new class extends PublicPageFetcher
        {
            public function fetch(string $url, int $maxBytes, int $timeout, string $userAgent): array
            {
                return [
                    'body' => '<html><body><article><h1>Article</h1><p>Safe article text</p>'
                        .'<a href="javascript:alert(1)" onclick="alert(1)">Read</a>'
                        .'<script>alert(1)</script></article></body></html>',
                    'content_type' => 'text/html',
                    'url' => $url,
                ];
            }
        };

        (new SnapshotPage($link->id))->handle($fetcher);

        $snapshot = LibrarySnapshot::where('library_link_id', $link->id)->firstOrFail();
        $html = Storage::disk('local')->get($snapshot->storage_path);
        $this->assertSame('ready', $link->fresh()->snapshot_status);
        $this->assertStringContainsString('Safe article text', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_extractor_resolves_relative_urls_and_sanitizes(): void
    {
        $html = '<html><head><title>T</title>'
            .'<meta name="description" content="D">'
            .'<meta property="og:image" content="/img/og.png">'
            .'<link rel="icon" href="/fav.ico">'
            .'</head><body><article><h1>Hola mundo</h1>'
            .'<p>'.str_repeat('palabra ', 60).'</p>'
            .'<script>alert(1)</script>'
            .'<a href="/rel" onclick="evil()">enlace</a>'
            .'<a href="javascript:alert(1)">malo</a>'
            .'</article></body></html>';

        $out = MetadataExtractor::extract($html, 'https://example.com/blog/post');

        $this->assertEquals('https://example.com/img/og.png', $out['og_image']);
        $this->assertEquals('https://example.com/fav.ico', $out['favicon']);
        $this->assertStringNotContainsString('<script', $out['readable']);
        $this->assertStringNotContainsString('onclick', $out['readable']);
        $this->assertStringNotContainsString('javascript:', $out['readable']);
        $this->assertStringContainsString('https://example.com/rel', $out['readable']);
        $this->assertStringContainsString('<h1>', $out['readable']);
        $this->assertGreaterThanOrEqual(1, $out['reading_time']);
    }
}
