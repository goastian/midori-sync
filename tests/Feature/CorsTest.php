<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SyncAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies CorsForExtension echoes only allow-listed origins and
 * preflights short-circuit cleanly.
 */
class CorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CollectionSeeder::class);

        config()->set('cors.allowed_origins', [
            'https://dashboard.example.com',
        ]);
        config()->set('cors.allowed_origin_patterns', [
            '^moz-extension://[a-z0-9-]+$',
        ]);
    }

    public function test_allowed_origin_is_echoed(): void
    {
        $user = User::factory()->create();
        $token = app(SyncAuthService::class)->createSessionToken($user)['token'];

        $response = $this->withToken($token)
            ->withHeaders(['Origin' => 'https://dashboard.example.com'])
            ->getJson('/api/v1/sync/info');

        $response->assertOk();
        $this->assertSame(
            'https://dashboard.example.com',
            $response->headers->get('Access-Control-Allow-Origin')
        );
        $this->assertStringContainsString('Origin', (string) $response->headers->get('Vary'));
    }

    public function test_pattern_origin_is_echoed(): void
    {
        $user = User::factory()->create();
        $token = app(SyncAuthService::class)->createSessionToken($user)['token'];

        $response = $this->withToken($token)
            ->withHeaders(['Origin' => 'moz-extension://abc-123-def'])
            ->getJson('/api/v1/sync/info');

        $response->assertOk();
        $this->assertSame(
            'moz-extension://abc-123-def',
            $response->headers->get('Access-Control-Allow-Origin')
        );
    }

    public function test_disallowed_origin_gets_no_cors_headers(): void
    {
        $user = User::factory()->create();
        $token = app(SyncAuthService::class)->createSessionToken($user)['token'];

        $response = $this->withToken($token)
            ->withHeaders(['Origin' => 'https://evil.example.com'])
            ->getJson('/api/v1/sync/info');

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_request_without_origin_has_no_cors_headers(): void
    {
        $user = User::factory()->create();
        $token = app(SyncAuthService::class)->createSessionToken($user)['token'];

        $response = $this->withToken($token)->getJson('/api/v1/sync/info');

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_preflight_returns_204_for_allowed_origin(): void
    {
        $response = $this->call(
            'OPTIONS',
            '/api/v1/sync/info',
            [],
            [],
            [],
            [
                'HTTP_ORIGIN' => 'https://dashboard.example.com',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization, x-device-id',
            ]
        );

        $response->assertStatus(204);
        $this->assertSame(
            'https://dashboard.example.com',
            $response->headers->get('Access-Control-Allow-Origin')
        );
        $this->assertNotNull($response->headers->get('Access-Control-Allow-Methods'));
        $this->assertNotNull($response->headers->get('Access-Control-Allow-Headers'));
    }

    public function test_preflight_for_disallowed_origin_has_no_cors_headers(): void
    {
        $response = $this->call(
            'OPTIONS',
            '/api/v1/sync/info',
            [],
            [],
            [],
            [
                'HTTP_ORIGIN' => 'https://evil.example.com',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            ]
        );

        $response->assertStatus(204);
        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }
}
