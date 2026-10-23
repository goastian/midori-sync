<?php

namespace Tests\Feature;

use App\Exceptions\SyncProtocolException;
use App\Models\Record;
use App\Models\User;
use App\Services\SyncNativeEnvelope;
use App\Services\SyncQuota;
use App\Services\SyncStorageService;
use Database\Seeders\CollectionSeeder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    private function passwordCapsule(array $state): array
    {
        $key = $this->key(99);

        return ['version' => 1, 'key' => $key, 'payload' => json_encode([
            'crypto_version' => 2, 'key_id' => $key['key_id'], 'purpose' => 'local',
            'context' => ['collection' => 'browser-settings', 'id' => 'sync-password-backup-v1',
                'generation' => $state['epoch'], 'schema_version' => 1, 'base_revision' => '0'],
            'nonce' => base64_encode(str_repeat('n', 24)), 'ciphertext' => base64_encode(str_repeat('c', 128)),
        ], JSON_THROW_ON_ERROR)];
    }

    public function test_password_backup_is_account_scoped_ciphertext_and_does_not_rewrite_the_registry(): void
    {
        $state = $this->activate();
        $this->assertFalse($state['password_unlock']);
        $capsule = $this->passwordCapsule($state);
        $body = ['crypto' => $this->context($state), 'capsule' => $capsule];
        $this->putJson('/api/v1/crypto/password', $body)->assertNoContent();
        $this->assertSame(array_replace($state, ['password_unlock' => true]), $this->state());
        $this->getJson('/api/v1/crypto/password')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['epoch' => $state['epoch'], 'capsule' => $capsule]);
        $second = $this->createNativeSessionToken($this->user);
        $this->withToken($second['token'])->getJson('/api/v1/crypto/password')->assertOk()
            ->assertJsonPath('capsule.key.key_id', $capsule['key']['key_id']);
        $this->withToken($this->createNativeSessionToken(User::factory()->create())['token'])
            ->getJson('/api/v1/crypto/password')->assertStatus(409)->assertJsonPath('error', 'password_backup_unavailable');
        $this->withToken($second['token']);
        $this->putJson('/api/v1/crypto/password', $body + ['password' => 'must never be sent'])->assertUnprocessable();
        $this->putJson('/api/v1/crypto/password', array_replace($body, ['crypto' => ['epoch' => $state['epoch'], 'revision' => '0']]))
            ->assertStatus(409)->assertJsonPath('error', 'crypto_state_conflict');
        $this->assertDatabaseCount('sync_password_backups', 1);
    }

    public function test_password_capsules_reject_wrong_purpose_epoch_and_oversized_ciphertext(): void
    {
        $state = $this->activate();
        foreach (['purpose', 'epoch', 'oversized'] as $fault) {
            $capsule = $this->passwordCapsule($state);
            $payload = json_decode($capsule['payload'], true, 16, JSON_THROW_ON_ERROR);
            if ($fault === 'purpose') {
                $payload['purpose'] = 'record';
            } elseif ($fault === 'epoch') {
                $payload['context']['generation'] = (string) Str::uuid();
            } else {
                $payload['ciphertext'] = base64_encode(str_repeat('c', 32785));
            }
            $capsule['payload'] = json_encode($payload, JSON_THROW_ON_ERROR);
            $this->putJson('/api/v1/crypto/password', ['crypto' => $this->context($state), 'capsule' => $capsule])
                ->assertUnprocessable()->assertJsonPath('error', 'invalid_password_capsule');
        }
        $this->assertDatabaseCount('sync_password_backups', 0);
    }

    public function test_key_rotation_invalidates_the_password_copy_and_reset_erases_it(): void
    {
        $state = $this->activate();
        $this->putJson('/api/v1/crypto/password', ['crypto' => $this->context($state), 'capsule' => $this->passwordCapsule($state)])->assertNoContent();
        $state = $this->postJson('/api/v1/crypto/rotation', $this->rotation($state))->assertOk()->json();
        $this->assertFalse($state['password_unlock']);
        $this->getJson('/api/v1/crypto/password')->assertStatus(409)->assertJsonPath('error', 'password_backup_unavailable');
        $this->putJson('/api/v1/crypto/password', ['crypto' => $this->context($state), 'capsule' => $this->passwordCapsule($state)])
            ->assertStatus(409)->assertJsonPath('error', 'password_backup_unavailable');
        $this->postJson('/api/v1/crypto/reset', ['crypto' => $this->context($state), 'confirm' => true])->assertOk();
        $this->assertDatabaseCount('sync_password_backups', 0);
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

    public function test_revision_authenticated_registry_advances_atomically_and_cannot_downgrade(): void
    {
        $this->getJson('/api/v1/capabilities')->assertOk()->assertJsonPath('client_recovery.registry_auth_version', 2);
        $this->assertSame(1, $this->state()['registry_auth_version']);
        $state = $this->postJson('/api/v1/crypto/activate', $this->activation() + ['registry_auth_version' => 2])
            ->assertOk()->assertJsonPath('revision', '1')->assertJsonPath('registry_auth_version', 2)->json();
        $rotation = $this->rotation($state);
        $this->postJson('/api/v1/crypto/rotation', $rotation)->assertStatus(409)->assertJsonPath('error', 'registry_auth_downgrade');
        $this->assertSame($state, $this->state());
        $rotation['registry_auth_version'] = 2;
        $state = $this->postJson('/api/v1/crypto/rotation', $rotation)
            ->assertOk()->assertJsonPath('revision', '2')->assertJsonPath('registry_auth_version', 2)->json();
        $this->postJson('/api/v1/crypto/rotation', $rotation)->assertOk()->assertExactJson($state);
        $this->postJson('/api/v1/crypto/rotation', array_replace($rotation, ['registry_auth_version' => 1]))
            ->assertStatus(409)->assertJsonPath('error', 'idempotency_conflict');
        $complete = ['crypto' => $this->context($state), 'operation_id' => $rotation['operation_id'], 'registry_auth' => str_repeat('f', 64)];
        $this->postJson('/api/v1/crypto/rotation/complete', $complete)->assertStatus(409)->assertJsonPath('error', 'registry_auth_downgrade');
        $this->assertSame($state, $this->state());
        $complete['registry_auth_version'] = 2;
        $state = $this->postJson('/api/v1/crypto/rotation/complete', $complete)
            ->assertOk()->assertJsonPath('revision', '3')->assertJsonPath('registry_auth_version', 2)->json();
        $this->postJson('/api/v1/crypto/rotation/complete', $complete)->assertOk()->assertExactJson($state);
        $retire = ['crypto' => $this->context($state), 'registry_auth' => str_repeat('a', 64)];
        $url = '/api/v1/crypto/native-keys/'.$this->key(1)['key_id'];
        $this->deleteJson($url, $retire)->assertStatus(409)->assertJsonPath('error', 'registry_auth_downgrade');
        $this->assertSame($state, $this->state());
        $this->deleteJson($url, $retire + ['registry_auth_version' => 2])
            ->assertOk()->assertJsonPath('revision', '4')->assertJsonPath('registry_auth_version', 2)->assertJsonCount(1, 'keys');
    }

    public function test_existing_version_one_registry_can_upgrade_without_changing_its_identity(): void
    {
        $state = $this->activate();
        $this->assertSame(1, $state['registry_auth_version']);
        $rotation = $this->rotation($state) + ['registry_auth_version' => 2];
        $this->postJson('/api/v1/crypto/rotation', array_replace($rotation, ['registry_auth_version' => 3]))->assertUnprocessable();
        $this->assertSame($state, $this->state());
        $this->postJson('/api/v1/crypto/rotation', array_replace($rotation, ['crypto' => ['epoch' => $state['epoch'], 'revision' => '0']]))
            ->assertStatus(409)->assertJsonPath('error', 'crypto_state_conflict');
        $this->assertSame($state, $this->state());
        $this->postJson('/api/v1/crypto/rotation', $rotation)->assertOk()
            ->assertJsonPath('registry_auth_version', 2)->assertJsonPath('revision', '2')
            ->assertJsonPath('identity_key.key_id', $state['identity_key']['key_id']);
    }

    public function test_registry_version_migration_refuses_to_remove_a_live_version_two_pin(): void
    {
        $this->postJson('/api/v1/crypto/activate', $this->activation() + ['registry_auth_version' => 2])->assertOk();
        $migration = require database_path('migrations/2026_10_16_000001_add_registry_auth_version.php');
        try {
            $migration->down();
            $this->fail('A version-two registry must prevent schema downgrade.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Revision-authenticated key registries cannot be downgraded.', $error->getMessage());
        }
        $this->assertSame(2, $this->state()['registry_auth_version']);
    }

    public function test_autofill_workspaces_filters_and_permissions_use_independent_protected_streams(): void
    {
        $capabilities = $this->getJson('/api/v1/capabilities')->assertOk()->json();
        $state = $this->activate();
        $payloads = [];
        foreach (['form-history' => 'form_history', 'addresses' => 'addresses', 'workspaces' => 'workspace_metadata', 'midori-privacy' => 'custom_filters', 'site-permissions' => 'site_permissions', 'extensions' => 'extension_catalog', 'extension-settings' => 'extension_settings'] as $collection => $feature) {
            $this->assertContains($collection, $capabilities['collections']);
            $this->assertContains($feature, $capabilities['features']);
            $this->assertSame(1, $capabilities['data_schemas'][$collection]);
            $url = '/api/v1/sync/collections/'.$collection;
            $body = $this->operation($state, 'same-record-id', collection: $collection);
            $this->postJson($url.'/operations', $body)->assertOk()->assertJsonPath('results.0.status', 'applied');
            $this->postJson($url.'/operations', $body)->assertOk()->assertJsonPath('results.0.revision', '1');
            $wrong = $this->operation($state, 'wrong-collection');
            $wrong['generation'] = $body['generation'];
            $this->postJson($url.'/operations', $wrong)->assertOk()->assertJsonPath('results.0.status', 'invalid');
            $deleted = $this->operation($state, 'same-record-id', '1', deleted: true, collection: $collection);
            $this->postJson($url.'/operations', $deleted)->assertOk()->assertJsonPath('results.0.status', 'applied');
            $page = $this->getJson($url.'/changes')->assertOk()->assertJsonCount(2, 'changes')
                ->assertJsonPath('changes.1.record.deleted', true)
                ->assertJsonPath('changes.1.record.payload', $deleted['operations'][0]['payload'])->json();
            $this->postJson($url.'/ack', ['cursor' => $page['next_cursor']])->assertOk();
            $payloads[] = $deleted['operations'][0]['payload'];
        }
        $this->assertNotSame($payloads[0], $payloads[1]);
        $this->assertCount(7, array_unique($payloads));
        $this->assertDatabaseCount('records', 7);
        $other = User::factory()->create();
        $this->withToken($this->createNativeSessionToken($other)['token']);
        foreach (['form-history', 'addresses', 'workspaces', 'midori-privacy', 'site-permissions', 'extensions', 'extension-settings'] as $collection) {
            $this->getJson('/api/v1/sync/collections/'.$collection.'/changes')->assertOk()->assertJsonCount(0, 'changes');
        }
        $migration = require base_path('database/migrations/2026_10_15_000002_add_form_history_and_addresses.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_workspace_migration_can_only_remove_an_unused_collection(): void
    {
        $migration = require base_path('database/migrations/2026_10_19_000001_add_workspace_metadata_collection.php');
        $migration->down();
        $this->assertDatabaseMissing('collections', ['name' => 'workspaces']);
        $migration->up();
        $migration->up();
        $this->assertSame(1, DB::table('collections')->where('name', 'workspaces')->count());
        $this->getJson('/api/v1/sync/collections/workspaces/changes')->assertOk();
        try {
            $migration->down();
            $this->fail('A workspace stream must survive a migration rollback.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Workspace metadata collection cannot be removed after use.', $error->getMessage());
        }
        $this->assertDatabaseHas('collections', ['name' => 'workspaces']);
    }

    public function test_site_permissions_migration_preserves_existing_streams(): void
    {
        $migration = require database_path('migrations/2026_10_20_000001_add_site_permissions_collection.php');
        $migration->down();
        $this->assertDatabaseMissing('collections', ['name' => 'site-permissions']);
        $migration->up();
        $migration->up();
        $this->assertSame(1, DB::table('collections')->where('name', 'site-permissions')->count());
        $this->getJson('/api/v1/sync/collections/site-permissions/changes')->assertOk();
        try {
            $migration->down();
            $this->fail('A permission stream must survive a migration rollback.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Site permissions collection cannot be removed after use.', $error->getMessage());
        }
        $this->assertDatabaseHas('collections', ['name' => 'site-permissions']);
        $this->assertDatabaseHas('collections', ['name' => 'midori-privacy']);
    }

    public function test_extensions_migration_preserves_existing_streams(): void
    {
        $migration = require database_path('migrations/2026_10_21_000001_add_extensions_collection.php');
        $migration->down();
        $this->assertDatabaseMissing('collections', ['name' => 'extensions']);
        $migration->up();
        $migration->up();
        $this->assertSame(1, DB::table('collections')->where('name', 'extensions')->count());
        $this->getJson('/api/v1/sync/collections/extensions/changes')->assertOk();
        try {
            $migration->down();
            $this->fail('An extension stream must survive a migration rollback.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Extension catalog collection cannot be removed after use.', $error->getMessage());
        }
        $this->assertDatabaseHas('collections', ['name' => 'extensions']);
        $this->assertDatabaseHas('collections', ['name' => 'midori-privacy']);
    }

    public function test_extension_settings_migration_preserves_existing_streams(): void
    {
        $migration = require database_path('migrations/2026_10_21_000002_add_extension_settings_collection.php');
        $migration->down();
        $this->assertDatabaseMissing('collections', ['name' => 'extension-settings']);
        $migration->up();
        $migration->up();
        $this->assertSame(1, DB::table('collections')->where('name', 'extension-settings')->count());
        $this->getJson('/api/v1/sync/collections/extension-settings/changes')->assertOk();
        try {
            $migration->down();
            $this->fail('An extension settings stream must survive a migration rollback.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Extension settings collection cannot be removed after use.', $error->getMessage());
        }
        $this->assertDatabaseHas('collections', ['name' => 'extension-settings']);
        $this->assertDatabaseHas('collections', ['name' => 'extensions']);
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

    public function test_desktop_and_mobile_share_each_encrypted_collection_bidirectionally(): void
    {
        $state = $this->activate();
        $desktop = $this->defaultHeaders['Authorization'];
        $mobileDevice = $this->user->devices()->create([
            'device_id' => (string) Str::uuid(), 'name' => 'Midori Mobile', 'type' => 'mobile',
        ]);
        $mobile = 'Bearer '.$this->createNativeSessionToken($this->user, $mobileDevice)['token'];
        foreach (['bookmarks', 'history', 'passwords', 'credit-cards', 'form-history', 'addresses', 'workspaces', 'midori-privacy', 'site-permissions', 'extensions', 'extension-settings', 'tabs', 'browser-settings'] as $collection) {
            $url = '/api/v1/sync/collections/'.$collection;
            $this->withHeader('Authorization', $desktop);
            $fromDesktop = $this->operation($state, 'desktop-record', collection: $collection);
            $this->postJson($url.'/operations', $fromDesktop)->assertOk()->assertJsonPath('results.0.status', 'applied');
            $this->withHeader('Authorization', $mobile);
            $this->getJson($url.'/changes')->assertOk()
                ->assertJsonPath('generation', $fromDesktop['generation'])
                ->assertJsonPath('changes.0.record.payload', $fromDesktop['operations'][0]['payload']);
            $fromMobile = $this->operation($state, 'mobile-record', collection: $collection);
            $this->postJson($url.'/operations', $fromMobile)->assertOk()->assertJsonPath('results.0.status', 'applied');
            $this->withHeader('Authorization', $desktop);
            $page = $this->getJson($url.'/changes')->assertOk()->assertJsonCount(2, 'changes')
                ->assertJsonPath('changes.1.record.payload', $fromMobile['operations'][0]['payload'])->json();
            $desktopCursor = $page['next_cursor'];
            $this->withHeader('Authorization', $mobile);
            $deleted = $this->operation($state, 'desktop-record', '1', deleted: true, collection: $collection);
            $this->postJson($url.'/operations', $deleted)->assertOk()->assertJsonPath('results.0.status', 'applied');
            $this->withHeader('Authorization', $desktop);
            $this->getJson($url.'/changes?'.http_build_query(['cursor' => $desktopCursor]))->assertOk()
                ->assertJsonCount(1, 'changes')->assertJsonPath('changes.0.record.deleted', true)
                ->assertJsonPath('changes.0.record.payload', $deleted['operations'][0]['payload']);
        }
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

    private function control(array $state, string $collection, string $kind, ?string $operationId = null): array
    {
        $page = $this->getJson('/api/v1/sync/collections/'.$collection.'/changes')->assertOk()->json();

        return ['version' => 1, 'epoch' => $state['epoch'], 'collection' => $collection,
            'operation_id' => $operationId ?? (string) Str::uuid(), 'previous_generation' => $page['generation'],
            'generation' => (string) Str::uuid(), 'control_revision' => (string) ((int) $page['control_revision'] + 1),
            'kind' => $kind, 'clear_before_ms' => $kind === 'history_clear' ? now()->getTimestampMs() : 0,
            'proof' => str_repeat('a', 64)];
    }

    public function test_history_control_is_persisted_replayed_and_returned_with_generation_conflicts(): void
    {
        $state = $this->activate();
        $this->postJson('/api/v1/sync/collections/history/operations', $this->operation($state, 'visit', collection: 'history'))->assertOk();
        $oldPage = $this->getJson('/api/v1/sync/collections/history/changes')->assertOk()->json();
        $control = $this->control($state, 'history', 'history_clear');
        $body = ['crypto' => $this->context($state), 'operation_id' => $control['operation_id'],
            'generation' => $control['previous_generation'], 'control' => $control];
        $bad = $body;
        $bad['control']['control_revision'] = '2';
        $this->postJson('/api/v1/sync/collections/history/clear', $bad)->assertConflict()->assertJsonPath('error', 'control_state_conflict');
        $bad['control']['proof'] = null;
        $this->postJson('/api/v1/sync/collections/history/clear', $bad)->assertUnprocessable();
        foreach ([0, 1.5, 9007199254741] as $cutoff) {
            $bad = $body;
            $bad['control']['clear_before_ms'] = $cutoff;
            $this->postJson('/api/v1/sync/collections/history/clear', $bad)->assertUnprocessable()->assertJsonPath('error', 'invalid_control');
        }
        $this->assertDatabaseCount('records', 1);
        $response = $this->postJson('/api/v1/sync/collections/history/clear', $body)->assertOk()
            ->assertJsonPath('deleted_records', 1)->assertJsonPath('control', $control)->json();
        $this->postJson('/api/v1/sync/collections/history/clear', $body)->assertOk()->assertExactJson($response);
        $this->getJson('/api/v1/sync/collections/history/changes?cursor='.urlencode($oldPage['next_cursor']))
            ->assertConflict()->assertJsonPath('error', 'reset_required')->assertJsonPath('control', $control)
            ->assertJsonPath('generation', $control['generation'])->assertJsonPath('control_revision', '1');
        $this->getJson('/api/v1/sync/collections/history/changes')->assertOk()->assertJsonCount(0, 'changes')
            ->assertJsonPath('control', $control)->assertJsonPath('reset.kind', 'history_clear')
            ->assertJsonPath('reset.clear_before', $response['clear_before']);
        $this->assertSame($state, $this->state());
        $body['control']['proof'] = str_repeat('b', 64);
        $this->postJson('/api/v1/sync/collections/history/clear', $body)->assertConflict()->assertJsonPath('error', 'idempotency_conflict');
    }

    public function test_consecutive_authenticated_history_clears_preserve_the_exact_signed_cutoff_in_changes(): void
    {
        $state = $this->activate();
        $url = '/api/v1/sync/collections/history';
        $previous = null;
        foreach ([1792022400123, 1792022400456] as $index => $cutoff) {
            $control = $this->control($state, 'history', 'history_clear');
            $control['clear_before_ms'] = $cutoff;
            $body = ['crypto' => $this->context($state), 'operation_id' => $control['operation_id'],
                'generation' => $control['previous_generation'], 'control' => $control];
            $response = $this->postJson($url.'/clear', $body)->assertOk()->json();
            $page = $this->getJson($url.'/changes')->assertOk()->assertJsonPath('control', $control)
                ->assertJsonPath('control_revision', (string) ($index + 1))
                ->assertJsonPath('generation', $control['generation'])
                ->assertJsonPath('reset.kind', 'history_clear')->assertJsonPath('reset.clear_before', $response['clear_before'])->json();
            $this->assertSame($cutoff, Carbon::parse($page['reset']['clear_before'])->getTimestampMs());
            if ($previous !== null) {
                $this->assertSame($previous['generation'], $control['previous_generation']);
                $this->getJson($url.'/changes?cursor='.urlencode($previous['next_cursor']))->assertConflict()
                    ->assertJsonPath('control', $control)->assertJsonPath('control_revision', '2');
            }
            $previous = $page;
            $this->postJson($url.'/clear', $body)->assertOk()->assertExactJson($response);
        }
    }

    public function test_wipe_requires_all_controls_commits_atomically_and_preserves_crypto_identity(): void
    {
        $state = $this->activate();
        $this->postJson('/api/v1/sync/collections/bookmarks/operations', $this->operation($state, 'bookmark'))->assertOk();
        $operationId = (string) Str::uuid();
        $controls = array_map(fn ($collection) => $this->control($state, $collection, 'collection_clear', $operationId),
            SyncNativeEnvelope::COLLECTIONS);
        $body = ['crypto' => $this->context($state), 'operation_id' => $operationId, 'controls' => $controls];
        $missing = $body;
        array_pop($missing['controls']);
        $this->deleteJson('/api/v1/sync/data', $missing)->assertUnprocessable()->assertJsonPath('error', 'incomplete_controls');
        $bad = $body;
        $bad['controls'][count($controls) - 1]['control_revision'] = '2';
        $this->deleteJson('/api/v1/sync/data', $bad)->assertConflict()->assertJsonPath('error', 'control_state_conflict');
        $this->assertDatabaseCount('records', 1);
        $this->assertSame(0, DB::table('sync_streams')->sum('control_revision'));
        $response = $this->deleteJson('/api/v1/sync/data', $body)->assertOk()->assertJsonPath('controls', $controls)
            ->assertJsonPath('deleted_records', 1)->json();
        $this->deleteJson('/api/v1/sync/data', $body)->assertOk()->assertExactJson($response);
        $this->assertDatabaseCount('records', 0);
        $this->assertDatabaseCount('sync_controls', 1);
        $this->assertSame($state, $this->state());
        foreach ($controls as $control) {
            $this->getJson('/api/v1/sync/collections/'.$control['collection'].'/changes')->assertOk()
                ->assertJsonPath('generation', $control['generation'])->assertJsonPath('control', $control);
        }
    }

    public function test_compaction_rebases_offline_pages_preserves_tombstones_and_keeps_a_snapshot_fence(): void
    {
        $state = $this->activate();
        foreach ([['a', '0', false], ['a', '1', false], ['b', '0', true]] as [$id, $revision, $deleted]) {
            $this->postJson('/api/v1/sync/collections/bookmarks/operations', $this->operation($state, $id, $revision, $deleted))->assertOk();
        }
        $old = $this->getJson('/api/v1/sync/collections/bookmarks/changes?limit=1')->assertOk()->json();
        $body = ['crypto' => $this->context($state), 'operation_id' => (string) Str::uuid(),
            'generation' => $old['generation'], 'snapshot_sequence' => $old['snapshot_sequence']];
        $response = $this->postJson('/api/v1/sync/collections/bookmarks/compact', $body)->assertOk()
            ->assertJsonPath('compacted_through', '3')->assertJsonPath('snapshot_sequence', '5')->assertJsonPath('records', 2)->json();
        $this->postJson('/api/v1/sync/collections/bookmarks/compact', $body)->assertOk()->assertExactJson($response);
        $this->assertDatabaseCount('sync_changes', 2);
        $this->assertDatabaseCount('sync_operations', 3);
        $rebased = $this->getJson('/api/v1/sync/collections/bookmarks/changes?limit=1&cursor='.urlencode($old['next_cursor']))
            ->assertOk()->assertJsonPath('snapshot_rebased', true)->assertJsonPath('compacted_through', '3')
            ->assertJsonPath('generation', $old['generation'])->assertJsonPath('snapshot_sequence', '5')->json();
        $this->postJson('/api/v1/sync/collections/bookmarks/operations', $this->operation($state, 'a', '2'))->assertOk();
        $next = $this->getJson('/api/v1/sync/collections/bookmarks/changes?cursor='.urlencode($rebased['next_cursor']))
            ->assertOk()->assertJsonPath('snapshot_sequence', '5')->assertJsonPath('has_more', false)->json();
        $rows = [...$rebased['changes'], ...$next['changes']];
        $byId = array_column(array_column($rows, 'record'), null, 'id');
        $this->assertSame('2', $byId['a']['revision']);
        $this->assertTrue($byId['b']['deleted']);
        $this->assertNotSame('', $byId['b']['payload']);
        $this->getJson('/api/v1/sync/collections/bookmarks/changes?cursor='.urlencode($next['next_cursor']))
            ->assertOk()->assertJsonPath('changes.0.record.revision', '3');
        $body['operation_id'] = (string) Str::uuid();
        $this->postJson('/api/v1/sync/collections/bookmarks/compact', $body)->assertConflict()->assertJsonPath('error', 'snapshot_conflict');
    }

    public function test_compaction_allows_repeated_rotations_beyond_eight_without_losing_current_ciphertext(): void
    {
        $state = $this->activate();
        $this->postJson('/api/v1/sync/collections/bookmarks/operations', $this->operation($state, 'record'))->assertOk();
        for ($index = 2; $index <= 10; $index++) {
            $oldKey = $state['active_key_id'];
            $rotation = $this->rotation($state, $index);
            $state = $this->postJson('/api/v1/crypto/rotation', $rotation)->assertOk()->json();
            $body = $this->operation($state, 'record', (string) ($index - 1));
            $this->postJson('/api/v1/sync/collections/bookmarks/operations', $body)->assertOk()->assertJsonPath('results.0.status', 'applied');
            $state = $this->postJson('/api/v1/crypto/rotation/complete', ['crypto' => $this->context($state),
                'operation_id' => $rotation['operation_id'], 'registry_auth' => str_repeat('f', 64)])->assertOk()->json();
            $page = $this->getJson('/api/v1/sync/collections/bookmarks/changes')->assertOk()->json();
            $this->postJson('/api/v1/sync/collections/bookmarks/compact', ['crypto' => $this->context($state),
                'operation_id' => (string) Str::uuid(), 'generation' => $page['generation'], 'snapshot_sequence' => $page['snapshot_sequence']])->assertOk();
            $state = $this->deleteJson('/api/v1/crypto/native-keys/'.$oldKey, ['crypto' => $this->context($state),
                'registry_auth' => str_repeat('a', 64)])->assertOk()->assertJsonCount(1, 'keys')->json();
            $this->assertDatabaseHas('records', ['record_id' => 'record', 'payload' => $body['operations'][0]['payload'], 'version' => $index]);
            $this->assertDatabaseCount('sync_changes', 1);
        }
        $this->assertSame($this->key(90), $state['identity_key']);
    }

    public function test_history_collision_merges_with_both_cas_revisions_and_never_resurrects_a_tombstone(): void
    {
        [$state, $body] = $this->historyMigration();
        $targetUrl = '/api/v1/crypto/rotation/history-target';
        $targetQuery = ['id' => $body['target_id'], 'generation' => $body['generation']];
        $this->postJson($targetUrl, $targetQuery)->assertOk()->assertJsonPath('record', null);
        $target = $this->operation($state, $body['target_id'], deleted: true, collection: 'history');
        $this->postJson('/api/v1/sync/collections/history/operations', $target)->assertOk();
        $this->postJson($targetUrl, $targetQuery)->assertOk()->assertJsonPath('record.revision', '1')->assertJsonPath('record.deleted', true);
        $body['target_revision'] = '9';
        $this->postJson('/api/v1/crypto/rotation/history-id', $body)->assertConflict()->assertJsonPath('error', 'history_target_conflict');
        $body['target_revision'] = '1';
        $this->postJson('/api/v1/crypto/rotation/history-id', $body)->assertConflict()->assertJsonPath('error', 'history_target_deleted');
        $this->assertDatabaseHas('records', ['record_id' => $body['source_id'], 'deleted' => false, 'version' => 1]);
        $body['target_deleted'] = true;
        $body['target_payload'] = $this->operation($state, $body['target_id'], '1', deleted: true, collection: 'history')['operations'][0]['payload'];
        $response = $this->postJson('/api/v1/crypto/rotation/history-id', $body)->assertOk()
            ->assertJsonPath('source_revision', '2')->assertJsonPath('target_revision', '2')->assertJsonPath('target_deleted', true)->json();
        $this->postJson('/api/v1/crypto/rotation/history-id', $body)->assertOk()->assertExactJson($response);
        $this->assertDatabaseHas('records', ['record_id' => $body['target_id'], 'deleted' => true, 'version' => 2]);
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
        $deviceId = DB::table('sync_sessions')->where('user_id', $this->user->id)->value('device_id');
        $legacyFingerprint = hash('sha256', json_encode([$deviceId, $body['rotation_id'], $body['generation'],
            $body['source_id'], $body['source_revision'], $body['target_id'], $body['source_payload'], $body['target_payload'], null], JSON_THROW_ON_ERROR));
        $this->assertDatabaseHas('sync_history_id_migrations', ['operation_id' => $body['operation_id'], 'fingerprint' => $legacyFingerprint]);
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

    public function test_a_session_can_reset_unrecoverable_client_keys_and_start_fresh(): void
    {
        $state = $this->activate();
        $this->postJson('/api/v1/crypto/reset', ['crypto' => ['epoch' => $state['epoch'], 'revision' => '0'], 'confirm' => true])
            ->assertStatus(409)->assertJsonPath('error', 'crypto_state_conflict');
        $this->postJson('/api/v1/crypto/reset', ['crypto' => $this->context($state)])->assertUnprocessable();
        $this->postJson('/api/v1/crypto/enrollments', ['public_key' => base64_encode("\x04".random_bytes(64))])->assertCreated();
        $fresh = $this->postJson('/api/v1/crypto/reset', ['crypto' => $this->context($state), 'confirm' => true])->assertOk()->json();
        $this->assertDatabaseCount('sync_device_enrollments', 0);
        $this->assertNotSame($state['epoch'], $fresh['epoch']);
        $this->assertSame([], $fresh['keys']);
        $this->assertNull($fresh['active_key_id']);
        $this->postJson('/api/v1/crypto/activate', $this->activation())->assertOk();
    }

    public function test_owner_reset_checks_revision_and_session_inside_the_storage_transaction(): void
    {
        $state = $this->activate();
        $session = DB::table('sync_sessions')->where('user_id', $this->user->id)->first();
        $context = $this->context($state) + ['session_id' => $session->id];
        DB::table('sync_crypto_states')->where('user_id', $this->user->id)->increment('revision');
        try {
            app(SyncStorageService::class)->deleteAllUserData($this->user->id, $context, ownerRequest: true);
            $this->fail('A stale reset must not erase newer keys');
        } catch (SyncProtocolException $error) {
            $this->assertSame('crypto_state_conflict', $error->error);
        }
        $this->assertDatabaseCount('sync_native_keys', 1);
        $context['revision'] = (string) ((int) $context['revision'] + 1);
        DB::table('sync_sessions')->where('id', $session->id)->delete();
        try {
            app(SyncStorageService::class)->deleteAllUserData($this->user->id, $context, ownerRequest: true);
            $this->fail('A revoked session must not erase encrypted data');
        } catch (SyncProtocolException $error) {
            $this->assertSame('auth_required', $error->error);
        }
        $this->assertDatabaseCount('sync_native_keys', 1);
    }
}
