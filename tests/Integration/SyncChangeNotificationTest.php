<?php

namespace Tests\Integration;

use App\Models\User;
use App\Services\SyncStorageService;
use Database\Seeders\CollectionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PgSql\Connection;
use Tests\TestCase;

class SyncChangeNotificationTest extends TestCase
{
    private ?Connection $listener = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql' || ! function_exists('pg_connect')) {
            $this->markTestSkipped('Requires PostgreSQL and ext-pgsql.');
        }
        if (! app()->environment('testing') || ! preg_match('/^midori_sync_test(_[a-z0-9]+)*$/D', DB::connection()->getDatabaseName())) {
            $this->fail('Notification tests require an isolated midori_sync_test_* database.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->seed(CollectionSeeder::class);

        $config = DB::connection()->getConfig();
        $fields = [
            'host' => $config['host'],
            'port' => $config['port'],
            'dbname' => $config['database'],
            'user' => $config['username'],
            'password' => $config['password'],
        ];
        $connectionString = implode(' ', array_map(
            static fn (string $key, mixed $value): string => $key."='".str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value)."'",
            array_keys($fields), array_values($fields),
        ));
        $listener = @pg_connect($connectionString, PGSQL_CONNECT_FORCE_NEW);
        $this->assertNotFalse($listener);
        $this->listener = $listener;
        $this->assertNotFalse(pg_query($this->listener, 'LISTEN midori_sync_change'));
    }

    protected function tearDown(): void
    {
        if ($this->listener) {
            pg_close($this->listener);
        }
        parent::tearDown();
    }

    public function test_only_committed_sync_and_link_changes_emit_account_scoped_hints(): void
    {
        $user = User::factory()->create(['storage_quota_bytes' => 104857600]);
        $storage = app(SyncStorageService::class);

        DB::transaction(function () use ($storage, $user) {
            $storage->upsertRecord($user->id, 'bookmarks', 'first', 'opaque-ciphertext');
            $storage->upsertRecord($user->id, 'bookmarks', 'second', 'opaque-ciphertext');
            $this->assertFalse($this->readNotification());
        });
        $this->assertSame((string) $user->id, $this->notification()['payload']);
        $this->assertFalse($this->readNotification());

        DB::table('library_link_changes')->insert([
            'user_id' => $user->id,
            'sequence' => 1,
            'link_id' => (string) Str::uuid(),
            'revision' => 1,
            'deleted' => false,
            'value' => json_encode(['title' => 'opaque-test'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
        $this->assertSame((string) $user->id, $this->notification()['payload']);

        DB::table('sync_streams')->where('user_id', $user->id)->update(['generation' => (string) Str::uuid()]);
        $this->assertSame((string) $user->id, $this->notification()['payload']);

        $otherUser = User::factory()->create(['storage_quota_bytes' => 104857600]);
        $storage->upsertRecord($otherUser->id, 'bookmarks', 'other', 'other-ciphertext');
        $this->assertSame((string) $otherUser->id, $this->notification()['payload']);

        try {
            DB::transaction(function () use ($user) {
                DB::table('sync_streams')->where('user_id', $user->id)->update(['generation' => (string) Str::uuid()]);
                throw new \RuntimeException('roll back');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('roll back', $error->getMessage());
        }
        $this->assertFalse($this->readNotification(100));
    }

    private function notification(): array
    {
        $notification = $this->readNotification(1000);
        $this->assertIsArray($notification);
        $this->assertSame('midori_sync_change', $notification['message']);
        $this->assertArrayHasKey('payload', $notification);

        return $notification;
    }

    private function readNotification(int $timeoutMs = 0): array|false
    {
        pg_consume_input($this->listener);
        $notification = pg_get_notify($this->listener, PGSQL_ASSOC);
        if ($notification !== false || $timeoutMs === 0) {
            return $notification;
        }

        $socket = pg_socket($this->listener);
        $this->assertNotFalse($socket);
        $read = [$socket];
        $write = null;
        $except = null;
        if (stream_select($read, $write, $except, intdiv($timeoutMs, 1000), ($timeoutMs % 1000) * 1000) !== 1) {
            return false;
        }
        pg_consume_input($this->listener);

        return pg_get_notify($this->listener, PGSQL_ASSOC);
    }
}
