<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SyncAuthService;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NativeOidcTest extends TestCase
{
    use RefreshDatabase;

    private string $issuer = 'https://accounts.example.invalid/application/o/midori-desktop/';

    private string $discovery = 'https://accounts.example.invalid/application/o/midori-desktop/.well-known/openid-configuration';

    private string $jwks = 'https://accounts.example.invalid/application/o/midori-desktop/jwks/';

    private string $userinfo = 'https://accounts.example.invalid/application/o/userinfo/';

    private string $privateKey = '';

    private array $publicKey;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.sync.local_dev' => false,
            'services.authentik.issuer' => $this->issuer,
            'services.authentik.native_client_id' => 'midori-desktop-public',
            'services.authentik.native_discovery_url' => $this->discovery,
        ]);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        openssl_pkey_export($key, $this->privateKey);
        $details = openssl_pkey_get_details($key);
        $this->publicKey = [
            'kty' => 'RSA', 'kid' => 'test-key', 'alg' => 'RS256', 'use' => 'sig',
            'n' => JWT::urlsafeB64Encode($details['rsa']['n']),
            'e' => JWT::urlsafeB64Encode($details['rsa']['e']),
        ];
        Cache::forget('sync:oidc:'.hash('sha256', $this->discovery));
        Cache::forget('sync:oidc:'.hash('sha256', $this->jwks));
    }

    private function fakeProvider(array $userinfo = [], ?array $jwks = null): void
    {
        Http::fake([
            $this->discovery => Http::response([
                'issuer' => $this->issuer,
                'jwks_uri' => $this->jwks,
                'userinfo_endpoint' => $this->userinfo,
            ]),
            $this->jwks => Http::response($jwks ?? ['keys' => [$this->publicKey]]),
            $this->userinfo => Http::response($userinfo + [
                'sub' => 'astian-user-42', 'email' => 'person@example.invalid', 'email_verified' => true,
                'name' => 'Person',
            ]),
        ]);
    }

    private function token(array $changes = []): string
    {
        return JWT::encode(array_replace([
            'iss' => $this->issuer,
            'aud' => 'midori-desktop-public',
            'sub' => 'astian-user-42',
            'nonce' => str_repeat('n', 43),
            'iat' => time(),
            'exp' => time() + 3600,
        ], $changes), $this->privateKey, 'RS256', 'test-key');
    }

    private function requestBody(?string $token = null): array
    {
        return [
            'id_token' => $token ?? $this->token(),
            'access_token' => 'provider-access-token',
            'nonce' => str_repeat('n', 43),
            'device_name' => 'Midori Desktop',
        ];
    }

    public function test_public_oidc_token_creates_a_renewable_native_device_session_once(): void
    {
        $this->fakeProvider();
        $this->getJson('/api/v1/capabilities')->assertOk()
            ->assertJsonPath('authentication.oidc.client_id', 'midori-desktop-public')
            ->assertJsonPath('authentication.oidc.discovery_url', $this->discovery);
        $body = $this->requestBody();
        $result = $this->postJson('/api/v1/auth/native-token', $body)
            ->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->json();
        $this->assertSame($this->issuer, $result['identity']['issuer']);
        $this->assertSame('astian-user-42', $result['identity']['subject']);
        $this->assertSame('oidc', $result['identity']['kind']);
        $this->assertSame('desktop', $result['device']['type']);
        $this->assertSame(1, $result['refresh_version']);
        $session = app(SyncAuthService::class)->validateToken($result['token']);
        $this->assertSame(2, $session->protocol_version);
        $this->withToken($result['token'])->getJson('/api/v1/account')->assertOk()
            ->assertJsonPath('device.id', $result['device']['id']);
        $this->postJson('/api/v1/auth/native-token', $body)
            ->assertStatus(409)->assertExactJson(['error' => 'oidc_token_reused']);
        $this->assertDatabaseCount('devices', 1);
        $this->assertDatabaseCount('sync_sessions', 1);
        $this->assertDatabaseCount('sync_native_oidc_exchanges', 1);
    }

    public function test_claims_and_userinfo_must_match_the_configured_public_client(): void
    {
        $this->fakeProvider(['sub' => 'different-user']);
        foreach ([
            ['iss' => 'https://wrong.example.invalid/'],
            ['aud' => 'other-client'],
            ['azp' => 'other-client'],
            ['nonce' => str_repeat('x', 43)],
            ['exp' => time() + 90000],
        ] as $claims) {
            $this->postJson('/api/v1/auth/native-token', $this->requestBody($this->token($claims)))
                ->assertUnauthorized()->assertExactJson(['error' => 'invalid_oidc_token']);
        }
        $this->postJson('/api/v1/auth/native-token', $this->requestBody())
            ->assertUnauthorized()->assertExactJson(['error' => 'invalid_oidc_token']);
        $this->assertDatabaseCount('devices', 0);
        $this->assertDatabaseCount('sync_native_oidc_exchanges', 0);
    }

    public function test_unconfigured_or_cross_origin_discovery_never_issues_a_session(): void
    {
        $this->postJson('/api/v1/auth/token', ['token' => 'legacy-oauth-token'])->assertStatus(405);
        config(['services.authentik.native_client_id' => null]);
        $this->getJson('/api/v1/capabilities')->assertJsonPath('authentication.oidc', null);
        $this->postJson('/api/v1/auth/native-token', $this->requestBody())
            ->assertStatus(503)->assertExactJson(['error' => 'oidc_unavailable']);
        config([
            'services.authentik.native_client_id' => 'midori-desktop-public',
            'services.authentik.native_discovery_url' => 'https://other.example.invalid/.well-known/openid-configuration',
        ]);
        $this->postJson('/api/v1/auth/native-token', $this->requestBody())
            ->assertStatus(503)->assertExactJson(['error' => 'oidc_unavailable']);
        $this->assertDatabaseCount('sync_sessions', 0);
    }

    public function test_malformed_jwks_cannot_create_a_device(): void
    {
        $this->fakeProvider([], ['keys' => 'invalid']);
        $this->postJson('/api/v1/auth/native-token', $this->requestBody())
            ->assertStatus(503)->assertExactJson(['error' => 'oidc_unavailable']);
        $this->assertDatabaseCount('devices', 0);
    }

    public function test_existing_email_with_another_subject_cannot_be_claimed_by_a_device(): void
    {
        $this->fakeProvider();
        User::factory()->create(['authentik_id' => 'another-user', 'email' => 'person@example.invalid']);
        $this->postJson('/api/v1/auth/native-token', $this->requestBody())
            ->assertStatus(409)->assertExactJson(['error' => 'invalid_account_identity']);
        $this->assertDatabaseCount('devices', 0);
    }
}
