<?php

namespace Tests\Feature;

use App\Models\Bso;
use App\Models\Collection;
use App\Models\User;
use App\Services\HawkAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * TokenServer must return api_endpoint with /api/1.5/{uid}.
     */
    public function test_token_server_contract_returns_expected_shape_and_api_endpoint(): void
    {
        config()->set('services.authentik.base_url', 'https://auth.example.com');

        Http::fake([
            'https://auth.example.com/application/o/userinfo/' => Http::response([
                'sub' => 'auth-user-1',
                'name' => 'Demo User',
                'email' => 'demo@example.com',
            ], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer valid-token',
        ])->getJson('/api/1.0/sync/1.5');

        $response->assertOk()
            ->assertHeader('X-Weave-Timestamp')
            ->assertHeader('X-Request-Id')
            ->assertJsonStructure(['id', 'key', 'uid', 'api_endpoint', 'duration', 'hashalg'])
            ->assertJsonPath('hashalg', 'sha256');

        $apiEndpoint = (string) $response->json('api_endpoint');
        $uid = (string) $response->json('uid');
        $this->assertStringContainsString('/api/1.5/' . $uid, $apiEndpoint);
    }

    public function test_hawk_query_string_is_included_in_mac_validation(): void
    {
        $user = User::factory()->create();
        [$header] = $this->makeHawkHeader($user, 'GET', '/api/1.5/' . $user->id . '/storage/bookmarks?limit=1');

        /** @var HawkAuthService $hawk */
        $hawk = app(HawkAuthService::class);
        $request = Request::create('/api/1.5/' . $user->id . '/storage/bookmarks?limit=1', 'GET');
        $request->headers->set('Authorization', $header);

        $authenticated = $hawk->authenticate($request);
        $this->assertNotNull($authenticated);
        $this->assertSame($user->id, $authenticated?->id);
    }

    public function test_hawk_rejects_old_timestamp(): void
    {
        $user = User::factory()->create();
        [$header] = $this->makeHawkHeader(
            $user,
            'GET',
            '/api/1.5/' . $user->id . '/storage/bookmarks',
            null,
            'nonce-old',
            -300
        );

        $response = $this->withHeaders(['Authorization' => $header])
            ->getJson('/api/1.5/' . $user->id . '/storage/bookmarks');

        $response->assertUnauthorized();
    }

    public function test_hawk_rejects_replayed_nonce(): void
    {
        Cache::flush();

        $user = User::factory()->create();
        [$header] = $this->makeHawkHeader(
            $user,
            'GET',
            '/api/1.5/' . $user->id . '/storage/bookmarks',
            null,
            'nonce-replay'
        );

        /** @var HawkAuthService $hawk */
        $hawk = app(HawkAuthService::class);
        $request = Request::create('/api/1.5/' . $user->id . '/storage/bookmarks', 'GET');
        $request->headers->set('Authorization', $header);

        $first = $hawk->authenticate($request);
        $this->assertNotNull($first);

        $second = $hawk->authenticate($request);
        $this->assertNull($second);
    }

    public function test_hawk_rejects_payload_hash_mismatch(): void
    {
        $user = User::factory()->create();

        $payload = ['payload' => '{"hello":"world"}'];
        [$header] = $this->makeHawkHeader(
            $user,
            'PUT',
            '/api/1.5/' . $user->id . '/storage/bookmarks/bso-1',
            json_encode($payload),
            'nonce-hash',
            0,
            'invalid-hash'
        );

        $response = $this->withHeaders(['Authorization' => $header])
            ->putJson('/api/1.5/' . $user->id . '/storage/bookmarks/bso-1', $payload);

        $response->assertUnauthorized();
    }

    public function test_sync_headers_and_info_configuration_contract(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\HawkAuthentication::class);

        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->getJson('/api/1.5/' . $user->id . '/info/configuration');

        $response->assertOk()
            ->assertHeader('X-Weave-Timestamp')
            ->assertHeader('X-Last-Modified')
            ->assertHeader('X-Request-Id')
            ->assertJsonStructure([
                'max_record_payload_bytes',
                'max_post_records',
                'max_request_bytes',
                'max_total_records',
                'max_total_bytes',
                'implicit_collectionname',
                'gzip',
            ]);

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
    }

    public function test_storage_list_returns_weave_records_and_next_offset_headers(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\HawkAuthentication::class);

        $user = User::factory()->create();
        $this->actingAs($user);

        $bookmark = Collection::where('name', 'bookmarks')->firstOrFail();
        Bso::create([
            'user_id' => $user->id,
            'collection_id' => $bookmark->id,
            'bso_id' => 'one',
            'payload' => 'p1',
            'payload_size' => 2,
            'modified' => microtime(true),
        ]);
        Bso::create([
            'user_id' => $user->id,
            'collection_id' => $bookmark->id,
            'bso_id' => 'two',
            'payload' => 'p2',
            'payload_size' => 2,
            'modified' => microtime(true) + 1,
        ]);

        $response = $this->getJson('/api/1.5/' . $user->id . '/storage/bookmarks?limit=1');

        $response->assertOk()
            ->assertHeader('X-Weave-Records', '1')
            ->assertHeader('X-Weave-Next-Offset');
    }

    public function test_get_on_non_existing_collection_does_not_create_collection(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\HawkAuthentication::class);

        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->getJson('/api/1.5/' . $user->id . '/storage/not-initialized');

        $response->assertOk()->assertExactJson([]);
        $this->assertDatabaseMissing('collections', ['name' => 'not-initialized']);
    }

    public function test_full_equals_one_returns_only_expected_bso_fields(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\HawkAuthentication::class);

        $user = User::factory()->create();
        $this->actingAs($user);
        $bookmark = Collection::where('name', 'bookmarks')->firstOrFail();

        Bso::create([
            'user_id' => $user->id,
            'collection_id' => $bookmark->id,
            'bso_id' => 'full-1',
            'payload' => 'secret',
            'payload_size' => 6,
            'modified' => microtime(true),
            'sortindex' => 12,
            'ttl' => 120,
        ]);

        $response = $this->getJson('/api/1.5/' . $user->id . '/storage/bookmarks?full=1');

        $response->assertOk();
        $item = $response->json('0');
        $this->assertSame(['id', 'modified', 'payload', 'sortindex', 'ttl'], array_keys($item));
    }

    public function test_storage_rejects_batch_over_100_and_payload_over_limit(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\HawkAuthentication::class);

        $user = User::factory()->create();
        $this->actingAs($user);

        $batchPayload = [];
        for ($i = 0; $i < 101; $i++) {
            $batchPayload[] = ['id' => 'id-' . $i, 'payload' => 'a'];
        }

        $batchResponse = $this->postJson('/api/1.5/' . $user->id . '/storage/bookmarks', $batchPayload);
        $batchResponse->assertStatus(400)->assertJsonPath('error', 'batch-size-exceeded');

        $tooLarge = [
            ['id' => 'large-item', 'payload' => str_repeat('a', 262145)],
        ];
        $largeResponse = $this->postJson('/api/1.5/' . $user->id . '/storage/bookmarks', $tooLarge);

        $largeResponse->assertOk()->assertJsonPath('failed.large-item.0', 'payload size exceeded');
    }

    public function test_extension_api_contract_ttl_modified_and_headers(): void
    {
        $token = 'plain-api-token';
        $user = User::factory()->create([
            'api_token' => hash('sha256', $token),
        ]);

        $post = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/ext/storage/bookmarks', [
            ['id' => 'ext-1', 'payload' => 'abc', 'ttl' => 60],
        ]);

        $post->assertOk()->assertHeader('X-Request-Id');

        $this->assertDatabaseHas('bso', [
            'user_id' => $user->id,
            'bso_id' => 'ext-1',
            'ttl' => 60,
        ]);

        $info = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/ext/storage/info');

        $info->assertOk()->assertJsonStructure(['collections', 'counts', 'quota']);
        $this->assertArrayHasKey('bookmarks', $info->json('collections'));
    }

    /**
     * El endpoint de extensión ahora acepta hasta 500 items por lote.
     * Esto permite sincronizar el historial completo de 30 días sin batching manual.
     */
    public function test_extension_api_accepts_batch_up_to_500(): void
    {
        $token = 'token-batch-500';
        $user = User::factory()->create([
            'api_token' => hash('sha256', $token),
        ]);

        $batch = [];
        for ($i = 0; $i < 500; $i++) {
            $batch[] = ['id' => 'hist-' . $i, 'payload' => base64_encode(str_repeat('x', 50))];
        }

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/ext/storage/history', $batch);

        $response->assertOk();
        $this->assertCount(500, $response->json('success'));
        $this->assertDatabaseHas('bso', ['user_id' => $user->id, 'bso_id' => 'hist-0']);
        $this->assertDatabaseHas('bso', ['user_id' => $user->id, 'bso_id' => 'hist-499']);
    }

    /**
     * El endpoint de extensión rechaza lotes de más de 500 items.
     */
    public function test_extension_api_rejects_batch_over_500(): void
    {
        $token = 'token-batch-501';
        User::factory()->create([
            'api_token' => hash('sha256', $token),
        ]);

        $batch = [];
        for ($i = 0; $i < 501; $i++) {
            $batch[] = ['id' => 'item-' . $i, 'payload' => 'a'];
        }

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/ext/storage/history', $batch);

        $response->assertStatus(400)->assertJsonPath('error', 'batch-size-exceeded');
    }

    /**
     * Verifica que lotes de 101-100 items (antes rechazados, ahora aceptados)
     * se almacenan correctamente en la base de datos.
     */
    public function test_extension_api_accepts_101_items_that_were_previously_rejected(): void
    {
        $token = 'token-batch-101';
        $user = User::factory()->create([
            'api_token' => hash('sha256', $token),
        ]);

        $batch = [];
        for ($i = 0; $i < 101; $i++) {
            $batch[] = ['id' => 'bk-' . $i, 'payload' => json_encode(['url' => "https://example.com/{$i}"])];
        }

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/ext/storage/bookmarks', $batch);

        $response->assertOk();
        $this->assertSame(101, Bso::where('user_id', $user->id)->count());
    }

    public function test_extension_api_handles_duplicate_bso_ids_in_same_batch(): void
    {
        $token = 'token-duplicate-bso';
        $user = User::factory()->create([
            'api_token' => hash('sha256', $token),
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/ext/storage/bookmarks', [
                ['id' => 'bk-dup', 'payload' => 'first'],
                ['id' => 'bk-dup', 'payload' => 'second'],
            ]);

        $response->assertOk();
        $this->assertSame(['bk-dup'], $response->json('success'));
        $this->assertDatabaseHas('bso', [
            'user_id' => $user->id,
            'bso_id' => 'bk-dup',
            'payload' => 'second',
        ]);
    }

    public function test_extension_cors_forbidden_origin_and_rate_limit(): void
    {
        config()->set('services.sync.extension_cors_origins', 'moz-extension://good,chrome-extension://ok');

        $forbidden = $this->withHeaders([
            'Origin' => 'https://evil.example.com',
        ])->getJson('/api/ext/storage/info');

        $forbidden->assertStatus(403);

        $token = 'rate-token';
        User::factory()->create([
            'api_token' => hash('sha256', $token),
        ]);

        $last = null;
        for ($i = 0; $i < 61; $i++) {
            $last = $this->withHeaders([
                'Authorization' => 'Bearer ' . $token,
            ])->getJson('/api/ext/storage/bookmarks');
        }

        $last?->assertStatus(429);
    }

    /**
     * @return array{0: string, 1: array{id: string, key: string, nonce: string, ts: int}}
     */
    private function makeHawkHeader(
        User $user,
        string $method,
        string $pathWithQuery,
        ?string $content = null,
        ?string $nonce = null,
        int $timeOffsetSeconds = 0,
        ?string $forcedHash = null,
    ): array {
        /** @var HawkAuthService $hawk */
        $hawk = app(HawkAuthService::class);
        $token = $hawk->generateToken($user);

        $ts = time() + $timeOffsetSeconds;
        $nonce = $nonce ?? bin2hex(random_bytes(4));

        $parts = parse_url($pathWithQuery);
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $resource = $path . $query;

        $payloadHash = '';
        if ($content !== null) {
            $payloadHash = $forcedHash ?? base64_encode(hash('sha256', $content, true));
        }

        $normalized = "hawk.1.header\n"
            . $ts . "\n"
            . $nonce . "\n"
            . strtoupper($method) . "\n"
            . $resource . "\n"
            . 'localhost' . "\n"
            . '80' . "\n"
            . $payloadHash . "\n"
            . "\n";

        $rawHawkKey = base64_decode($token['key'], true);
        $hmacKey = pack('H*', hash('sha256', (string) $rawHawkKey));
        $mac = base64_encode(hash_hmac('sha256', $normalized, $hmacKey, true));
        $header = sprintf(
            'Hawk id="%s", ts="%s", nonce="%s", mac="%s"%s',
            $token['id'],
            (string) $ts,
            $nonce,
            $mac,
            $payloadHash !== '' ? ', hash="' . $payloadHash . '"' : ''
        );

        return [$header, ['id' => $token['id'], 'key' => $token['key'], 'nonce' => $nonce, 'ts' => $ts]];
    }
}
