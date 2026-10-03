<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\User;
use App\Services\SyncIdentityService;
use App\Services\SyncPairingService;
use Database\Seeders\CollectionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class HistoryClearTest extends TestCase
{
    use RefreshDatabase;

    private const CHANGES = '/api/v1/sync/collections/history/changes';

    private const OPERATIONS = '/api/v1/sync/collections/history/operations';

    private const CLEAR = '/api/v1/sync/collections/history/clear';

    private User $user;

    private string $token;

    private string $keyId;

    private array $crypto;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sync.local_dev' => true]);
        $this->seed(CollectionSeeder::class);
        $this->user = User::factory()->create(['authentik_issuer' => SyncIdentityService::DEVELOPMENT_ISSUER]);
        $this->token = $this->pair('Profile A');
        $this->withToken($this->token);
        $state = $this->getJson('/api/v1/crypto/state')->assertOk()->json();
        $this->keyId = rtrim(strtr(base64_encode(str_repeat('k', 16)), '+/', '-_'), '=');
        $activated = $this->postJson('/api/v1/crypto/activate', [
            'crypto' => ['epoch' => $state['epoch'], 'revision' => $state['revision']],
            'key_id' => $this->keyId,
            'encrypted_bundle' => json_encode([
                'bundle_version' => 2, 'crypto_version' => 2, 'key_id' => $this->keyId,
                'kdf' => ['algorithm' => 'argon2id13', 'memory_kib' => 65536, 'iterations' => 3, 'parallelism' => 1],
                'salt' => base64_encode(str_repeat('s', 16)),
                'nonce' => base64_encode(str_repeat('n', 24)),
                'ciphertext' => base64_encode(str_repeat('c', 48)),
            ], JSON_THROW_ON_ERROR),
        ])->assertOk()->json();
        $this->crypto = ['epoch' => $activated['epoch'], 'revision' => $activated['revision']];
    }

    public function test_clear_rotates_only_history_and_rejects_offline_writes(): void
    {
        $generation = $this->page($this->token)['generation'];
        $this->submit($generation, 'h1:old')->assertOk()->assertJsonPath('results.0.status', 'applied');
        $bookmarkCollection = Collection::findByName('bookmarks');
        DB::table('records')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id, 'collection_id' => $bookmarkCollection->id,
            'record_id' => 'keep', 'payload' => 'bookmark ciphertext',
            'version' => 1, 'deleted' => false, 'modified_at' => microtime(true),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherToken = $this->pair('Profile B');
        $oldCursor = $this->page($otherToken)['next_cursor'];
        $operationId = (string) Str::uuid();
        $body = ['operation_id' => $operationId, 'generation' => $generation, 'crypto' => $this->crypto];

        $cleared = $this->withToken($this->token)->postJson(self::CLEAR, $body)->assertOk()->json();
        $this->assertNotSame($generation, $cleared['generation']);
        $this->assertSame(1, $cleared['deleted_records']);
        $this->assertSame($cleared, $this->postJson(self::CLEAR, $body)->assertOk()->json());
        $this->assertSame($cleared, $this->postJson(self::CLEAR, [
            'operation_id' => strtoupper($operationId), 'generation' => strtoupper($generation), 'crypto' => $this->crypto,
        ])->assertOk()->json());
        $this->assertDatabaseCount('sync_history_clears', 1);
        $this->assertDatabaseMissing('records', ['record_id' => 'h1:old']);
        $this->assertDatabaseHas('records', ['record_id' => 'keep', 'collection_id' => $bookmarkCollection->id]);

        $this->withToken($otherToken)->getJson(self::CHANGES.'?'.http_build_query(['cursor' => $oldCursor]))
            ->assertStatus(409)->assertJsonPath('error', 'reset_required');
        $page = $this->page($otherToken);
        $this->assertSame($cleared['generation'], $page['generation']);
        $this->assertSame([], $page['changes']);
        $this->assertSame('history_clear', $page['reset']['kind']);
        $this->assertSame($cleared['clear_before'], $page['reset']['clear_before']);
        $this->withToken($otherToken)->postJson(self::OPERATIONS, [
            'generation' => $generation, 'crypto' => $this->crypto,
            'operations' => [$this->operation($generation, 'h1:old')],
        ])->assertStatus(409)->assertJsonPath('error', 'reset_required');
        $this->assertDatabaseCount('sync_changes', 0);
        $this->assertDatabaseCount('sync_operations', 0);
        $this->assertDatabaseCount('sync_device_cursors', 0);
    }

    public function test_clear_requires_crypto_and_a_current_generation(): void
    {
        $generation = $this->page($this->token)['generation'];
        $body = ['operation_id' => (string) Str::uuid(), 'generation' => $generation, 'crypto' => $this->crypto];
        $this->postJson(self::CLEAR, ['operation_id' => $body['operation_id'], 'generation' => $generation])
            ->assertUnprocessable();
        $this->postJson(self::CLEAR, $body)->assertOk();
        $this->postJson(self::CLEAR, ['operation_id' => (string) Str::uuid(), 'generation' => $generation, 'crypto' => $this->crypto])
            ->assertStatus(409)->assertJsonPath('error', 'reset_required');
        $this->postJson(self::CLEAR, ['operation_id' => $body['operation_id'], 'generation' => (string) Str::uuid(), 'crypto' => $this->crypto])
            ->assertStatus(409)->assertJsonPath('error', 'idempotency_conflict');
    }

    private function pair(string $name): string
    {
        $code = app(SyncPairingService::class)->generate($this->user)['pairing_token'];

        return $this->postJson('/api/v1/pair/redeem', [
            'pairing_token' => $code, 'device_name' => $name,
        ])->assertCreated()->json('token');
    }

    private function page(string $token): array
    {
        return $this->withToken($token)->getJson(self::CHANGES)->assertOk()->json();
    }

    private function operation(string $generation, string $id): array
    {
        return ['operation_id' => (string) Str::uuid(), 'id' => $id, 'base_revision' => '0', 'payload' => json_encode([
            'crypto_version' => 2, 'key_id' => $this->keyId, 'purpose' => 'record',
            'context' => ['collection' => 'history', 'id' => $id, 'generation' => $generation, 'schema_version' => 1, 'base_revision' => '0'],
            'nonce' => base64_encode(str_repeat('n', 24)), 'ciphertext' => base64_encode(str_repeat('c', 20)),
        ], JSON_THROW_ON_ERROR)];
    }

    private function submit(string $generation, string $id)
    {
        return $this->withToken($this->token)->postJson(self::OPERATIONS, [
            'generation' => $generation, 'crypto' => $this->crypto,
            'operations' => [$this->operation($generation, $id)],
        ]);
    }
}
