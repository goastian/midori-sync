<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use App\Services\SyncStorageService;
use Database\Seeders\CollectionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncOperationsTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATIONS = '/api/v1/sync/collections/bookmarks/operations';

    private const CHANGES = '/api/v1/sync/collections/bookmarks/changes';

    private User $user;

    private string $token;

    private string $otherToken;

    private string $generation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CollectionSeeder::class);
        $this->user = User::factory()->create(['storage_quota_bytes' => 104857600]);
        foreach (['a', 'b'] as $id) {
            $device = Device::create(['user_id' => $this->user->id, 'device_id' => $id, 'name' => $id, 'type' => 'desktop']);
            $token = $this->createNativeSessionToken($this->user, $device)['token'];
            if ($id === 'a') {
                $this->token = $token;
            } else {
                $this->otherToken = $token;
            }
        }
        $this->generation = $this->withToken($this->token)->getJson(self::CHANGES)->assertOk()->json('generation');
    }

    public function test_retry_after_lost_response_does_not_bump_revision_or_duplicate_changes(): void
    {
        $operation = $this->operation('record', '0', 'first');
        $first = $this->submit([$operation]);
        $this->assertSame('applied', $first[0]['status']);
        $this->assertSame('1', $first[0]['revision']);
        $this->assertSame($first, $this->submit([$operation]));
        $this->assertDatabaseCount('sync_operations', 1);
        $this->assertDatabaseCount('sync_changes', 1);
        $this->assertDatabaseHas('records', ['record_id' => 'record', 'version' => 1]);
    }

    public function test_operation_id_cannot_be_reused_with_different_content_or_device(): void
    {
        $operation = $this->operation('record', '0', 'first');
        $this->submit([$operation]);
        $changed = $operation;
        $changed['payload'] = 'second';
        $this->assertSame('idempotency_conflict', $this->submit([$changed])[0]['status']);
        $this->assertSame('idempotency_conflict', $this->submit([$operation], $this->otherToken)[0]['status']);
        $this->assertDatabaseHas('records', ['record_id' => 'record', 'payload' => 'first', 'version' => 1]);
    }

    public function test_stale_device_cannot_overwrite_update_or_resurrect_delete(): void
    {
        $this->submit([$this->operation('record', '0', 'first')]);
        $this->submit([$this->operation('record', '1', 'second')]);
        $conflict = $this->submit([$this->operation('record', '1', 'stale')], $this->otherToken);
        $this->assertSame('conflict', $conflict[0]['status']);
        $this->assertSame('2', $conflict[0]['revision']);

        $delete = $this->operation('record', '2', '');
        unset($delete['payload']);
        $delete['deleted'] = true;
        $this->assertSame('applied', $this->submit([$delete])[0]['status']);
        $afterDelete = $this->submit([$this->operation('record', '2', 'stale')], $this->otherToken);
        $this->assertSame('conflict', $afterDelete[0]['status']);
        $this->assertSame('3', $afterDelete[0]['revision']);
        $this->assertDatabaseHas('records', ['record_id' => 'record', 'deleted' => true, 'payload' => '', 'version' => 3]);
    }

    public function test_each_item_has_an_outcome_and_retry_preserves_partial_success(): void
    {
        $valid = $this->operation('valid', '0', 'first');
        $stale = $this->operation('missing', '5', 'stale');
        $invalid = $this->operation('invalid', '0', 'bad');
        $invalid['base_revision'] = 0;
        $operations = [$valid, $stale, $invalid, 'not-an-object'];
        $first = $this->submit($operations);
        $this->assertSame(['applied', 'conflict', 'invalid', 'invalid'], array_column($first, 'status'));
        $this->assertSame($first, $this->submit($operations));
        $this->assertDatabaseCount('records', 1);
        $this->assertDatabaseCount('sync_changes', 1);
    }

    public function test_quota_failure_can_be_retried_with_same_id_after_freeing_space(): void
    {
        $this->user->update(['storage_quota_bytes' => 5]);
        $this->submit([$this->operation('existing', '0', '12345')]);
        $new = $this->operation('new', '0', 'new');
        $this->assertSame('quota_exceeded', $this->submit([$new])[0]['status']);
        $delete = $this->operation('existing', '1', '');
        $delete['deleted'] = true;
        $this->assertSame('applied', $this->submit([$delete])[0]['status']);
        $this->assertSame('applied', $this->submit([$new])[0]['status']);
        $this->assertDatabaseCount('sync_changes', 3);
    }

    public function test_multiple_operations_on_the_same_record_preserve_intermediate_feed_values(): void
    {
        $first = $this->operation('record', '0', 'first');
        $results = $this->submit([$first, $first, $this->operation('record', '1', 'second')]);
        $this->assertSame(['1', '1', '2'], array_column($results, 'revision'));
        $page = $this->withToken($this->token)->getJson(self::CHANGES)->assertOk()->json();
        $this->assertCount(2, $page['changes']);
        $this->assertSame('first', $page['changes'][0]['record']['payload']);
        $this->assertSame('second', $page['changes'][1]['record']['payload']);
    }

    public function test_old_generation_cannot_write_or_replay_after_wipe(): void
    {
        $operation = $this->operation('record', '0', 'first');
        $this->submit([$operation]);
        app(SyncStorageService::class)->deleteAllUserData($this->user->id);
        $this->withToken($this->token)->postJson(self::OPERATIONS, [
            'generation' => $this->generation, 'operations' => [$operation],
        ])->assertStatus(409)->assertJsonPath('error', 'reset_required');
        $this->assertDatabaseCount('sync_operations', 0);
        $this->assertDatabaseCount('records', 0);
    }

    public function test_request_and_record_size_limits_are_enforced_without_writes(): void
    {
        $this->assertSame('invalid', $this->submit([$this->operation('big', '0', str_repeat('a', 262145))])[0]['status']);
        $this->withToken($this->token)->postJson(self::OPERATIONS, [
            'generation' => $this->generation,
            'operations' => [$this->operation('too-big', '0', str_repeat('a', 4194304))],
        ])->assertStatus(413)->assertJsonPath('error', 'batch_too_large');
        $this->assertDatabaseCount('records', 0);
        $this->assertDatabaseCount('sync_operations', 0);
    }

    private function operation(string $id, string $revision, string $payload): array
    {
        return ['operation_id' => (string) Str::uuid(), 'id' => $id, 'base_revision' => $revision, 'payload' => $payload];
    }

    private function submit(array $operations, ?string $token = null): array
    {
        return $this->withToken($token ?? $this->token)->postJson(self::OPERATIONS, [
            'generation' => $this->generation, 'operations' => $operations,
        ])->assertOk()->json('results');
    }
}
