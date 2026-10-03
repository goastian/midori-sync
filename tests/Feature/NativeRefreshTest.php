<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SyncSession;
use App\Models\User;
use App\Services\SyncAuthService;
use App\Services\SyncIdentityService;
use App\Services\SyncPairingService;
use App\Services\SyncRefreshService;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NativeRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sync.local_dev' => true]);
        $this->travelTo(now()->startOfSecond());
    }

    private function renewableSession(?User $user = null): array
    {
        $user ??= User::factory()->create(['authentik_issuer' => SyncIdentityService::DEVELOPMENT_ISSUER]);
        $device = Device::create(['user_id' => $user->id, 'device_id' => (string) Str::uuid(), 'name' => 'Desktop', 'type' => 'desktop']);
        $data = app(SyncAuthService::class)->createSessionToken($user, $device->id, protocolVersion: 2, renewable: true);

        return [$user, $device, $data, SyncSession::where('token_hash', hash('sha256', $data['token']))->firstOrFail()];
    }

    public function test_pairing_issues_refresh_credentials_only_for_explicit_native_opt_in(): void
    {
        $this->getJson('/api/v1/capabilities')->assertOk()->assertJsonPath('native_ready', false)
            ->assertJsonPath('authentication.refresh.version', 1)
            ->assertJsonPath('authentication.refresh.request_bytes', SyncRefreshService::MAX_REQUEST_BYTES);
        $user = User::factory()->create(['authentik_issuer' => SyncIdentityService::DEVELOPMENT_ISSUER]);
        foreach ([false, true] as $refresh) {
            $code = app(SyncPairingService::class)->generate($user)['pairing_token'];
            $result = $this->postJson('/api/v1/pair/redeem', [
                'pairing_token' => $code, 'device_name' => 'Desktop', 'native_refresh' => $refresh,
            ])->assertCreated()->json();
            $this->assertSame($refresh, array_key_exists('refresh_token', $result));
            $session = SyncSession::where('token_hash', hash('sha256', $result['token']))->firstOrFail();
            if ($refresh) {
                $this->assertSame(1, $result['refresh_version']);
                $this->assertSame(SyncRefreshService::tokenHash($result['refresh_token']), $session->refresh_hash);
                $this->assertSame(now()->addDays(30)->toIso8601String(), $result['refresh_expires_at']);
                $this->assertSame($result['device']['id'], $session->refresh_identity['device_id']);
                $this->assertStringNotContainsString($result['refresh_token'], json_encode($session->getRawOriginal()));
            } else {
                $this->assertNull($session->refresh_hash);
            }
        }
        $code = app(SyncPairingService::class)->generate($user)['pairing_token'];
        $this->postJson('/api/v1/pair/redeem', [
            'pairing_token' => $code, 'device_name' => 'Additional device', 'native_refresh' => true,
        ])->assertCreated()->assertJsonStructure(['refresh_token']);
        $this->assertDatabaseCount('devices', 3);
        $this->assertDatabaseCount('sync_sessions', 3);
        $this->assertDatabaseMissing('sync_pairing_codes', ['token_hash' => hash('sha256', $code)]);
    }

    public function test_rotation_recovers_the_exact_response_even_after_access_expiration(): void
    {
        [$user, $device, $data, $session] = $this->renewableSession();
        $this->travel(2)->hours();
        $request = ['refresh_token' => $data['refresh_token'], 'operation_id' => strtoupper((string) Str::uuid())];
        $response = $this->postJson('/api/v1/auth/refresh', $request)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $rotated = $response->json();
        $this->assertSame(strtolower($request['operation_id']), $rotated['operation_id']);
        $this->assertSame($data['refresh_expires_at'], $rotated['refresh_expires_at']);
        $this->assertSame((string) $user->id, $rotated['user']['id']);
        $this->assertSame($device->device_id, $rotated['device']['id']);
        $this->assertSame($session->id, app(SyncAuthService::class)->validateToken($rotated['token'])->id);
        $this->assertNull(app(SyncAuthService::class)->validateToken($data['token']));
        $this->assertDatabaseCount('sync_sessions', 1);
        $this->assertDatabaseCount('devices', 1);
        $receipt = DB::table('sync_refresh_receipts')->first();
        foreach ([$data['refresh_token'], $rotated['refresh_token'], $rotated['token']] as $secret) {
            $this->assertStringNotContainsString($secret, $receipt->response);
        }
        $this->postJson('/api/v1/auth/refresh', $request)->assertOk()->assertExactJson($rotated);
        $this->travel(2)->hours();
        $this->postJson('/api/v1/auth/refresh', $request)->assertOk()->assertExactJson($rotated);
        $this->assertNull(app(SyncAuthService::class)->validateToken($rotated['token']));
        $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $rotated['refresh_token'], 'operation_id' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('refresh_expires_at', $data['refresh_expires_at']);
        $this->assertDatabaseCount('sync_refresh_receipts', 2);
        $this->assertSame(1, DB::table('sync_refresh_receipts')->whereNotNull('response')->count());
    }

    public function test_reuse_with_a_different_operation_commits_revocation_of_only_that_session(): void
    {
        [$user, , $data] = $this->renewableSession();
        [, , $other] = $this->renewableSession($user);
        $this->travel(1)->minutes();
        $request = ['refresh_token' => $data['refresh_token'], 'operation_id' => (string) Str::uuid()];
        $result = $this->postJson('/api/v1/auth/refresh', $request)->assertOk()->json();
        $request['operation_id'] = (string) Str::uuid();
        $this->postJson('/api/v1/auth/refresh', $request)->assertUnauthorized()->assertExactJson(['error' => 'refresh_reused']);
        $this->assertNull(app(SyncAuthService::class)->validateToken($result['token']));
        $this->assertNotNull(app(SyncAuthService::class)->validateToken($other['token']));
        $this->assertDatabaseCount('sync_refresh_receipts', 0);
        $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $result['refresh_token'], 'operation_id' => (string) Str::uuid(),
        ])->assertUnauthorized();
    }

    public function test_superseded_replays_and_operation_collisions_preserve_the_current_family(): void
    {
        [, , $data] = $this->renewableSession();
        $this->travel(1)->minutes();
        $request = ['refresh_token' => $data['refresh_token'], 'operation_id' => (string) Str::uuid()];
        $first = $this->postJson('/api/v1/auth/refresh', $request)->assertOk()->json();
        $this->postJson('/api/v1/auth/refresh', array_replace($request, ['refresh_token' => $first['refresh_token']]))
            ->assertStatus(409)->assertExactJson(['error' => 'refresh_operation_conflict']);
        $this->travel(1)->minutes();
        $second = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $first['refresh_token'], 'operation_id' => (string) Str::uuid(),
        ])->assertOk()->json();
        $this->postJson('/api/v1/auth/refresh', $request)->assertStatus(409)->assertExactJson(['error' => 'refresh_superseded']);
        $this->assertNotNull(app(SyncAuthService::class)->validateToken($second['token']));
        $request['operation_id'] = (string) Str::uuid();
        $this->postJson('/api/v1/auth/refresh', $request)->assertUnauthorized()->assertExactJson(['error' => 'refresh_reused']);
        $this->assertDatabaseCount('sync_sessions', 0);
    }

    public function test_rotation_obeys_frequency_and_absolute_expiration(): void
    {
        config(['services.sync.token_ttl' => 999999]);
        [, , $data] = $this->renewableSession();
        $this->assertSame(3600, $data['expires_in']);
        $request = ['refresh_token' => $data['refresh_token'], 'operation_id' => (string) Str::uuid()];
        $this->postJson('/api/v1/auth/refresh', $request)->assertStatus(429)->assertHeader('Retry-After', '60')
            ->assertExactJson(['error' => 'rate_limited']);
        $this->assertDatabaseCount('sync_refresh_receipts', 0);
        $this->travelTo(Carbon::parse($data['refresh_expires_at'])->subSeconds(15));
        $last = $this->postJson('/api/v1/auth/refresh', $request)->assertOk()->assertJsonPath('expires_in', 15)->json();
        $this->assertSame($last['expires_at'], $last['refresh_expires_at']);
        $this->travel(15)->seconds();
        $this->postJson('/api/v1/auth/refresh', $request)->assertUnauthorized()->assertExactJson(['error' => 'invalid_refresh']);
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $last['refresh_token'], 'operation_id' => (string) Str::uuid()])
            ->assertUnauthorized();
    }

    public static function invalidBindings(): array
    {
        return [['device_removed', 'invalid_refresh'], ['device_foreign', 'invalid_refresh'],
            ['device_changed', 'refresh_identity_changed'], ['subject', 'refresh_identity_changed'],
            ['issuer', 'identity_issuer_mismatch'], ['legacy', 'invalid_refresh']];
    }

    public function test_a_response_delayed_past_absolute_expiration_never_extends_authorization(): void
    {
        [, , $data] = $this->renewableSession();
        $this->travelTo(Carbon::parse($data['refresh_expires_at'])->subSecond());
        SyncSession::updated(fn () => $this->travel(2)->seconds());
        $result = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $data['refresh_token'], 'operation_id' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('expires_in', 0)->assertJsonPath('expires_at', $data['refresh_expires_at'])->json();
        $this->assertNull(app(SyncAuthService::class)->validateToken($result['token']));
    }

    #[DataProvider('invalidBindings')]
    public function test_rotation_cannot_change_identity_or_restore_an_unowned_device(string $change, string $error): void
    {
        [$user, $device, $data, $session] = $this->renewableSession();
        match ($change) {
            'device_removed' => $device->delete(),
            'device_foreign' => $device->update(['user_id' => User::factory()->create()->id]),
            'device_changed' => $device->update(['device_id' => (string) Str::uuid()]),
            'subject' => $user->update(['authentik_id' => 'changed']),
            'issuer' => $user->update(['authentik_issuer' => 'https://another.example']),
            'legacy' => $session->update(['protocol_version' => 1]),
        };
        $this->travel(1)->minutes();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $data['refresh_token'], 'operation_id' => (string) Str::uuid()])
            ->assertStatus(str_starts_with($error, 'invalid') ? 401 : 409)->assertExactJson(['error' => $error]);
        $this->assertDatabaseCount('sync_refresh_receipts', 0);
    }

    public function test_corrupt_or_swapped_encrypted_receipts_never_deliver_credentials(): void
    {
        [, , $data] = $this->renewableSession();
        [, , $other] = $this->renewableSession();
        $this->travel(1)->minutes();
        $request = ['refresh_token' => $data['refresh_token'], 'operation_id' => (string) Str::uuid()];
        $first = $this->postJson('/api/v1/auth/refresh', $request)->assertOk()->json();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $other['refresh_token'], 'operation_id' => $request['operation_id']])->assertOk();
        $foreign = DB::table('sync_refresh_receipts')->where('token_hash', SyncRefreshService::tokenHash($other['refresh_token']))->value('response');
        foreach (['corrupt', $foreign] as $ciphertext) {
            DB::table('sync_refresh_receipts')->where('token_hash', SyncRefreshService::tokenHash($data['refresh_token']))->update(['response' => $ciphertext]);
            $this->postJson('/api/v1/auth/refresh', $request)->assertStatus(503)->assertExactJson(['error' => 'refresh_receipt_unavailable']);
        }
        $this->assertNotNull(app(SyncAuthService::class)->validateToken($first['token']));
    }

    public function test_receipt_and_session_limits_reject_without_rotating_or_consuming_pairing(): void
    {
        [$user, , $data, $session] = $this->renewableSession();
        $receipts = [];
        for ($i = 0; $i < SyncRefreshService::MAX_RECEIPTS; $i++) {
            $receipts[] = ['token_hash' => hash('sha256', 'fixture'.$i), 'session_id' => $session->id,
                'operation_id' => (string) Str::uuid(), 'response' => null, 'created_at' => now()];
        }
        DB::table('sync_refresh_receipts')->insert($receipts);
        $this->travel(1)->minutes();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $data['refresh_token'], 'operation_id' => (string) Str::uuid()])
            ->assertStatus(409)->assertExactJson(['error' => 'refresh_capacity']);
        $this->assertSame(SyncRefreshService::tokenHash($data['refresh_token']), $session->fresh()->refresh_hash);
        for ($i = 1; $i < SyncRefreshService::MAX_SESSIONS; $i++) {
            $this->renewableSession($user);
        }
        $code = app(SyncPairingService::class)->generate($user)['pairing_token'];
        $this->postJson('/api/v1/pair/redeem', ['pairing_token' => $code, 'device_name' => 'Over limit', 'native_refresh' => true])
            ->assertStatus(409)->assertExactJson(['error' => 'refresh_session_capacity']);
        $this->assertDatabaseCount('devices', SyncRefreshService::MAX_SESSIONS);
        $this->assertDatabaseHas('sync_pairing_codes', ['token_hash' => hash('sha256', $code)]);
    }

    public function test_application_key_rotation_requires_the_previous_key_until_the_receipt_is_replaced(): void
    {
        [, , $data] = $this->renewableSession();
        $this->travel(1)->minutes();
        $request = ['refresh_token' => $data['refresh_token'], 'operation_id' => (string) Str::uuid()];
        $rotated = $this->postJson('/api/v1/auth/refresh', $request)->assertOk()->json();
        $original = Crypt::getFacadeRoot();
        $replacement = new Encrypter(random_bytes(32), 'AES-256-CBC');
        try {
            Crypt::swap($replacement);
            $this->postJson('/api/v1/auth/refresh', $request)->assertStatus(503)->assertExactJson(['error' => 'refresh_receipt_unavailable']);
            $replacement->previousKeys([$original->getKey()]);
            $this->postJson('/api/v1/auth/refresh', $request)->assertOk()->assertExactJson($rotated);
            $this->travel(1)->minutes();
            $next = ['refresh_token' => $rotated['refresh_token'], 'operation_id' => (string) Str::uuid()];
            $result = $this->postJson('/api/v1/auth/refresh', $next)->assertOk()->json();
            $replacement->previousKeys([]);
            $this->postJson('/api/v1/auth/refresh', $next)->assertOk()->assertExactJson($result);
            $this->assertDatabaseCount('sync_refresh_receipts', 2);
            $this->assertSame(1, DB::table('sync_refresh_receipts')->whereNotNull('response')->count());
        } finally {
            Crypt::swap($original);
        }
    }

    public function test_migration_rollback_refuses_to_destroy_live_renewal_proofs(): void
    {
        [, , , $session] = $this->renewableSession();
        $migration = require database_path('migrations/2026_09_30_000002_create_native_session_refresh.php');
        try {
            $migration->down();
            $this->fail('Live renewal proofs must prevent rollback.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Revoke renewable sessions before removing their credentials and replay history.', $error->getMessage());
        }
        $this->assertDatabaseHas('sync_sessions', ['id' => $session->id, 'refresh_hash' => $session->refresh_hash]);
        $this->assertDatabaseCount('sync_refresh_receipts', 0);
    }

    public static function accessRevocationRoutes(): array
    {
        return [['DELETE', '/api/v1/auth/token']];
    }

    #[DataProvider('accessRevocationRoutes')]
    public function test_authenticated_logout_revokes_the_same_session_if_credentials_rotate_before_the_handler(string $method, string $path): void
    {
        [, , $data] = $this->renewableSession();
        $this->travel(1)->minutes();
        $this->app->instance('rotate-before-logout', new class($data['refresh_token'])
        {
            public function __construct(private string $proof) {}

            public function handle(Request $request, \Closure $next)
            {
                if (! $request->input('sync_session') instanceof SyncSession) {
                    throw new \RuntimeException('The fixture must rotate after bearer authentication.');
                }
                app(SyncRefreshService::class)->rotate($this->proof, (string) Str::uuid());

                return $next($request);
            }
        });
        $route = app('router')->getRoutes()->match(Request::create($path, $method));
        $route->middleware('rotate-before-logout');
        $this->withToken($data['token'])->json($method, $path)->assertNoContent();
        $this->assertDatabaseCount('sync_sessions', 0);
        $this->assertDatabaseCount('sync_refresh_receipts', 0);
    }

    public function test_cleanup_retains_renewal_proof_and_removes_receipts_only_when_authorization_ends(): void
    {
        [$user, , $data, $session] = $this->renewableSession();
        app(SyncAuthService::class)->createSessionToken($user);
        $this->travel(1)->minutes();
        $result = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $data['refresh_token'], 'operation_id' => (string) Str::uuid()])->assertOk()->json();
        $this->travel(2)->hours();
        $this->assertSame(1, app(SyncAuthService::class)->cleanupExpired());
        $this->assertDatabaseHas('sync_sessions', ['id' => $session->id]);
        $this->assertDatabaseCount('sync_refresh_receipts', 1);
        $this->travelTo(Carbon::parse($result['refresh_expires_at']));
        $this->assertSame(1, app(SyncAuthService::class)->cleanupExpired());
        $this->assertDatabaseCount('sync_refresh_receipts', 0);
    }

    public static function revocations(): array
    {
        return [['refresh_current'], ['refresh_spent'], ['access'], ['audit'], ['audit_all'],
            ['device_web'], ['device_v1']];
    }

    #[DataProvider('revocations')]
    public function test_every_revocation_surface_cascades_renewal_and_replay_history(string $surface): void
    {
        [$user, $device, $data, $session] = $this->renewableSession();
        $this->travel(1)->minutes();
        $request = ['refresh_token' => $data['refresh_token'], 'operation_id' => (string) Str::uuid()];
        $rotated = $this->postJson('/api/v1/auth/refresh', $request)->assertOk()->json();
        if (str_starts_with($surface, 'refresh_')) {
            $this->travel(2)->hours();
            $proof = $surface === 'refresh_current' ? $rotated['refresh_token'] : $data['refresh_token'];
            $this->deleteJson('/api/v1/auth/refresh', ['refresh_token' => $proof])->assertNoContent();
            $this->deleteJson('/api/v1/auth/refresh', ['refresh_token' => $proof])->assertNoContent();
        } elseif ($surface === 'access') {
            $this->withToken($rotated['token'])->deleteJson('/api/v1/auth/token')->assertNoContent();
        } elseif ($surface === 'audit' || $surface === 'audit_all') {
            $this->actingAs($user)->delete('/audit/sessions'.($surface === 'audit' ? '/'.$session->id : ''))->assertRedirect();
        } elseif ($surface === 'device_web') {
            $this->actingAs($user)->delete('/devices/'.$device->device_id)->assertRedirect();
        } else {
            $this->withToken($rotated['token'])->deleteJson('/api/v1/devices/'.$device->device_id)->assertNoContent();
        }
        $this->assertDatabaseMissing('sync_sessions', ['id' => $session->id]);
        $this->assertDatabaseCount('sync_refresh_receipts', 0);
        $this->postJson('/api/v1/auth/refresh', $request)->assertUnauthorized();
        $this->assertNull(app(SyncAuthService::class)->validateToken($rotated['token']));
    }

    public function test_audit_keeps_expired_access_with_live_renewal_visible_and_revocable(): void
    {
        config(['inertia.pages.paths' => [resource_path('js/Pages')]]);
        [$user, , , $session] = $this->renewableSession();
        app(SyncAuthService::class)->createSessionToken($user);
        $this->travel(2)->hours();
        $this->withoutVite()->actingAs($user)->get('/audit?status=active')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Audit/Index')->has('sessions', 1)->where('sessions.0.id', $session->id)
            ->where('sessions.0.active', true)->where('activeTokenCount', 1)->where('expiredCount', 1)
            ->missing('sessions.0.refresh_hash')->missing('sessions.0.refresh_identity'));
        $this->get('/audit?status=expired')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('sessions', 1)->where('sessions.0.active', false));
    }

    public function test_rotation_rolls_back_both_credentials_and_receipt_as_one_unit(): void
    {
        [, , $data, $session] = $this->renewableSession();
        $this->travel(1)->minutes();
        try {
            DB::transaction(function () use ($data) {
                app(SyncRefreshService::class)->rotate($data['refresh_token'], (string) Str::uuid());
                throw new \RuntimeException('synthetic rollback');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('synthetic rollback', $error->getMessage());
        }
        $this->assertDatabaseCount('sync_refresh_receipts', 0);
        $this->assertSame($session->token_hash, $session->fresh()->token_hash);
        $this->assertSame($session->refresh_hash, $session->fresh()->refresh_hash);
    }

    public function test_invalid_requests_do_not_redirect_or_echo_secrets_without_accept_header(): void
    {
        [, , $data] = $this->renewableSession();
        $valid = ['refresh_token' => $data['refresh_token'], 'operation_id' => (string) Str::uuid()];
        $bodies = ['[]', 'null', '{', json_encode(array_replace($valid, ['refresh_token' => ' '.$data['refresh_token']])),
            json_encode(array_replace($valid, ['operation_id' => 'not-a-uuid'])), json_encode($valid + ['extra' => 'secret']),
            json_encode(['refresh_token' => $data['refresh_token']]), json_encode(['operation_id' => $valid['operation_id']]),
            json_encode(array_replace($valid, ['refresh_token' => [$data['refresh_token']]]))];
        foreach ($bodies as $body) {
            $this->call('POST', '/api/v1/auth/refresh', server: ['CONTENT_TYPE' => 'application/json'], content: $body)
                ->assertStatus(422)->assertExactJson(['error' => 'invalid_refresh_request'])->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->call('POST', '/api/v1/auth/refresh', server: ['CONTENT_TYPE' => 'application/json'], content: str_repeat(' ', 4097))
            ->assertStatus(413)->assertExactJson(['error' => 'request_too_large']);
        $this->deleteJson('/api/v1/auth/refresh', $valid)->assertStatus(422);
        $this->postJson('/api/v1/auth/refresh', array_replace($valid, ['refresh_token' => SyncRefreshService::newToken()]))
            ->assertUnauthorized()->assertExactJson(['error' => 'invalid_refresh']);
        $this->deleteJson('/api/v1/auth/refresh', ['refresh_token' => SyncRefreshService::newToken()])->assertNoContent();
        $this->assertDatabaseCount('sync_refresh_receipts', 0);
        $this->assertDatabaseCount('sync_sessions', 1);
    }

    public function test_both_network_throttles_return_bounded_uncacheable_json(): void
    {
        config(['services.sync.unauth_rate_limit' => 1]);
        $body = json_encode(['refresh_token' => SyncRefreshService::newToken(), 'operation_id' => (string) Str::uuid()]);
        $this->call('POST', '/api/v1/auth/refresh', server: ['CONTENT_TYPE' => 'application/json'], content: $body)->assertUnauthorized();
        $this->call('POST', '/api/v1/auth/refresh', server: ['CONTENT_TYPE' => 'application/json'], content: $body)
            ->assertStatus(429)->assertExactJson(['error' => 'rate_limited'])->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Retry-After');
        config(['services.sync.rate_limit_write' => 1]);
        $this->call('POST', '/api/v1/auth/refresh', server: ['CONTENT_TYPE' => 'application/json'], content: $body)
            ->assertStatus(429)->assertExactJson(['error' => 'rate_limited'])->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Retry-After');
    }
}
