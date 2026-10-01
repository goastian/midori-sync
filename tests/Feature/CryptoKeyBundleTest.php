<?php

namespace Tests\Feature;

use App\Models\CryptoKeyBundle;
use App\Models\User;
use App\Services\SyncAuthService;
use App\Services\SyncKeyBundleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CryptoKeyBundleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->withToken(app(SyncAuthService::class)->createSessionToken($this->user)['token']);
    }

    public function test_preconditions_preserve_bundle_after_creation_and_update_conflicts(): void
    {
        $this->postJson('/api/v1/crypto/keys', ['encrypted_bundle' => 'first', 'expected_version' => 0])
            ->assertCreated()->assertJsonPath('version', 1)->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/api/v1/crypto/keys', ['encrypted_bundle' => 'stale create', 'expected_version' => 0])
            ->assertStatus(409)->assertJsonPath('error', 'key_version_conflict');
        $this->postJson('/api/v1/crypto/keys', ['encrypted_bundle' => 'second', 'expected_version' => 1])
            ->assertCreated()->assertJsonPath('version', 2);
        $this->postJson('/api/v1/crypto/keys', ['encrypted_bundle' => 'stale update', 'expected_version' => 1])
            ->assertStatus(409)->assertJsonPath('error', 'key_version_conflict');
        $this->getJson('/api/v1/crypto/keys')->assertOk()
            ->assertJsonPath('encrypted_bundle', 'second')->assertJsonPath('version', 2)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('crypto_key_bundles', 1);
    }

    public function test_existing_clients_keep_incrementing_versions_without_preconditions(): void
    {
        foreach (['one', 'two', 'three'] as $index => $payload) {
            $this->postJson('/api/v1/crypto/keys', ['encrypted_bundle' => $payload])
                ->assertCreated()->assertJsonPath('version', $index + 1);
        }
        $this->assertDatabaseHas('crypto_key_bundles', ['user_id' => $this->user->id, 'version' => 3, 'encrypted_bundle' => 'three']);
    }

    public function test_versions_and_payloads_are_isolated_by_account(): void
    {
        $this->postJson('/api/v1/crypto/keys', ['encrypted_bundle' => 'owner', 'expected_version' => 0])->assertCreated();
        $other = User::factory()->create();
        $this->withToken(app(SyncAuthService::class)->createSessionToken($other)['token']);
        $this->getJson('/api/v1/crypto/keys')->assertNotFound();
        $this->postJson('/api/v1/crypto/keys', ['encrypted_bundle' => 'other', 'expected_version' => 1])
            ->assertStatus(409);
        $this->postJson('/api/v1/crypto/keys', ['encrypted_bundle' => 'other', 'expected_version' => 0])
            ->assertCreated()->assertJsonPath('version', 1);
        $this->assertDatabaseHas('crypto_key_bundles', ['user_id' => $this->user->id, 'encrypted_bundle' => 'owner', 'version' => 1]);
    }

    public function test_invalid_preconditions_cannot_create_bundles(): void
    {
        foreach ([-1, null, 'invalid', 1.5, SyncKeyBundleService::MAX_VERSION + 1] as $version) {
            $this->postJson('/api/v1/crypto/keys', ['encrypted_bundle' => 'value', 'expected_version' => $version])
                ->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        }
        $this->assertDatabaseCount('crypto_key_bundles', 0);
    }

    public function test_exhausted_version_is_not_overwritten(): void
    {
        CryptoKeyBundle::create(['user_id' => $this->user->id, 'encrypted_bundle' => 'last', 'version' => SyncKeyBundleService::MAX_VERSION]);
        $this->postJson('/api/v1/crypto/keys', ['encrypted_bundle' => 'overflow', 'expected_version' => SyncKeyBundleService::MAX_VERSION])
            ->assertStatus(409)->assertJsonPath('error', 'key_version_exhausted');
        $this->assertDatabaseHas('crypto_key_bundles', ['user_id' => $this->user->id, 'encrypted_bundle' => 'last', 'version' => SyncKeyBundleService::MAX_VERSION]);
    }
}
