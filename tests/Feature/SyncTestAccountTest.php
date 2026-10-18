<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SyncIdentityService;
use Database\Seeders\CollectionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncTestAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_seed_is_limited_to_private_activated_synthetic_account_and_never_renews_codes(): void
    {
        $this->seed(CollectionSeeder::class);
        $subject = 'synthetic-h1:'.Str::uuid();
        $user = User::factory()->create(['authentik_id' => $subject, 'email' => 'h1.'.Str::uuid().'@local.invalid']);
        $this->withToken($this->createNativeSessionToken($user)['token']);
        $fixture = ['kind' => 'h1-live', 'database' => DB::connection()->getDatabaseName(), 'user_id' => (string) $user->id,
            'subject' => $subject, 'profiles' => ['sentinel' => 'unchanged']];
        $path = '/tmp/midori-sync-h1-seed-'.Str::uuid().'.json';
        file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR));
        chmod($path, 0600);
        Http::fake(['127.0.0.1:8000/api/v1/capabilities' => Http::response(['authentication' => ['issuer' => SyncIdentityService::DEVELOPMENT_ISSUER],
            'protocol' => 'MSP', 'crypto_custody_version' => 1])]);
        $options = ['--output' => $path, '--seed-legacy-deletion' => true];
        try {
            $this->assertSeedRejected($options);
            $public = json_decode(file_get_contents(base_path('tests/Fixtures/native-sodium.json')), true, flags: JSON_THROW_ON_ERROR);
            $identity = $public['bundle'];
            $identity['key_id'] = str_repeat('A', 22);
            $state = $this->getJson('/api/v1/crypto/state')->assertOk()->json();
            $this->postJson('/api/v1/crypto/activate', ['crypto' => ['epoch' => $state['epoch'], 'revision' => $state['revision']],
                'key_id' => $public['key_id'], 'encrypted_bundle' => json_encode($public['bundle'], JSON_THROW_ON_ERROR),
                'identity_key' => ['key_id' => $identity['key_id'], 'encrypted_bundle' => json_encode($identity, JSON_THROW_ON_ERROR)],
                'registry_auth' => str_repeat('b', 64)])->assertOk();
            chmod($path, 0644);
            clearstatcache(true, $path);
            $this->assertSeedRejected($options);
            chmod($path, 0600);
            clearstatcache(true, $path);
            $this->assertSame(0, Artisan::call('sync:test-account', $options));
            $saved = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($fixture['profiles'], $saved['profiles']);
            $this->assertSame('zzH1Legacy01_', $saved['legacyDeletion']['id']);
            $this->assertDatabaseCount('sync_pairing_codes', 0);
            $this->assertDatabaseHas('records', ['user_id' => $user->id, 'record_id' => 'zzH1Legacy01_', 'payload' => '', 'deleted' => true]);
            $this->assertDatabaseCount('sync_changes', 1);
            $this->assertSame(0, Artisan::call('sync:test-account', $options));
            $this->assertDatabaseCount('sync_changes', 1);
            $fixture['subject'] = 'real-account';
            file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR));
            $this->assertSeedRejected($options);
            $this->assertDatabaseCount('records', 1);
        } finally {
            unlink($path);
        }
    }

    private function assertSeedRejected(array $options): void
    {
        try {
            Artisan::call('sync:test-account', $options);
            $this->fail('Unsafe legacy seeding was accepted.');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }
}
