<?php

namespace Tests\Integration;

use App\Models\Device;
use App\Models\SyncSession;
use App\Models\User;
use App\Services\SyncNotificationTickets;
use App\Services\SyncStorageService;
use Database\Seeders\CollectionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncNotificationStreamTest extends TestCase
{
    private $process = null;

    private array $pipes = [];

    private array $sockets = [];

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
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            fclose($socket);
        }
        if ($this->process !== null) {
            proc_terminate($this->process);
            foreach ($this->pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($this->process);
        }
        parent::tearDown();
    }

    public function test_stream_accepts_one_use_tickets_and_sends_only_account_scoped_hints(): void
    {
        $port = $this->freePort();
        $this->process = proc_open(
            [PHP_BINARY, base_path('artisan'), 'sync:notifications', "--port={$port}"],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $this->pipes, base_path(), null,
        );
        $this->assertIsResource($this->process);
        $this->waitForServer($port);

        [$firstUser, $firstTicket] = $this->ticket();
        [$secondUser, $secondTicket] = $this->ticket();
        $first = $this->connect($port, $firstTicket);
        $second = $this->connect($port, $secondTicket);
        $this->assertStringContainsString('HTTP/1.1 200 OK', $this->readUntil($first, ': connected'));
        $this->assertStringContainsString('HTTP/1.1 200 OK', $this->readUntil($second, ': connected'));

        $replayed = $this->connect($port, $firstTicket);
        $this->assertStringContainsString('401 Unauthorized', $this->readUntil($replayed, '401 Unauthorized'));

        app(SyncStorageService::class)->upsertRecord($firstUser->id, 'bookmarks', 'first', 'opaque-ciphertext');
        $firstEvent = $this->readUntil($first, 'event: changed');
        $this->assertStringContainsString("event: changed\ndata: {}", $firstEvent);
        $this->assertStringNotContainsString((string) $firstUser->id, $firstEvent);
        $this->assertFalse($this->hasData($second, 150));

        app(SyncStorageService::class)->upsertRecord($secondUser->id, 'bookmarks', 'second', 'opaque-ciphertext');
        $this->assertStringContainsString('event: changed', $this->readUntil($second, 'event: changed'));
        $this->assertFalse($this->hasData($first, 150));

        SyncSession::where('user_id', $firstUser->id)->delete();
        app(SyncStorageService::class)->upsertRecord($firstUser->id, 'bookmarks', 'after-revocation', 'opaque-ciphertext');
        $this->assertTrue($this->closedWithoutChangeWithin($first, 2000));
        $this->assertFalse($this->hasData($second, 150));
    }

    private function ticket(): array
    {
        $user = User::factory()->create(['storage_quota_bytes' => 104857600]);
        $device = Device::create([
            'user_id' => $user->id, 'device_id' => (string) Str::uuid(),
            'name' => 'Stream test', 'type' => 'desktop',
        ]);
        $session = SyncSession::create([
            'user_id' => $user->id, 'device_id' => $device->id,
            'token_hash' => hash('sha256', Str::random(64)), 'protocol_version' => 2,
            'expires_at' => now()->addHour(), 'created_at' => now(),
        ]);

        return [$user, app(SyncNotificationTickets::class)->issue($session)['ticket']];
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($socket);
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    }

    private function waitForServer(int $port): void
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errorCode, $errorMessage, 0.1);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(20000);
        }
        $stderr = stream_get_contents($this->pipes[2]);
        $this->fail("Notification server did not start: {$stderr}");
    }

    private function connect(int $port, string $ticket)
    {
        $socket = stream_socket_client("tcp://127.0.0.1:{$port}", $errorCode, $errorMessage, 2);
        $this->assertNotFalse($socket);
        stream_set_blocking($socket, false);
        fwrite($socket, "GET /api/v1/sync/notifications/stream HTTP/1.1\r\nHost: localhost\r\nAuthorization: MidoriNotification {$ticket}\r\n\r\n");
        $this->sockets[] = $socket;

        return $socket;
    }

    private function readUntil($socket, string $needle): string
    {
        $output = '';
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $read = [$socket];
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, 0, 20000) === 1) {
                $output .= fread($socket, 4096);
                if (str_contains($output, $needle)) {
                    return $output;
                }
            }
        }
        $this->fail("Notification stream did not contain {$needle}.");
    }

    private function hasData($socket, int $timeoutMs): bool
    {
        $read = [$socket];
        $write = null;
        $except = null;

        return stream_select($read, $write, $except, 0, $timeoutMs * 1000) === 1 && fread($socket, 4096) !== '';
    }

    private function closedWithoutChangeWithin($socket, int $timeoutMs): bool
    {
        $deadline = microtime(true) + $timeoutMs / 1000;
        $received = '';
        while (microtime(true) < $deadline) {
            $read = [$socket];
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, 0, 20000) === 1) {
                $received .= fread($socket, 4096);
                if (str_contains($received, 'event: changed')) {
                    return false;
                }
                if (feof($socket)) {
                    return true;
                }
            }
        }

        return false;
    }
}
