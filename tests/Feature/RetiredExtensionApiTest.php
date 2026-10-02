<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SyncPairingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RetiredExtensionApiTest extends TestCase
{
    use RefreshDatabase;

    public static function retiredRoutes(): array
    {
        return [
            ['GET', '/api/v1/sync/info'],
            ['GET', '/api/v1/sync/status'],
            ['GET', '/api/v1/collections/bookmarks'],
            ['PUT', '/api/v1/collections/bookmarks/old'],
            ['POST', '/api/v1/collections/bookmarks'],
            ['DELETE', '/api/v1/collections/bookmarks/old'],
            ['PUT', '/api/v1/devices/old'],
            ['GET', '/api/v1/crypto/keys'],
            ['POST', '/api/v1/crypto/keys'],
            ['GET', '/api/v1/crypto/legacy-manifest'],
            ['POST', '/api/v1/crypto/legacy-freeze'],
            ['POST', '/api/v1/crypto/legacy-conversion'],
        ];
    }

    #[DataProvider('retiredRoutes')]
    public function test_retired_extension_route_is_absent(string $method, string $path): void
    {
        $this->assertContains($this->call($method, $path)->status(), [404, 405]);
    }

    public function test_pairing_requires_native_client_flag_without_consuming_code(): void
    {
        $user = User::factory()->create();
        $code = app(SyncPairingService::class)->generate($user)['pairing_token'];
        $this->postJson('/api/v1/pair/redeem', [
            'pairing_token' => $code, 'device_name' => 'Old client',
        ])->assertUnprocessable()->assertJsonValidationErrors('native_client');
        $this->assertDatabaseHas('sync_pairing_codes', ['token_hash' => hash('sha256', $code)]);
    }
}
