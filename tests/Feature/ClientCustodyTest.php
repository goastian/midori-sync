<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Models\User;
use App\Services\SyncQuota;
use App\Services\SyncStorageService;
use Database\Seeders\CollectionSeeder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientCustodyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CollectionSeeder::class);
        $this->user = User::factory()->create();
        $this->withToken($this->createNativeSessionToken($this->user)['token']);
    }

    private function key(int $index): array
    {
        $id = rtrim(strtr(base64_encode(str_repeat(chr($index), 16)), '+/', '-_'), '=');

        return ['key_id' => $id, 'encrypted_bundle' => json_encode([
            'bundle_version' => 2, 'crypto_version' => 2, 'key_id' => $id,
            'kdf' => ['algorithm' => 'argon2id13', 'memory_kib' => 65536, 'iterations' => 3, 'parallelism' => 1],
            'salt' => base64_encode(str_repeat('s', 16)), 'nonce' => base64_encode(str_repeat('n', 24)),
            'ciphertext' => base64_encode(str_repeat(chr($index), 48)),
        ], JSON_THROW_ON_ERROR)];
    }

    private function state(): array
    {
        return $this->getJson('/api/v1/crypto/state')->assertOk()->json();
    }

    private function context(array $state): array
    {
        return ['epoch' => $state['epoch'], 'revision' => $state['revision']];
    }

    private function activation(): array
    {
        return ['crypto' => $this->context($this->state()), 'custody' => 'client', 'registry_auth' => str_repeat('d', 64), 'identity_key' => $this->key(90)] + $this->key(1);
    }

    private function activate(): array
    {
        return $this->postJson('/api/v1/crypto/activate', $this->activation())->assertOk()->json();
    }

    private function legacy(): array
    {
        $state = $this->state();
        $key = $this->key(1);
        DB::table('sync_crypto_states')->where('user_id', $this->user->id)->update([
            'native_enabled' => true, 'active_key_id' => $key['key_id'], 'revision' => 1,
        ]);
        DB::table('sync_native_keys')->insert(['user_id' => $this->user->id] + $key + ['created_at' => now(), 'updated_at' => now()]);
        DB::table('sync_server_recovery')->insert([
            'user_id' => $this->user->id, 'epoch' => $state['epoch'], 'encrypted_secret' => Crypt::encryptString(str_repeat('a', 64)),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->state();
    }

    private function rotation(array $state, int $index = 2): array
    {
        return ['crypto' => $this->context($state), 'operation_id' => (string) Str::uuid(),
            'registry_auth' => str_repeat('e', 64), 'keys' => [...$state['keys'], $this->key($index)], 'identity_key' => $this->key(90)] + $this->key($index);
    }

    private function operation(array $state, string $id, string $revision = '0', bool $deleted = false, ?string $ttl = null, int $schema = 2, string $collection = 'bookmarks'): array
    {
        $generation = $this->getJson('/api/v1/sync/collections/'.$collection.'/changes')->assertOk()->json('generation');
        $payload = json_encode(['crypto_version' => 2, 'key_id' => $state['active_key_id'], 'purpose' => 'record',
            'context' => ['collection' => $collection, 'id' => $id, 'generation' => $generation,
                'schema_version' => $schema, 'base_revision' => $revision],
            'nonce' => base64_encode(str_repeat('n', 24)), 'ciphertext' => base64_encode(str_repeat('c', 48)),
        ], JSON_THROW_ON_ERROR);

        return ['crypto' => $this->context($state), 'generation' => $generation,
            'operations' => [['operation_id' => (string) Str::uuid(), 'id' => $id,
                'base_revision' => $revision, 'deleted' => $deleted, 'ttl' => $ttl, 'payload' => $payload]]];
    }

    public function test_activation_never_accepts_a_recovery_secret_and_requires_separate_identity(): void
    {
        $body = $this->activation();
        foreach ([null, '', str_repeat('a', 64)] as $secret) {
            $this->postJson('/api/v1/crypto/activate', $body + ['recovery_secret' => $secret])->assertUnprocessable();
        }
        $this->postJson('/api/v1/crypto/activate', array_replace($body, ['identity_key' => $this->key(1)]))->assertUnprocessable();
        $state = $this->postJson('/api/v1/crypto/activate', $body)->assertOk()
            ->assertJsonPath('custody', 'client')->assertJsonPath('server_recovery', false)->json();
        $this->assertSame($this->key(90), $state['identity_key']);
        $this->assertDatabaseCount('sync_server_recovery', 0);
        $this->getJson('/api/v1/crypto/recovery')->assertConflict()->assertJsonPath('error', 'server_recovery_disabled');
        $this->postJson('/api/v1/crypto/recovery', ['recovery_secret' => str_repeat('a', 64)])
            ->assertConflict()->assertJsonPath('error', 'server_recovery_disabled');
    }

    public function test_legacy_escrow_is_preserved_but_never_disclosed_by_session_or_migration_headers(): void
    {
        $this->legacy();
        $this->getJson('/api/v1/crypto/recovery')->assertConflict()->assertJsonPath('error', 'server_recovery_disabled');
        $this->withHeader('X-Midori-Recovery-Intent', 'migrate-to-client')->getJson('/api/v1/crypto/recovery')
            ->assertConflict()->assertJsonPath('error', 'server_recovery_disabled')->assertJsonMissingPath('recovery_secret');
        $this->assertDatabaseCount('sync_server_recovery', 1);
    }

    public function test_retained_legacy_escrow_can_be_read_with_previous_application_keys_offline(): void
    {
        $this->legacy();
        $ciphertext = DB::table('sync_server_recovery')->where('user_id', $this->user->id)->value('encrypted_secret');
        $original = Crypt::getFacadeRoot();
        $replacement = new Encrypter(random_bytes(32), 'AES-256-CBC');
        try {
            Crypt::swap($replacement);
            $replacement->previousKeys([$original->getKey()]);
            $this->assertSame(str_repeat('a', 64), Crypt::decryptString($ciphertext));
            $this->getJson('/api/v1/crypto/recovery')->assertConflict()->assertJsonPath('error', 'server_recovery_disabled');
        } finally {
            Crypt::swap($original);
        }
    }

    public function test_schema_two_and_encrypted_tombstones_are_required_and_preserved(): void
    {
        $state = $this->activate();
        $url = '/api/v1/sync/collections/bookmarks/operations';
        $legacy = $this->operation($state, 'bookmark', schema: 1);
        $this->postJson($url, $legacy)->assertOk()->assertJsonPath('results.0.status', 'invalid');
        $unsigned = $this->operation($state, 'bookmark', deleted: true);
        $unsigned['operations'][0]['payload'] = '';
        $this->postJson($url, $unsigned)->assertOk()->assertJsonPath('results.0.status', 'invalid');
        $body = $this->operation($state, 'bookmark', deleted: true);
        $this->postJson($url, $body)->assertOk()->assertJsonPath('results.0.status', 'applied');
        $this->getJson('/api/v1/sync/collections/bookmarks/changes')->assertOk()
            ->assertJsonPath('changes.0.record.deleted', true)
            ->assertJsonPath('changes.0.record.payload', $body['operations'][0]['payload']);
        $this->assertSame(strlen($body['operations'][0]['payload']), app(SyncQuota::class)->usage($this->user->id));
    }

    public function test_ttl_cleanup_and_unsigned_controls_cannot_forge_client_deletions(): void
    {
        $state = $this->activate();
        $body = $this->operation($state, 'bookmark', ttl: now()->subMinute()->toIso8601String());
        $this->postJson('/api/v1/sync/collections/bookmarks/operations', $body)->assertOk()->assertJsonPath('results.0.status', 'applied');
        $this->assertSame(0, app(SyncStorageService::class)->cleanupExpiredRecords());
        $this->assertDatabaseHas('records', ['record_id' => 'bookmark', 'deleted' => false]);
        $generation = $this->getJson('/api/v1/sync/collections/history/changes')->assertOk()->json('generation');
        $this->postJson('/api/v1/sync/collections/history/clear', ['crypto' => $this->context($state),
            'generation' => $generation, 'operation_id' => (string) Str::uuid()])->assertConflict()
            ->assertJsonPath('error', 'authenticated_control_required');
        $this->deleteJson('/api/v1/sync/data', ['crypto' => $this->context($state)])->assertConflict()
            ->assertJsonPath('error', 'authenticated_control_required');
        $this->assertDatabaseCount('records', 1);
    }

    public function test_rotation_is_recoverable_after_lost_response_and_preserves_legacy_escrow_until_complete(): void
    {
        $before = $this->legacy();
        $this->postJson('/api/v1/sync/collections/bookmarks/operations', $this->operation($before, 'bookmark', schema: 1))
            ->assertOk()->assertJsonPath('results.0.status', 'applied');
        $body = $this->rotation($before);
        $state = $this->postJson('/api/v1/crypto/rotation', $body)->assertOk()
            ->assertJsonPath('custody', 'transition')->assertJsonPath('rotation.live_records', 1)->json();
        $this->postJson('/api/v1/crypto/rotation', $body)->assertOk()->assertExactJson($state);
        $this->getJson('/api/v1/crypto/rotation')->assertOk()->assertExactJson($state);
        $this->assertDatabaseCount('sync_server_recovery', 1);
        $complete = ['crypto' => $this->context($state), 'operation_id' => $body['operation_id'], 'registry_auth' => str_repeat('f', 64)];
        $this->postJson('/api/v1/crypto/rotation/complete', $complete + ['recovery_secret' => null])->assertUnprocessable();
        $this->postJson('/api/v1/crypto/rotation/complete', ['crypto' => $complete['crypto'], 'operation_id' => $body['operation_id']])
            ->assertUnprocessable();
        $this->postJson('/api/v1/crypto/rotation/complete', $complete)->assertConflict()->assertJsonPath('error', 'rotation_records_pending');
        $this->postJson('/api/v1/sync/collections/bookmarks/operations', $this->operation($state, 'bookmark', '1'))
            ->assertOk()->assertJsonPath('results.0.status', 'applied');
        $final = $this->postJson('/api/v1/crypto/rotation/complete', $complete)->assertOk()
            ->assertJsonPath('custody', 'client')->assertJsonPath('server_recovery', false)
            ->assertJsonPath('registry_auth', str_repeat('f', 64))
            ->assertJsonPath('rotation.phase', 'complete')->assertJsonPath('rotation.live_records', 0)
            ->assertJsonPath('rotation.journal_records', 1)->json();
        $this->postJson('/api/v1/crypto/rotation/complete', $complete)->assertOk()->assertExactJson($final);
        $this->postJson('/api/v1/crypto/rotation/complete', array_replace($complete, ['registry_auth' => str_repeat('b', 64)]))
            ->assertOk()->assertExactJson($final);
        $this->assertCount(2, $final['keys']);
        $this->assertDatabaseCount('sync_server_recovery', 0);
        $this->deleteJson('/api/v1/crypto/native-keys/'.$this->key(1)['key_id'], ['crypto' => $this->context($final), 'registry_auth' => str_repeat('f', 64)])
            ->assertConflict()->assertJsonPath('error', 'key_in_use');
    }

    public function test_rotation_validates_identity_full_registry_context_and_operation_reuse(): void
    {
        $state = $this->activate();
        $body = $this->rotation($state);
        $missing = $body;
        $missing['keys'] = [$this->key(2), $this->key(3)];
        $this->postJson('/api/v1/crypto/rotation', $missing)->assertUnprocessable()->assertJsonPath('error', 'incomplete_key_registry');
        $this->postJson('/api/v1/crypto/rotation', array_replace($body, ['identity_key' => $this->key(91)]))
            ->assertUnprocessable()->assertJsonPath('error', 'invalid_identity_key');
        foreach ([null, '', str_repeat('a', 64)] as $secret) {
            $this->postJson('/api/v1/crypto/rotation', $body + ['recovery_secret' => $secret])->assertUnprocessable();
        }
        $changed = $this->postJson('/api/v1/crypto/rotation', $body)->assertOk()->json();
        $different = $body;
        $bundle = json_decode($different['identity_key']['encrypted_bundle'], true, flags: JSON_THROW_ON_ERROR);
        $bundle['ciphertext'] = base64_encode(str_repeat('z', 48));
        $different['identity_key']['encrypted_bundle'] = json_encode($bundle, JSON_THROW_ON_ERROR);
        $this->postJson('/api/v1/crypto/rotation', $different)->assertConflict()->assertJsonPath('error', 'idempotency_conflict');
        $this->postJson('/api/v1/crypto/rotation', $this->rotation($changed, 3))->assertConflict()->assertJsonPath('error', 'rotation_in_progress');
        $this->assertCount(2, $this->state()['keys']);
    }

    public function test_rotation_records_are_paginated_and_scoped_and_revisited_when_rewritten(): void
    {
        $state = $this->activate();
        foreach (['first', 'second', 'third'] as $id) {
            $this->postJson('/api/v1/sync/collections/bookmarks/operations', $this->operation($state, $id))->assertOk();
        }
        $state = $this->postJson('/api/v1/crypto/rotation', $this->rotation($state))->assertOk()->json();
        $first = $this->getJson('/api/v1/crypto/rotation/records?limit=1')->assertOk()->assertJsonCount(1, 'records')->json();
        $this->assertTrue($first['has_more']);
        $next = $this->getJson('/api/v1/crypto/rotation/records?limit=1&cursor='.urlencode($first['next_cursor']))
            ->assertOk()->json();
        $this->assertNotSame($first['records'][0]['record']['id'], $next['records'][0]['record']['id']);
        $this->getJson('/api/v1/crypto/rotation/records?cursor=invalid')->assertBadRequest();
        foreach (Record::where('user_id', $this->user->id)->get() as $record) {
            $this->postJson('/api/v1/sync/collections/bookmarks/operations', $this->operation($state, $record->record_id, '1'))->assertOk();
        }
        $this->getJson('/api/v1/crypto/rotation/records')->assertOk()->assertJsonCount(0, 'records')->assertJsonPath('has_more', false);
        $other = User::factory()->create();
        $this->withToken($this->createNativeSessionToken($other)['token']);
        $otherState = $this->activate();
        $this->postJson('/api/v1/crypto/rotation', $this->rotation($otherState))->assertOk();
        $this->getJson('/api/v1/crypto/rotation/records?cursor='.urlencode($first['next_cursor']))->assertBadRequest();
    }

    public function test_retirement_removes_only_unreferenced_data_keys_and_retains_identity(): void
    {
        $state = $this->activate();
        $body = $this->rotation($state);
        $state = $this->postJson('/api/v1/crypto/rotation', $body)->assertOk()->json();
        $state = $this->postJson('/api/v1/crypto/rotation/complete', ['crypto' => $this->context($state),
            'operation_id' => $body['operation_id'], 'registry_auth' => str_repeat('f', 64)])->assertOk()->json();
        foreach ([$state['active_key_id'], $state['identity_key']['key_id']] as $keyId) {
            $this->deleteJson('/api/v1/crypto/native-keys/'.$keyId, ['crypto' => $this->context($state), 'registry_auth' => str_repeat('f', 64)])
                ->assertConflict()->assertJsonPath('error', 'key_retirement_forbidden');
        }
        $this->deleteJson('/api/v1/crypto/native-keys/'.$this->key(1)['key_id'], ['crypto' => $this->context($state), 'registry_auth' => str_repeat('f', 64)])
            ->assertOk()->assertJsonCount(1, 'keys')->assertJsonPath('identity_key', $this->key(90));
    }

    public function test_schema_rollback_is_refused_after_client_activation(): void
    {
        $this->activate();
        $migration = require base_path('database/migrations/2026_10_15_000001_add_client_key_custody.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_malformed_retained_payload_blocks_key_retirement(): void
    {
        $state = $this->activate();
        $body = $this->rotation($state);
        $state = $this->postJson('/api/v1/crypto/rotation', $body)->assertOk()->json();
        $state = $this->postJson('/api/v1/crypto/rotation/complete', ['crypto' => $this->context($state),
            'operation_id' => $body['operation_id'], 'registry_auth' => str_repeat('f', 64)])->assertOk()->json();
        $this->postJson('/api/v1/sync/collections/bookmarks/operations', $this->operation($state, 'bookmark'))->assertOk();
        DB::table('sync_changes')->update(['record' => json_encode(['payload' => json_encode(['key_id' => $state['active_key_id']])])]);
        $this->deleteJson('/api/v1/crypto/native-keys/'.$this->key(1)['key_id'], ['crypto' => $this->context($state), 'registry_auth' => str_repeat('f', 64)])
            ->assertConflict()->assertJsonPath('error', 'key_in_use');
        $this->assertDatabaseCount('sync_native_keys', 2);
    }

    private function historyMigration(): array
    {
        $state = $this->legacy();
        $source = 'h1:'.str_repeat('a', 64);
        $target = 'h2:'.str_repeat('b', 64);
        $this->postJson('/api/v1/sync/collections/history/operations', $this->operation($state, $source, schema: 1, collection: 'history'))
            ->assertOk()->assertJsonPath('results.0.status', 'applied');
        $state = $this->postJson('/api/v1/crypto/rotation', $this->rotation($state))->assertOk()->json();
        $deleted = $this->operation($state, $source, '1', deleted: true, collection: 'history');
        $created = $this->operation($state, $target, collection: 'history');

        return [$state, ['crypto' => $this->context($state), 'operation_id' => (string) Str::uuid(),
            'rotation_id' => $state['rotation']['operation_id'], 'generation' => $created['generation'],
            'source_id' => $source, 'source_revision' => '1', 'target_id' => $target,
            'source_payload' => $deleted['operations'][0]['payload'], 'target_payload' => $created['operations'][0]['payload']]];
    }

    public function test_history_id_migration_is_atomic_idempotent_and_exports_old_ids_even_after_reciphering(): void
    {
        [$state, $body] = $this->historyMigration();
        $response = $this->postJson('/api/v1/crypto/rotation/history-id', $body)->assertOk()
            ->assertJsonPath('source_revision', '2')->assertJsonPath('target_revision', '1')->json();
        $this->postJson('/api/v1/crypto/rotation/history-id', $body)->assertOk()->assertExactJson($response);
        $this->assertDatabaseHas('records', ['record_id' => $body['source_id'], 'deleted' => true, 'payload' => $body['source_payload']]);
        $this->assertDatabaseHas('records', ['record_id' => $body['target_id'], 'deleted' => false, 'payload' => $body['target_payload']]);
        $this->assertDatabaseCount('sync_changes', 3);
        $this->assertDatabaseCount('sync_history_id_migrations', 1);
        $changed = array_replace($body, ['target_id' => 'h2:'.str_repeat('c', 64)]);
        $this->postJson('/api/v1/crypto/rotation/history-id', $changed)->assertConflict()->assertJsonPath('error', 'idempotency_conflict');
        $remaining = $this->getJson('/api/v1/crypto/rotation/records')->assertOk()->json('records');
        $this->assertSame([], $remaining);

        $oldId = 'h1:'.str_repeat('d', 64);
        $this->postJson('/api/v1/sync/collections/history/operations', $this->operation($state, $oldId, collection: 'history'))->assertOk();
        $this->getJson('/api/v1/crypto/rotation/records')->assertOk()->assertJsonPath('records.0.record.id', $oldId);
        $this->getJson('/api/v1/crypto/state')->assertOk()->assertJsonPath('rotation.live_records', 1);
    }

    public function test_history_id_collision_or_invalid_tombstone_never_removes_the_source(): void
    {
        [$state, $body] = $this->historyMigration();
        $bad = $body;
        $source = json_decode($bad['source_payload'], true, flags: JSON_THROW_ON_ERROR);
        $source['context']['schema_version'] = 1;
        $bad['source_payload'] = json_encode($source, JSON_THROW_ON_ERROR);
        $this->postJson('/api/v1/crypto/rotation/history-id', $bad)->assertConflict()->assertJsonPath('error', 'history_migration_invalid');
        $this->assertDatabaseHas('records', ['record_id' => $body['source_id'], 'deleted' => false, 'version' => 1]);
        $this->assertDatabaseMissing('records', ['record_id' => $body['target_id']]);
        $this->assertDatabaseCount('sync_changes', 1);
        $this->assertDatabaseCount('sync_history_id_migrations', 0);
        $this->postJson('/api/v1/sync/collections/history/operations', $this->operation($state, $body['target_id'], collection: 'history'))->assertOk();
        $this->postJson('/api/v1/crypto/rotation/history-id', $body)->assertConflict()->assertJsonPath('error', 'history_target_exists');
        $this->assertDatabaseHas('records', ['record_id' => $body['source_id'], 'deleted' => false, 'version' => 1]);
    }

    public function test_history_id_migration_needs_only_the_final_storage_quota(): void
    {
        [, $body] = $this->historyMigration();
        $payload = json_decode(Record::where('record_id', $body['source_id'])->value('payload'), true, flags: JSON_THROW_ON_ERROR);
        $payload['ciphertext'] = base64_encode(str_repeat('c', 4096));
        Record::where('record_id', $body['source_id'])->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
        $finalBytes = strlen($body['source_payload']) + strlen($body['target_payload']);
        $this->user->update(['storage_quota_bytes' => $finalBytes]);

        $response = $this->postJson('/api/v1/crypto/rotation/history-id', $body)->assertOk()
            ->assertJsonPath('source_revision', '2')->assertJsonPath('target_revision', '1')->json();
        $this->assertSame($finalBytes, app(SyncQuota::class)->usage($this->user->id));
        $this->postJson('/api/v1/crypto/rotation/history-id', $body)->assertOk()->assertExactJson($response);
        $this->assertDatabaseCount('sync_changes', 3);
        $this->assertDatabaseCount('sync_history_id_migrations', 1);
    }

    public function test_history_id_migration_rolls_back_every_change_when_final_quota_is_exceeded(): void
    {
        [, $body] = $this->historyMigration();
        $source = Record::where('record_id', $body['source_id'])->firstOrFail()->getAttributes();
        $operations = DB::table('sync_operations')->count();
        $finalBytes = strlen($body['source_payload']) + strlen($body['target_payload']);
        $this->user->update(['storage_quota_bytes' => $finalBytes - 1]);

        $this->postJson('/api/v1/crypto/rotation/history-id', $body)->assertConflict()
            ->assertJsonPath('error', 'history_migration_quota_exceeded');
        $this->assertSame($source, Record::where('record_id', $body['source_id'])->firstOrFail()->getAttributes());
        $this->assertDatabaseMissing('records', ['record_id' => $body['target_id']]);
        $this->assertDatabaseCount('sync_operations', $operations);
        $this->assertDatabaseCount('sync_changes', 1);
        $this->assertDatabaseCount('sync_history_id_migrations', 0);
        $this->assertSame(strlen($source['payload']), app(SyncQuota::class)->usage($this->user->id));
    }

    public function test_history_id_migration_preserves_expired_ttl_and_replays_after_completion(): void
    {
        [$state, $body] = $this->historyMigration();
        $ttl = now()->subMinute()->startOfSecond()->toIso8601String();
        Record::where('record_id', $body['source_id'])->update(['ttl' => $ttl]);
        $this->postJson('/api/v1/crypto/rotation/history-id', $body)->assertConflict()->assertJsonPath('error', 'history_source_conflict');
        $body['target_ttl'] = $ttl;
        $response = $this->postJson('/api/v1/crypto/rotation/history-id', $body)->assertOk()->json();
        $this->assertSame($ttl, Record::where('record_id', $body['target_id'])->firstOrFail()->ttl->toIso8601String());
        $this->assertNull(Record::where('record_id', $body['source_id'])->firstOrFail()->ttl);
        $this->postJson('/api/v1/crypto/rotation/complete', ['crypto' => $this->context($state),
            'operation_id' => $state['rotation']['operation_id'], 'registry_auth' => str_repeat('f', 64)])->assertOk();
        $this->postJson('/api/v1/crypto/rotation/history-id', $body)->assertOk()->assertExactJson($response);
        $this->assertDatabaseCount('sync_history_id_migrations', 1);
        $body['crypto']['epoch'] = (string) Str::uuid();
        $this->postJson('/api/v1/crypto/rotation/history-id', $body)->assertConflict()->assertJsonPath('error', 'crypto_state_conflict');
    }
}
