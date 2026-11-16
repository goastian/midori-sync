<?php

namespace Tests\Feature;

use App\Models\CryptoKeyBundle;
use App\Models\Device;
use App\Models\SyncSession;
use App\Models\User;
use App\Services\SyncAuthService;
use App\Services\SyncIdentityService;
use App\Services\SyncNativeCryptoService;
use App\Services\SyncNativeEnvelope;
use App\Services\SyncPairingService;
use App\Services\SyncStorageService;
use Database\Seeders\CollectionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NativeCryptoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sync.local_dev' => true]);
        $this->seed(CollectionSeeder::class);
        $this->user = User::factory()->create(['authentik_issuer' => SyncIdentityService::DEVELOPMENT_ISSUER]);
        $code = app(SyncPairingService::class)->generate($this->user)['pairing_token'];
        $paired = $this->postJson('/api/v1/pair/redeem', [
            'pairing_token' => $code, 'device_name' => 'Native fixture',
        ])->assertCreated()->assertJsonPath('user.id', (string) $this->user->id);
        $this->token = $paired->json('token');
        $this->withToken($this->token);
        $this->assertDatabaseHas('sync_sessions', ['token_hash' => hash('sha256', $this->token), 'protocol_version' => 2]);
    }

    private function state(): array
    {
        return $this->getJson('/api/v1/crypto/state')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
    }

    private function context(array $state): array
    {
        return ['epoch' => $state['epoch'], 'revision' => $state['revision']];
    }

    private function key(int $index = 1): array
    {
        $keyId = rtrim(strtr(base64_encode(str_repeat(chr($index), 16)), '+/', '-_'), '=');

        return ['key_id' => $keyId, 'encrypted_bundle' => json_encode([
            'bundle_version' => 2, 'crypto_version' => 2, 'key_id' => $keyId,
            'kdf' => ['algorithm' => 'argon2id13', 'memory_kib' => 65536, 'iterations' => 3, 'parallelism' => 1],
            'salt' => base64_encode(str_repeat('s', 16)), 'nonce' => base64_encode(str_repeat('n', 24)),
            'ciphertext' => base64_encode(str_repeat('c', 48)),
        ], JSON_THROW_ON_ERROR)];
    }

    private function activate(): array
    {
        return $this->postActivation(['crypto' => $this->context($this->state())] + $this->key())
            ->assertOk()->assertJsonPath('mode', 'native')->assertJsonPath('revision', '1')->json();
    }

    private function accountCapsule(array $state): array
    {
        return ['version' => 1, 'scope' => json_encode(['https://sync.astian.org/', SyncIdentityService::DEVELOPMENT_ISSUER, $this->user->authentik_id], JSON_THROW_ON_ERROR),
            'epoch' => $state['epoch'], 'secret' => str_repeat('ab', 32), 'keys' => [$this->key()], 'identity_key' => $this->key(90)];
    }

    public function test_account_recovery_endpoints_never_store_or_disclose_secrets(): void
    {
        $state = $this->activate();
        $before = $this->state();
        $this->getJson('/api/v1/crypto/account-recovery')->assertConflict()
            ->assertJsonPath('error', 'server_recovery_disabled')->assertJsonMissingPath('capsule');
        $this->putJson('/api/v1/crypto/account-recovery', ['crypto' => $this->context($state),
            'capsule' => $this->accountCapsule($state)])->assertConflict()->assertJsonPath('error', 'server_recovery_disabled');
        $this->assertSame($before, $this->state());
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('sync_account_recovery'));
        $this->getJson('/api/v1/capabilities')->assertOk()->assertJsonMissingPath('crypto.account_recovery_version');
        $this->assertArrayNotHasKey('account_recovery', $state);
        SyncSession::where('token_hash', hash('sha256', $this->token))->delete();
        $this->getJson('/api/v1/crypto/account-recovery')->assertUnauthorized();
        $this->putJson('/api/v1/crypto/account-recovery', [])->assertUnauthorized();
    }

    public function test_account_capsules_are_rejected_before_activation_mutates_state(): void
    {
        $before = $this->state();
        foreach ([$this->accountCapsule($before), null, []] as $capsule) {
            $this->postActivation(['crypto' => $this->context($before), 'registry_auth_version' => 2,
                'account_recovery' => $capsule] + $this->key())->assertUnprocessable()->assertJsonValidationErrors('account_recovery');
            $this->assertSame($before, $this->state());
            $this->assertDatabaseCount('sync_native_keys', 0);
        }
    }

    public function test_rotation_and_retirement_reject_account_capsules_without_changes(): void
    {
        $state = $this->activate();
        $operation = (string) Str::uuid();
        $rotation = ['crypto' => $this->context($state), 'operation_id' => $operation,
            'keys' => [$this->key(), $this->key(2)], 'identity_key' => $this->key(90),
            'registry_auth' => str_repeat('b', 64)] + $this->key(2);
        $this->postJson('/api/v1/crypto/rotation', $rotation + ['account_recovery' => $this->accountCapsule($state)])
            ->assertUnprocessable()->assertJsonValidationErrors('account_recovery');
        $this->assertSame($state, $this->state());
        $this->assertDatabaseCount('sync_key_rotations', 0);
        $state = $this->postJson('/api/v1/crypto/rotation', $rotation)->assertOk()->json();
        $state = $this->postJson('/api/v1/crypto/rotation/complete', ['crypto' => $this->context($state),
            'operation_id' => $operation, 'registry_auth' => str_repeat('c', 64)])->assertOk()->json();
        $body = ['crypto' => $this->context($state), 'registry_auth' => str_repeat('d', 64)];
        $this->deleteJson('/api/v1/crypto/native-keys/'.$this->key()['key_id'], $body + ['account_recovery' => $this->accountCapsule($state)])
            ->assertUnprocessable()->assertJsonValidationErrors('account_recovery');
        $this->assertSame($state, $this->state());
        $this->assertDatabaseCount('sync_native_keys', 2);
        $this->deleteJson('/api/v1/crypto/native-keys/'.$this->key()['key_id'], $body)->assertOk();
    }

    public function test_account_recovery_migration_removes_previously_stored_capsules(): void
    {
        $state = $this->activate();
        $generation = $this->generation();
        $operation = $this->operation($generation, $state['active_key_id']);
        $this->submit($state, $generation, $operation)->assertOk()->assertJsonPath('results.0.status', 'applied');
        $create = require database_path('migrations/2026_10_28_000002_create_sync_account_recovery.php');
        $remove = require database_path('migrations/2026_11_15_000001_remove_sync_account_recovery.php');
        $create->up();
        try {
            DB::table('sync_account_recovery')->insert(['user_id' => $this->user->id, 'epoch' => $state['epoch'],
                'registry_fingerprint' => str_repeat('a', 64),
                'encrypted_capsule' => Crypt::encryptString(json_encode($this->accountCapsule($state))),
                'created_at' => now(), 'updated_at' => now()]);
            $this->getJson('/api/v1/crypto/account-recovery')->assertConflict()->assertJsonMissingPath('capsule');
        } finally {
            $remove->up();
        }
        $remove->up();
        $remove->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('sync_account_recovery'));
        $this->assertSame($state, $this->state());
        $this->assertDatabaseHas('records', ['record_id' => $operation['id'], 'payload' => $operation['payload']]);
        $this->assertDatabaseCount('sync_native_keys', 1);
        $this->assertSame($generation, $this->generation());
    }

    private function postActivation(array $body)
    {
        return $this->postJson('/api/v1/crypto/activate', $body + [
            'identity_key' => $this->key(90), 'registry_auth' => str_repeat('a', 64),
        ]);
    }

    private function beginRotation(array $state, int $index): array
    {
        return $this->postJson('/api/v1/crypto/rotation', [
            'crypto' => $this->context($state), 'operation_id' => (string) Str::uuid(),
            'keys' => [...$state['keys'], $this->key($index)],
            'identity_key' => $this->key(90), 'registry_auth' => str_repeat('b', 64),
        ] + $this->key($index))->assertOk()->json();
    }

    public function test_existing_escrow_is_preserved_but_new_escrow_is_rejected(): void
    {
        $state = $this->activate();
        $this->postJson('/api/v1/crypto/recovery', ['crypto' => $this->context($state),
            'keys' => [$this->key()], 'recovery_secret' => str_repeat('a', 64)])
            ->assertConflict()->assertJsonPath('error', 'server_recovery_disabled');
        $this->assertDatabaseCount('sync_server_recovery', 0);
        $this->assertSame($state, $this->state());
    }

    private function generation(): string
    {
        return $this->getJson('/api/v1/sync/collections/bookmarks/changes')->assertOk()->json('generation');
    }

    private function operation(string $generation, string $keyId, string $id = 'record', string $revision = '0'): array
    {
        return ['operation_id' => (string) Str::uuid(), 'id' => $id, 'base_revision' => $revision, 'payload' => json_encode([
            'crypto_version' => 2, 'key_id' => $keyId, 'purpose' => 'record',
            'context' => ['collection' => 'bookmarks', 'id' => $id, 'generation' => $generation, 'schema_version' => 2, 'base_revision' => $revision],
            'nonce' => base64_encode(str_repeat('n', 24)), 'ciphertext' => base64_encode(str_repeat('c', 20)),
        ], JSON_THROW_ON_ERROR)];
    }

    private function submit(array $state, string $generation, array $operation)
    {
        return $this->postJson('/api/v1/sync/collections/bookmarks/operations', [
            'crypto' => $this->context($state), 'generation' => $generation, 'operations' => [$operation],
        ]);
    }

    public function test_activation_changes_the_write_contract_and_is_conditional(): void
    {
        $before = $this->state();
        $this->assertFalse($before['migration_required']);
        $oldGeneration = $this->generation();
        $state = $this->activate();
        $this->assertSame($before['epoch'], $state['epoch']);
        $this->assertSame($this->key()['key_id'], $state['active_key_id']);
        $this->assertNotSame($oldGeneration, $this->generation());
        $this->assertCount(1, $state['keys']);
        $this->assertFalse($state['migration_required']);
        $this->postActivation(['crypto' => $this->context($before)] + $this->key(2))
            ->assertStatus(409)->assertJsonPath('error', 'crypto_state_conflict');
        $this->postActivation(['crypto' => $this->context($state)] + $this->key(2))
            ->assertStatus(409)->assertJsonPath('error', 'native_keys_already_active');
        $this->assertDatabaseCount('sync_native_keys', 1);
    }

    public function test_legacy_writes_and_bundle_replacement_cannot_bypass_activation(): void
    {
        $state = $this->activate();
        $generation = $this->generation();
        $this->postJson('/api/v1/sync/collections/bookmarks/operations', [
            'generation' => $generation, 'operations' => [$this->operation($generation, $state['active_key_id'])],
        ])->assertStatus(426)->assertJsonPath('error', 'client_upgrade_required');
        $this->assertDatabaseCount('records', 0);
        $this->assertDatabaseCount('crypto_key_bundles', 0);
        $this->assertSame($state, $this->state());
    }

    public function test_incompatible_sessions_require_explicit_revocation_and_new_pairing_uses_native_protocol(): void
    {
        $legacy = app(SyncAuthService::class)->createSessionToken($this->user, protocolVersion: 1)['token'];
        $state = $this->state();
        $this->assertSame(1, $state['incompatible_sessions']);
        $request = ['crypto' => $this->context($state)] + $this->key();
        $this->postActivation($request)->assertStatus(409)->assertJsonPath('error', 'incompatible_devices');
        $this->assertDatabaseHas('sync_sessions', ['token_hash' => hash('sha256', $legacy)]);
        $this->postActivation($request + ['revoke_incompatible' => true])
            ->assertOk()->assertJsonPath('incompatible_sessions', 0);
        $this->withToken($legacy)->getJson('/api/v1/crypto/state')->assertUnauthorized();
        $this->withToken($this->token);
        $code = app(SyncPairingService::class)->generate($this->user)['pairing_token'];
        $devices = Device::count();
        $result = $this->postJson('/api/v1/pair/redeem', ['pairing_token' => $code, 'device_name' => 'New browser'])
            ->assertCreated()->json();
        $this->assertDatabaseHas('sync_sessions', [
            'token_hash' => hash('sha256', $result['token']), 'protocol_version' => 2,
        ]);
        $this->assertDatabaseMissing('sync_pairing_codes', ['token_hash' => hash('sha256', $code)]);
        $this->assertSame($devices + 1, Device::count());
    }

    public function test_existing_encrypted_data_or_legacy_keys_require_a_migration(): void
    {
        app(SyncStorageService::class)->upsertRecord($this->user->id, 'bookmarks', 'legacy', 'encrypted legacy payload');
        $state = $this->state();
        $this->postActivation(['crypto' => $this->context($state)] + $this->key())
            ->assertStatus(409)->assertJsonPath('error', 'legacy_migration_required');
        $this->assertTrue($state['migration_required']);
        $this->assertDatabaseHas('records', ['record_id' => 'legacy', 'payload' => 'encrypted legacy payload']);
        $this->deleteJson('/api/v1/sync/data', ['crypto' => $this->context($state)])->assertNoContent();
        $this->assertFalse($this->state()['migration_required']);
        CryptoKeyBundle::create([
            'user_id' => $this->user->id, 'encrypted_bundle' => 'legacy recovery bundle', 'version' => 1,
        ]);
        $this->assertTrue($this->state()['migration_required']);
        $this->postActivation(['crypto' => $this->context($this->state())] + $this->key())
            ->assertStatus(409)->assertJsonPath('error', 'legacy_migration_required');
        $this->assertDatabaseHas('crypto_key_bundles', ['encrypted_bundle' => 'legacy recovery bundle']);
        $this->assertDatabaseCount('sync_native_keys', 0);
    }

    public function test_v2_operations_bind_context_and_preserve_idempotence_during_rotation(): void
    {
        $state = $this->activate();
        $generation = $this->generation();
        $operation = $this->operation($generation, $state['active_key_id']);
        $first = $this->submit($state, $generation, $operation)->assertOk()->assertJsonPath('results.0.status', 'applied')->json();
        $rotated = $this->beginRotation($state, 2);
        $this->assertCount(2, $rotated['keys']);
        $this->submit($state, $generation, $operation)->assertStatus(409)->assertJsonPath('error', 'crypto_state_conflict');
        $this->assertSame($first, $this->submit($rotated, $generation, $operation)->assertOk()->json());
        $this->submit($rotated, $generation, $this->operation($generation, $state['active_key_id'], 'new'))
            ->assertOk()->assertJsonPath('results.0.status', 'invalid');
        $this->submit($rotated, $generation, $this->operation($generation, $rotated['active_key_id'], 'new'))
            ->assertOk()->assertJsonPath('results.0.status', 'applied');
        $this->assertDatabaseCount('records', 2);
        $this->assertDatabaseCount('sync_changes', 2);
    }

    public function test_payment_cards_use_an_independent_encrypted_collection(): void
    {
        $this->assertContains('credit_cards', $this->getJson('/api/v1/capabilities')->assertOk()->json('features'));
        $state = $this->activate();
        $url = '/api/v1/sync/collections/credit-cards';
        $generation = $this->getJson($url.'/changes')->assertOk()->json('generation');
        $operation = $this->operation($generation, $state['active_key_id'], 'cardGuid1234');
        $payload = json_decode($operation['payload'], true, flags: JSON_THROW_ON_ERROR);
        $payload['context']['collection'] = 'credit-cards';
        $operation['payload'] = json_encode($payload, JSON_THROW_ON_ERROR);
        $request = ['crypto' => $this->context($state), 'generation' => $generation, 'operations' => [$operation]];
        $this->postJson($url.'/operations', $request)->assertOk()->assertJsonPath('results.0.status', 'applied');
        $this->assertDatabaseHas('records', ['record_id' => 'cardGuid1234', 'payload' => $operation['payload']]);
        $this->assertStringNotContainsString('4111111111111111', $operation['payload']);
        $this->getJson($url.'/changes')->assertOk()->assertJsonPath('changes.0.record.id', 'cardGuid1234');

        $wrong = $this->operation($generation, $state['active_key_id'], 'otherCard123');
        $this->postJson($url.'/operations', ['crypto' => $this->context($state), 'generation' => $generation,
            'operations' => [$wrong]])->assertOk()->assertJsonPath('results.0.status', 'invalid');
        $this->assertDatabaseMissing('records', ['record_id' => 'otherCard123']);
    }

    public function test_unknown_keys_and_mismatched_record_metadata_never_write(): void
    {
        $state = $this->activate();
        $generation = $this->generation();
        foreach (['key_id', 'purpose', 'id', 'collection', 'generation', 'base_revision', 'schema_version', 'nonce', 'ciphertext', 'extra'] as $field) {
            $operation = $this->operation($generation, $state['active_key_id']);
            $payload = json_decode($operation['payload'], true, flags: JSON_THROW_ON_ERROR);
            if (array_key_exists($field, $payload['context'])) {
                $payload['context'][$field] = 'wrong';
            } else {
                $payload[$field] = 'wrong';
            }
            $operation['payload'] = json_encode($payload, JSON_THROW_ON_ERROR);
            $this->submit($state, $generation, $operation)->assertOk()->assertJsonPath('results.0.status', 'invalid');
        }
        $this->assertDatabaseCount('records', 0);
        $this->assertDatabaseCount('sync_operations', 0);
    }

    public function test_old_rotation_and_rewrap_cannot_bypass_the_authenticated_registry(): void
    {
        $state = $this->activate();
        $this->postJson('/api/v1/crypto/rotate', ['crypto' => $this->context($state)] + $this->key(2))
            ->assertConflict()->assertJsonPath('error', 'durable_rotation_required');
        $this->putJson('/api/v1/crypto/native-keys/'.$state['active_key_id'], [
            'crypto' => $this->context($state), 'encrypted_bundle' => $this->key()['encrypted_bundle'],
        ])->assertConflict()->assertJsonPath('error', 'durable_rotation_required');
        $this->assertSame($state, $this->state());
    }

    public function test_wipe_rotates_epoch_and_never_reenables_legacy_writes(): void
    {
        $state = $this->activate();
        $generation = $this->generation();
        $this->submit($state, $generation, $this->operation($generation, $state['active_key_id']))->assertOk();
        app(SyncStorageService::class)->deleteAllUserData($this->user->id, ownerRequest: true);
        $empty = $this->state();
        $this->assertSame('native', $empty['mode']);
        $this->assertNull($empty['active_key_id']);
        $this->assertNotSame($state['epoch'], $empty['epoch']);
        $this->assertSame('0', $empty['revision']);
        $this->assertSame([], $empty['keys']);
        $this->postActivation(['crypto' => ['epoch' => $state['epoch'], 'revision' => '0']] + $this->key(2))
            ->assertStatus(409)->assertJsonPath('error', 'crypto_state_conflict');
        $fresh = $this->postActivation(['crypto' => $this->context($empty)] + $this->key(2))->assertOk()->json();
        $this->submit($fresh, $generation, $this->operation($generation, $fresh['active_key_id']))->assertStatus(409);
        $this->assertDatabaseCount('records', 0);
    }

    public function test_keys_are_bounded_and_cannot_reuse_an_existing_identifier(): void
    {
        $state = $this->activate();
        for ($i = 2; $i <= SyncNativeCryptoService::MAX_KEYS; $i++) {
            $state = $this->beginRotation($state, $i);
            $state = $this->postJson('/api/v1/crypto/rotation/complete', [
                'crypto' => $this->context($state), 'operation_id' => $state['rotation']['operation_id'], 'registry_auth' => str_repeat('f', 64),
            ])->assertOk()->json();
        }
        $this->postJson('/api/v1/crypto/rotation', [
            'crypto' => $this->context($state), 'operation_id' => (string) Str::uuid(),
            'identity_key' => $this->key(90), 'registry_auth' => str_repeat('b', 64),
            'keys' => [...$state['keys'], $this->key(9)],
        ] + $this->key(9))->assertUnprocessable();
        $this->assertCount(8, $this->state()['keys']);
    }

    public function test_legacy_foreign_expired_or_unbound_sessions_cannot_access_native_keys(): void
    {
        $legacy = app(SyncAuthService::class)->createSessionToken($this->user, protocolVersion: 1)['token'];
        $this->withToken($legacy)->getJson('/api/v1/crypto/state')->assertUnauthorized();
        $this->withToken($this->token);
        $session = SyncSession::where('token_hash', hash('sha256', $this->token))->firstOrFail();
        $other = User::factory()->create();
        $device = Device::create(['user_id' => $other->id, 'device_id' => (string) Str::uuid(), 'name' => 'Other', 'type' => 'desktop']);
        $original = $session->device_id;
        $session->update(['device_id' => $device->id]);
        $this->getJson('/api/v1/crypto/state')->assertUnauthorized();
        $session->update(['device_id' => $original]);
        $this->user->update(['authentik_issuer' => 'https://other.example.invalid/']);
        $this->getJson('/api/v1/crypto/state')->assertStatus(409)->assertJsonPath('error', 'identity_issuer_mismatch');
        $session->update(['expires_at' => now()->subSecond()]);
        $this->getJson('/api/v1/crypto/state')->assertUnauthorized();
    }

    public function test_invalid_bundles_and_kdf_costs_are_rejected_without_a_state_change(): void
    {
        $state = $this->state();
        foreach (['crypto_version', 'bundle_version', 'salt', 'nonce', 'ciphertext', 'key_id', 'kdf', 'extra'] as $field) {
            $key = $this->key();
            $bundle = json_decode($key['encrypted_bundle'], true, flags: JSON_THROW_ON_ERROR);
            $bundle[$field] = 'invalid';
            $key['encrypted_bundle'] = json_encode($bundle, JSON_THROW_ON_ERROR);
            $this->postActivation(['crypto' => $this->context($state)] + $key)
                ->assertStatus(422)->assertJsonPath('error', 'invalid_native_bundle');
        }
        $this->assertSame($state, $this->state());
        $this->assertDatabaseCount('sync_native_keys', 0);
    }

    public function test_native_bundle_and_envelope_parsers_accept_the_independent_sodium_fixture(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/native-sodium.json'), true, flags: JSON_THROW_ON_ERROR);
        $parser = app(SyncNativeEnvelope::class);
        $parser->validateBundle($fixture['key_id'], json_encode($fixture['bundle'], JSON_THROW_ON_ERROR));
        foreach (SyncNativeEnvelope::COLLECTIONS as $collection) {
            $envelope = $fixture['records'][$collection]['record']['envelope'];
            $this->assertTrue($parser->validRecord([
                'deleted' => false, 'id' => $envelope['context']['id'], 'base_revision' => $envelope['context']['base_revision'],
                'payload' => json_encode($envelope, JSON_THROW_ON_ERROR),
            ], $collection, $envelope['context']['generation'], $fixture['key_id']));
        }
    }

    public function test_account_boundaries_apply_to_state_keys_and_preconditions(): void
    {
        $state = $this->activate();
        $other = User::factory()->create(['authentik_issuer' => SyncIdentityService::DEVELOPMENT_ISSUER]);
        $device = Device::create(['user_id' => $other->id, 'device_id' => (string) Str::uuid(), 'name' => 'Other', 'type' => 'desktop']);
        $otherToken = app(SyncAuthService::class)->createSessionToken($other, $device->id, protocolVersion: 2)['token'];
        $this->withToken($otherToken);
        $empty = $this->state();
        $this->assertSame([], $empty['keys']);
        $this->assertNotSame($state['epoch'], $empty['epoch']);
        $this->postActivation(['crypto' => $this->context($state), 'user_id' => $this->user->id] + $this->key(2))
            ->assertStatus(409)->assertJsonPath('error', 'crypto_state_conflict');
        $this->postActivation(['crypto' => $this->context($empty), 'user_id' => $this->user->id] + $this->key(2))->assertOk();
        $this->withToken($this->token);
        $this->assertSame($state, $this->state());
        $this->assertDatabaseHas('sync_native_keys', ['user_id' => $other->id] + $this->key(2));
        $this->assertDatabaseCount('sync_native_keys', 2);
    }

    public function test_migration_rollback_cannot_remove_the_native_write_barrier(): void
    {
        $this->activate();
        $migration = require database_path('migrations/2026_09_29_000005_create_native_crypto_state.php');
        try {
            $migration->down();
            $this->fail('Native accounts must not lose the write barrier during rollback.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('compatible migration', $error->getMessage());
        }
        $this->assertDatabaseCount('sync_native_keys', 1);
        app(SyncStorageService::class)->deleteAllUserData($this->user->id, ownerRequest: true);
        try {
            $migration->down();
            $this->fail('Wiping data must not enable rollback to legacy writers.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('compatible migration', $error->getMessage());
        }
        $this->assertSame('native', $this->state()['mode']);
    }
}
