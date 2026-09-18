<?php

namespace Tests\Feature\Library;

use App\Models\Library\LibraryLink;
use App\Models\User;
use App\Services\Library\MetadataExtractor;
use App\Services\SyncAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LibraryWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_crud_with_tags_and_flags(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response(
                '<html><head><title>Fake Title</title><meta name="description" content="Fake desc"></head>'
                .'<body><article><h1>Hola</h1><p>'.str_repeat('palabra ', 80).'</p></article></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);

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
        // Con QUEUE_CONNECTION=sync el job corre inline contra el HTTP fakeado.
        $this->assertEquals('ready', $link->fresh()->metadata_status);
        $this->assertEquals('Fake Title', $link->fresh()->title);

        $this->delete("/library/{$link->id}")->assertRedirect('/library');
        $this->assertSoftDeleted('library_links', ['id' => $link->id]);
    }

    public function test_api_token_still_works_for_extension(): void
    {
        $user = User::factory()->create();
        $token = app(SyncAuthService::class)->createSessionToken($user)['token'];
        config(['library.billing_enabled' => false]);

        $this->withToken($token)->postJson('/api/library/links', ['url' => 'https://example.com/ext'])
            ->assertCreated();
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
