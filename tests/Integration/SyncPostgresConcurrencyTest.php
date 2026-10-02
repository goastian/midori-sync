<?php

namespace Tests\Integration;

use App\Exceptions\LibraryLimitException;
use App\Exceptions\LibraryProtocolException;
use App\Exceptions\SyncProtocolException;
use App\Models\Device;
use App\Models\Library\LibraryLink;
use App\Models\SyncSession;
use App\Models\User;
use App\Services\Library\LinkWriter;
use App\Services\SyncAuthService;
use App\Services\SyncIdentityService;
use App\Services\SyncNativeCryptoService;
use App\Services\SyncRefreshService;
use App\Services\SyncStorageService;
use Database\Seeders\CollectionSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SyncPostgresConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires PostgreSQL and pcntl.');
        }
        if (! app()->environment('testing') || ! preg_match('/^midori_sync_test(_[a-z0-9]+)*$/D', DB::connection()->getDatabaseName())) {
            $this->fail('Concurrency tests require an explicitly isolated midori_sync_test_* database.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->seed(CollectionSeeder::class);
    }

    public static function scenarios(): array
    {
        return [
            'commit order' => ['order', 'applied'],
            'competing revision' => ['cas', 'conflict'],
            'shared account quota' => ['quota', 'quota_exceeded'],
            'rollback frees revision and sequence' => ['rollback', 'applied'],
        ];
    }

    #[DataProvider('scenarios')]
    public function test_overlapping_writers_serialize_before_assigning_revision_quota_and_sequence(string $scenario, string $expected): void
    {
        $user = User::factory()->create(['storage_quota_bytes' => $scenario === 'quota' ? 5 : 104857600]);
        $a = Device::create(['user_id' => $user->id, 'device_id' => 'a', 'name' => 'A', 'type' => 'desktop']);
        $b = Device::create(['user_id' => $user->id, 'device_id' => 'b', 'name' => 'B', 'type' => 'desktop']);
        $storage = app(SyncStorageService::class);
        $secondCollection = $scenario === 'quota' ? 'history' : 'bookmarks';
        $generation = $storage->getChanges($user->id, 'bookmarks', $a->id, null, 100)['generation'];
        $secondGeneration = $storage->getChanges($user->id, $secondCollection, $b->id, null, 100)['generation'];
        $first = ['operation_id' => (string) Str::uuid(), 'id' => 'a', 'base_revision' => '0', 'payload' => '12345'];
        $second = [
            'operation_id' => (string) Str::uuid(),
            'id' => in_array($scenario, ['cas', 'rollback'], true) ? 'a' : 'b',
            'base_revision' => '0',
            'payload' => 'new',
        ];
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        DB::disconnect();
        $child = pcntl_fork();
        $this->assertNotSame(-1, $child);

        if ($child === 0) {
            fclose($sockets[0]);
            try {
                stream_set_timeout($sockets[1], 10);
                if (trim((string) fgets($sockets[1])) !== 'go') {
                    exit(2);
                }
                DB::statement("SET statement_timeout = '8s'");
                fwrite($sockets[1], json_encode(['pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid])."\n");
                $result = $storage->applyOperations($user->id, $secondCollection, $b->id, $secondGeneration, [$second]);
                fwrite($sockets[1], json_encode($result, JSON_THROW_ON_ERROR)."\n");
                DB::disconnect();
                fclose($sockets[1]);
                exit(0);
            } catch (\Throwable $error) {
                fwrite($sockets[1], json_encode(['error' => $error->getMessage()])."\n");
                exit(1);
            }
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);
        try {
            DB::beginTransaction();
            $firstResult = $storage->applyOperations($user->id, 'bookmarks', $a->id, $generation, [$first]);
            $this->assertSame('applied', $firstResult['results'][0]['status']);
            fwrite($sockets[0], "go\n");
            $ready = json_decode((string) fgets($sockets[0]), true, flags: JSON_THROW_ON_ERROR);
            $this->assertArrayHasKey('pid', $ready);
            $this->assertTrue($this->waitForDatabaseLock($ready['pid']), 'The competing writer must actually overlap and wait on the account lock.');
            if ($scenario === 'rollback') {
                DB::rollBack();
            } else {
                DB::commit();
            }
            $result = json_decode((string) fgets($sockets[0]), true, flags: JSON_THROW_ON_ERROR);
            pcntl_waitpid($child, $status);
            $child = null;
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status), json_encode($result));
            $this->assertSame($expected, $result['results'][0]['status']);

            $changes = $storage->getChanges($user->id, 'bookmarks', $a->id, null, 100)['changes'];
            $this->assertSame($scenario === 'order' ? ['1', '2'] : ['1'], array_column($changes, 'sequence'));
            $this->assertSame($scenario === 'rollback' ? 'new' : '12345', $changes[0]['record']['payload']);
            $this->assertSame('1', $changes[0]['record']['revision']);
            if ($scenario === 'quota') {
                $this->assertSame([], $storage->getChanges($user->id, 'history', $b->id, null, 100)['changes']);
                $this->assertSame(5, $storage->getSyncInfo($user->id)['used_bytes']);
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            fclose($sockets[0]);
            if ($child !== null) {
                posix_kill($child, SIGTERM);
                pcntl_waitpid($child, $status);
            }
        }
    }

    public static function libraryScenarios(): array
    {
        return [
            'canonical deduplication' => ['duplicate', 'existing'],
            'shared link quota' => ['quota', 'quota_exceeded'],
            'rollback releases capacity' => ['rollback', 'created'],
            'native receipt replay' => ['receipt_replay', 'created'],
            'native receipt conflict' => ['receipt_conflict', 'idempotency_conflict'],
            'native receipt rollback' => ['receipt_rollback', 'created'],
        ];
    }

    #[DataProvider('libraryScenarios')]
    public function test_library_writers_serialize_deduplication_and_quota(string $scenario, string $expected): void
    {
        Bus::fake();
        config(['library.billing_enabled' => false, 'library.selfhost_limits.max_links' => 1]);
        $user = User::factory()->create();
        $writer = app(LinkWriter::class);
        $first = ['url' => 'https://93.184.216.34/item?utm_source=first'];
        $second = ['url' => $scenario === 'quota' ? 'https://93.184.216.34/another' : 'https://93.184.216.34/item?utm_source=second'];
        $native = str_starts_with($scenario, 'receipt_');
        $rollback = in_array($scenario, ['rollback', 'receipt_rollback'], true);
        if ($native) {
            $first['operation_id'] = (string) Str::uuid();
            $second = $first;
            if ($scenario === 'receipt_conflict') {
                $second['title'] = 'Different request';
            }
        }
        $save = function (array $data) use ($writer, $user, $native): array {
            if ($native) {
                $receipt = $writer->saveOperation($user, $data);

                return ['status' => $receipt['created'] ? 'created' : 'existing', 'id' => $receipt['link_id']];
            }
            $link = $writer->save($user, $data);

            return ['status' => $link->wasRecentlyCreated ? 'created' : 'existing', 'id' => $link->id];
        };
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        DB::disconnect();
        $child = pcntl_fork();
        $this->assertNotSame(-1, $child);
        if ($child === 0) {
            fclose($sockets[0]);
            try {
                stream_set_timeout($sockets[1], 10);
                if (trim((string) fgets($sockets[1])) !== 'go') {
                    exit(2);
                }
                DB::statement("SET statement_timeout = '8s'");
                fwrite($sockets[1], json_encode(['pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid])."\n");
                try {
                    $result = $save($second);
                } catch (LibraryLimitException) {
                    $result = ['status' => 'quota_exceeded'];
                } catch (LibraryProtocolException $error) {
                    $result = ['status' => $error->error];
                }
                fwrite($sockets[1], json_encode($result, JSON_THROW_ON_ERROR)."\n");
                DB::disconnect();
                fclose($sockets[1]);
                exit(0);
            } catch (\Throwable $error) {
                fwrite($sockets[1], json_encode(['error' => $error->getMessage()])."\n");
                exit(1);
            }
        }
        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);
        try {
            DB::beginTransaction();
            $firstResult = $save($first);
            $this->assertSame('created', $firstResult['status']);
            fwrite($sockets[0], "go\n");
            $ready = json_decode((string) fgets($sockets[0]), true, flags: JSON_THROW_ON_ERROR);
            $this->assertArrayHasKey('pid', $ready);
            $this->assertTrue($this->waitForDatabaseLock($ready['pid']), 'The Link writer must wait for the competing account transaction.');
            if ($rollback) {
                DB::rollBack();
            } else {
                DB::commit();
            }
            $result = json_decode((string) fgets($sockets[0]), true, flags: JSON_THROW_ON_ERROR);
            pcntl_waitpid($child, $status);
            $child = null;
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status), json_encode($result));
            $this->assertSame($expected, $result['status']);
            $this->assertSame(1, LibraryLink::where('user_id', $user->id)->count());
            if (in_array($scenario, ['duplicate', 'receipt_replay'], true)) {
                $this->assertSame($firstResult['id'], $result['id']);
            } elseif ($rollback) {
                $this->assertNotSame($firstResult['id'], $result['id']);
                Bus::assertNothingDispatched();
            }
            if ($native) {
                $receipt = DB::table('library_save_receipts')->where('user_id', $user->id)->sole();
                $this->assertSame(LibraryLink::where('user_id', $user->id)->sole()->id, $receipt->link_id);
                $this->assertSame($first['operation_id'], $receipt->operation_id);
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            fclose($sockets[0]);
            if ($child !== null) {
                posix_kill($child, SIGTERM);
                pcntl_waitpid($child, $status);
            }
        }
    }

    public static function linkFeedScenarios(): array
    {
        return ['commit order' => [false], 'rollback order' => [true]];
    }

    #[DataProvider('linkFeedScenarios')]
    public function test_link_feed_sequences_follow_commit_order_across_connections(bool $rollback): void
    {
        $user = User::factory()->create();
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        DB::disconnect();
        $child = pcntl_fork();
        $this->assertNotSame(-1, $child);
        if ($child === 0) {
            fclose($sockets[0]);
            try {
                stream_set_timeout($sockets[1], 10);
                if (trim((string) fgets($sockets[1])) !== 'go') {
                    exit(2);
                }
                DB::statement("SET statement_timeout = '8s'");
                fwrite($sockets[1], json_encode(['pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid])."\n");
                $link = LibraryLink::create(['user_id' => $user->id, 'url' => 'https://example.org/second']);
                fwrite($sockets[1], json_encode(['id' => $link->id], JSON_THROW_ON_ERROR)."\n");
                DB::disconnect();
                fclose($sockets[1]);
                exit(0);
            } catch (\Throwable $error) {
                fwrite($sockets[1], json_encode(['error' => $error->getMessage()])."\n");
                exit(1);
            }
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);
        try {
            DB::beginTransaction();
            $first = LibraryLink::create(['user_id' => $user->id, 'url' => 'https://example.org/first']);
            fwrite($sockets[0], "go\n");
            $ready = json_decode((string) fgets($sockets[0]), true, flags: JSON_THROW_ON_ERROR);
            $this->assertArrayHasKey('pid', $ready);
            $this->assertTrue($this->waitForDatabaseLock($ready['pid']), 'A Link writer must wait for the account feed sequence.');
            if ($rollback) {
                DB::rollBack();
            } else {
                DB::commit();
            }
            $result = json_decode((string) fgets($sockets[0]), true, flags: JSON_THROW_ON_ERROR);
            pcntl_waitpid($child, $status);
            $child = null;
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status), json_encode($result));
            $events = DB::table('library_link_changes')->where('user_id', $user->id)->orderBy('sequence')->get();
            $this->assertSame($rollback ? [1] : [1, 2], $events->pluck('sequence')->map(fn ($value) => (int) $value)->all());
            $this->assertSame($rollback ? [$result['id']] : [$first->id, $result['id']], $events->pluck('link_id')->all());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            fclose($sockets[0]);
            if ($child !== null) {
                posix_kill($child, SIGTERM);
                pcntl_waitpid($child, $status);
            }
        }
    }

    public static function nativeCutoverScenarios(): array
    {
        return [
            'activation before legacy write' => ['legacy_write', 'client_upgrade_required'],
            'activation before legacy session issuance' => ['legacy_session', 'client_upgrade_required'],
            'competing native activation' => ['activation', 'crypto_state_conflict'],
            'legacy write before activation' => ['legacy_first', 'legacy_migration_required'],
        ];
    }

    #[DataProvider('nativeCutoverScenarios')]
    public function test_native_cutover_serializes_with_writes_and_session_issuance(string $scenario, string $expected): void
    {
        config(['services.sync.local_dev' => true]);
        $user = User::factory()->create(['authentik_issuer' => SyncIdentityService::DEVELOPMENT_ISSUER]);
        $device = Device::create(['user_id' => $user->id, 'device_id' => (string) Str::uuid(), 'name' => 'Native', 'type' => 'desktop']);
        $token = app(SyncAuthService::class)->createSessionToken($user, $device->id, protocolVersion: 2)['token'];
        $sessionId = SyncSession::where('token_hash', hash('sha256', $token))->value('id');
        $crypto = app(SyncNativeCryptoService::class);
        $state = $crypto->snapshot($user->id, $sessionId);
        $context = ['session_id' => $sessionId, 'epoch' => $state['epoch'], 'revision' => $state['revision']];
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/native-sodium.json'), true, flags: JSON_THROW_ON_ERROR);
        $bundle = json_encode($fixture['bundle'], JSON_THROW_ON_ERROR);
        $keyId = $fixture['key_id'];
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        DB::disconnect();
        $child = pcntl_fork();
        $this->assertNotSame(-1, $child);
        if ($child === 0) {
            fclose($sockets[0]);
            try {
                stream_set_timeout($sockets[1], 10);
                if (trim((string) fgets($sockets[1])) !== 'go') {
                    exit(2);
                }
                DB::statement("SET statement_timeout = '8s'");
                fwrite($sockets[1], json_encode(['pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid])."\n");
                try {
                    match ($scenario) {
                        'legacy_write' => app(SyncStorageService::class)->upsertRecord($user->id, 'bookmarks', 'legacy', 'old payload'),
                        'legacy_session' => app(SyncAuthService::class)->createSessionToken($user),
                        default => $crypto->activate($user->id, $context, $keyId, $bundle, false),
                    };
                    $result = ['error' => 'unexpected_success'];
                } catch (SyncProtocolException $error) {
                    $result = ['error' => $error->error];
                }
                fwrite($sockets[1], json_encode($result, JSON_THROW_ON_ERROR)."\n");
                DB::disconnect();
                fclose($sockets[1]);
                exit(0);
            } catch (\Throwable $error) {
                fwrite($sockets[1], json_encode(['error' => $error->getMessage()])."\n");
                exit(1);
            }
        }
        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);
        try {
            DB::beginTransaction();
            if ($scenario === 'legacy_first') {
                app(SyncStorageService::class)->upsertRecord($user->id, 'bookmarks', 'legacy', 'old payload');
            } else {
                $crypto->activate($user->id, $context, $keyId, $bundle, false);
            }
            fwrite($sockets[0], "go\n");
            $ready = json_decode((string) fgets($sockets[0]), true, flags: JSON_THROW_ON_ERROR);
            $this->assertArrayHasKey('pid', $ready);
            $this->assertTrue($this->waitForDatabaseLock($ready['pid']), 'Cutover must overlap and block on the actual account row.');
            DB::commit();
            $result = json_decode((string) fgets($sockets[0]), true, flags: JSON_THROW_ON_ERROR);
            pcntl_waitpid($child, $status);
            $child = null;
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status), json_encode($result));
            $this->assertSame(['error' => $expected], $result);
            $this->assertDatabaseCount('sync_native_keys', $scenario === 'legacy_first' ? 0 : 1);
            $this->assertDatabaseCount('records', $scenario === 'legacy_first' ? 1 : 0);
            $this->assertDatabaseCount('sync_sessions', 1);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            fclose($sockets[0]);
            if ($child !== null) {
                posix_kill($child, SIGTERM);
                pcntl_waitpid($child, $status);
            }
        }
    }

    public static function deviceRevocationScenarios(): array
    {
        return [[false, 'device_required'], [true, 'created']];
    }

    #[DataProvider('deviceRevocationScenarios')]
    public function test_session_issuance_waits_for_device_revocation_or_rollback(bool $rollback, string $expected): void
    {
        $user = User::factory()->create();
        $device = Device::create(['user_id' => $user->id, 'device_id' => 'revocation-race', 'name' => 'Race', 'type' => 'desktop']);
        $auth = app(SyncAuthService::class);
        $auth->createSessionToken($user, $device->id);
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        DB::disconnect();
        $child = pcntl_fork();
        $this->assertNotSame(-1, $child);
        if ($child === 0) {
            fclose($sockets[0]);
            try {
                stream_set_timeout($sockets[1], 10);
                if (trim((string) fgets($sockets[1])) !== 'go') {
                    exit(2);
                }
                DB::statement("SET statement_timeout = '8s'");
                fwrite($sockets[1], json_encode(['pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid])."\n");
                try {
                    $auth->createSessionToken($user, $device->id);
                    $result = 'created';
                } catch (SyncProtocolException $error) {
                    $result = $error->error;
                }
                fwrite($sockets[1], json_encode(['result' => $result], JSON_THROW_ON_ERROR)."\n");
                DB::disconnect();
                fclose($sockets[1]);
                exit(0);
            } catch (\Throwable $error) {
                fwrite($sockets[1], json_encode(['error' => $error::class])."\n");
                exit(1);
            }
        }
        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);
        try {
            DB::beginTransaction();
            $this->assertSame(1, $auth->revokeDevice($user, $device->device_id));
            fwrite($sockets[0], "go\n");
            $ready = json_decode((string) fgets($sockets[0]), true, flags: JSON_THROW_ON_ERROR);
            $this->assertArrayHasKey('pid', $ready);
            $this->assertTrue($this->waitForDatabaseLock($ready['pid']), 'Session issuance must wait for the revoking account transaction.');
            if ($rollback) {
                DB::rollBack();
            } else {
                DB::commit();
            }
            $result = json_decode((string) fgets($sockets[0]), true, flags: JSON_THROW_ON_ERROR);
            pcntl_waitpid($child, $status);
            $child = null;
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status), json_encode($result));
            $this->assertSame($expected, $result['result']);
            $this->assertSame($rollback ? 2 : 0, SyncSession::where('user_id', $user->id)->count());
            $this->assertSame($rollback, Device::whereKey($device->id)->exists());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            fclose($sockets[0]);
            if ($child !== null) {
                posix_kill($child, SIGTERM);
                pcntl_waitpid($child, $status);
            }
        }
    }

    public static function refreshScenarios(): array
    {
        return [
            'same operation recovers response' => ['replay', 'rotated'],
            'different operation revokes family' => ['reuse', 'refresh_reused'],
            'rollback preserves unused proof' => ['rollback', 'rotated'],
            'revocation prevents renewal' => ['revoke_first', 'invalid_refresh'],
            'revocation finds concurrently consumed proof' => ['revoke_after', 'revoked'],
            'device revocation prevents renewal' => ['device_revoke', 'invalid_refresh'],
            'authenticated session revocation prevents renewal' => ['access_first', 'invalid_refresh'],
            'authenticated session revocation follows rotation' => ['access_after', 'revoked'],
        ];
    }

    #[DataProvider('refreshScenarios')]
    public function test_refresh_rotations_and_revocations_serialize_on_the_same_authorization(string $scenario, string $expected): void
    {
        config(['services.sync.local_dev' => true]);
        $user = User::factory()->create(['authentik_issuer' => SyncIdentityService::DEVELOPMENT_ISSUER]);
        $device = Device::create(['user_id' => $user->id, 'device_id' => (string) Str::uuid(), 'name' => 'Renewal race', 'type' => 'desktop']);
        $auth = app(SyncAuthService::class);
        $data = $auth->createSessionToken($user, $device->id, protocolVersion: 2, renewable: true);
        $sessionId = $auth->validateToken($data['token'])->id;
        SyncSession::where('user_id', $user->id)->update(['refreshed_at' => now()->subMinutes(2)]);
        $refresh = app(SyncRefreshService::class);
        $operation = (string) Str::uuid();
        $secondOperation = $scenario === 'reuse' ? (string) Str::uuid() : $operation;
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        DB::disconnect();
        $child = pcntl_fork();
        $this->assertNotSame(-1, $child);
        if ($child === 0) {
            fclose($sockets[0]);
            try {
                stream_set_timeout($sockets[1], 10);
                if (trim((string) fgets($sockets[1])) !== 'go') {
                    exit(2);
                }
                DB::statement("SET statement_timeout = '8s'");
                fwrite($sockets[1], json_encode(['pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid])."\n");
                try {
                    if ($scenario === 'revoke_after') {
                        $refresh->revoke($data['refresh_token']);
                        $result = ['status' => 'revoked'];
                    } elseif ($scenario === 'access_after') {
                        $result = ['status' => $auth->revokeSession($user->id, $sessionId) ? 'revoked' : 'missing'];
                    } else {
                        $response = $refresh->rotate($data['refresh_token'], $secondOperation);
                        $result = ['status' => $response['error'] ?? 'rotated', 'response_hash' => hash('sha256', json_encode($response))];
                    }
                } catch (SyncProtocolException $error) {
                    $result = ['status' => $error->error];
                }
                fwrite($sockets[1], json_encode($result, JSON_THROW_ON_ERROR)."\n");
                DB::disconnect();
                fclose($sockets[1]);
                exit(0);
            } catch (\Throwable $error) {
                fwrite($sockets[1], json_encode(['error' => $error::class])."\n");
                exit(1);
            }
        }
        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);
        try {
            DB::beginTransaction();
            $first = null;
            if ($scenario === 'revoke_first') {
                $refresh->revoke($data['refresh_token']);
            } elseif ($scenario === 'access_first') {
                $this->assertTrue($auth->revokeSession($user->id, $sessionId));
            } elseif ($scenario === 'device_revoke') {
                $auth->revokeDevice($user, $device->device_id);
            } else {
                $first = $refresh->rotate($data['refresh_token'], $operation);
                $this->assertArrayHasKey('token', $first);
            }
            fwrite($sockets[0], "go\n");
            $ready = json_decode((string) fgets($sockets[0]), true, flags: JSON_THROW_ON_ERROR);
            $this->assertArrayHasKey('pid', $ready);
            $this->assertTrue($this->waitForDatabaseLock($ready['pid']), 'Renewal and revocation must overlap on the actual account lock.');
            if ($scenario === 'rollback') {
                DB::rollBack();
            } else {
                DB::commit();
            }
            $result = json_decode((string) fgets($sockets[0]), true, flags: JSON_THROW_ON_ERROR);
            pcntl_waitpid($child, $status);
            $child = null;
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status), json_encode($result));
            $this->assertSame($expected, $result['status']);
            $survives = in_array($scenario, ['replay', 'rollback'], true);
            $this->assertSame($survives ? 1 : 0, SyncSession::where('user_id', $user->id)->count());
            $this->assertSame($survives ? 1 : 0, DB::table('sync_refresh_receipts')->count());
            if ($scenario === 'replay') {
                $this->assertSame(hash('sha256', json_encode($first)), $result['response_hash']);
                $this->assertNotNull($auth->validateToken($first['token']));
            } elseif ($first !== null) {
                $this->assertNull($auth->validateToken($first['token']));
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            fclose($sockets[0]);
            if ($child !== null) {
                posix_kill($child, SIGTERM);
                pcntl_waitpid($child, $status);
            }
        }
    }

    private function waitForDatabaseLock(int $pid): bool
    {
        $deadline = microtime(true) + 3;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $activity = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$pid]);
            if ($activity?->wait_event_type === 'Lock') {
                return true;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        return false;
    }
}
