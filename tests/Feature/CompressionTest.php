<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SyncAuthService;
use App\Services\SyncStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke test for NegotiateCompression middleware. The feature is opt-in,
 * so we assert both default-off behaviour and enabled behaviour.
 */
class CompressionTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CollectionSeeder::class);
        $this->user = User::factory()->create(['storage_quota_bytes' => 104857600]);
        $this->token = app(SyncAuthService::class)
            ->createSessionToken($this->user)['token'];

        // Seed a chunky response body.
        $svc = app(SyncStorageService::class);
        for ($i = 0; $i < 20; $i++) {
            $svc->upsertRecord(
                $this->user->id,
                'bookmarks',
                "bk-{$i}",
                str_repeat('A', 512),
            );
        }
    }

    public function test_disabled_by_default(): void
    {
        config()->set('services.sync.http_compression', false);

        $response = $this->withToken($this->token)
            ->withHeaders(['Accept-Encoding' => 'gzip'])
            ->getJson('/api/v1/collections/bookmarks');

        $response->assertOk();
        $this->assertNull($response->headers->get('Content-Encoding'));
    }

    public function test_gzips_response_when_enabled_and_client_accepts(): void
    {
        config()->set('services.sync.http_compression', true);
        config()->set('services.sync.http_compression_min_bytes', 256);

        $response = $this->withToken($this->token)
            ->withHeaders(['Accept-Encoding' => 'gzip'])
            ->getJson('/api/v1/collections/bookmarks');

        $response->assertOk();
        $this->assertSame('gzip', $response->headers->get('Content-Encoding'));
        $this->assertStringContainsString(
            'Accept-Encoding',
            (string) $response->headers->get('Vary'),
        );
        $decoded = gzdecode($response->getContent());
        $this->assertNotFalse($decoded);
        $this->assertJson($decoded);
    }

    public function test_skips_when_client_does_not_accept_gzip(): void
    {
        config()->set('services.sync.http_compression', true);

        $response = $this->withToken($this->token)
            ->withHeaders(['Accept-Encoding' => 'identity'])
            ->getJson('/api/v1/collections/bookmarks');

        $response->assertOk();
        $this->assertNull($response->headers->get('Content-Encoding'));
    }
}
