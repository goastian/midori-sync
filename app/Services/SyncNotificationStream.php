<?php

namespace App\Services;

use App\Models\SyncSession;
use Illuminate\Support\Facades\DB;

class SyncNotificationStream
{
    private const CHANNEL = 'midori_sync_change';

    private const MAX_CLIENTS = 512;

    private const MAX_HEADERS = 2048;

    private const HEARTBEAT_SECONDS = 20;

    private const MAX_CONNECTION_SECONDS = 900;

    public function __construct(private SyncNotificationTickets $tickets) {}

    public function serve(int $port): void
    {
        if (! extension_loaded('pgsql')) {
            throw new \RuntimeException('The pgsql extension is required for Sync notifications.');
        }
        $listener = @pg_connect($this->connectionString(), PGSQL_CONNECT_FORCE_NEW);
        if ($listener === false || pg_query($listener, 'LISTEN '.self::CHANNEL) === false) {
            throw new \RuntimeException('Could not listen for Sync changes.');
        }
        $server = stream_socket_server("tcp://127.0.0.1:{$port}", $errorCode, $errorMessage);
        if ($server === false) {
            throw new \RuntimeException("Could not start Sync notifications: {$errorMessage}");
        }
        stream_set_blocking($server, false);
        $clients = [];
        $pgSocket = pg_socket($listener);

        try {
            while (true) {
                if (pg_connection_status($listener) !== PGSQL_CONNECTION_OK) {
                    throw new \RuntimeException('Sync notification database connection closed.');
                }
                $read = [$server, $pgSocket];
                $write = [];
                foreach ($clients as $client) {
                    $read[] = $client['socket'];
                    if ($client['output'] !== '') {
                        $write[] = $client['socket'];
                    }
                }
                $except = null;
                $selected = stream_select($read, $write, $except, 1);
                if ($selected === false) {
                    throw new \RuntimeException('Sync notification socket poll failed.');
                }
                if (in_array($server, $read, true)) {
                    $socket = stream_socket_accept($server, 0);
                    if ($socket !== false) {
                        stream_set_blocking($socket, false);
                        if (count($clients) >= self::MAX_CLIENTS) {
                            fclose($socket);
                        } else {
                            $clients[(int) $socket] = [
                                'socket' => $socket, 'input' => '', 'output' => '',
                                'user_id' => null, 'session_id' => null,
                                'connected_at' => time(), 'last_ping' => time(),
                            ];
                        }
                    }
                }
                if (in_array($pgSocket, $read, true)) {
                    if (! pg_consume_input($listener)) {
                        throw new \RuntimeException('Could not consume Sync change notifications.');
                    }
                    while (($notification = pg_get_notify($listener, PGSQL_ASSOC)) !== false) {
                        if ($notification['message'] !== self::CHANNEL || ! ctype_digit($notification['payload'])) {
                            continue;
                        }
                        $userId = (int) $notification['payload'];
                        $recipients = [];
                        foreach ($clients as $id => $client) {
                            if ($client['user_id'] === $userId) {
                                $recipients[$id] = $client['session_id'];
                            }
                        }
                        if ($recipients === []) {
                            continue;
                        }
                        $validSessions = SyncSession::where('user_id', $userId)->whereIn('id', array_values($recipients))
                            ->valid()->pluck('id')->all();
                        $validSessions = array_fill_keys($validSessions, true);
                        foreach ($recipients as $id => $sessionId) {
                            if (! isset($validSessions[$sessionId])) {
                                $this->close($clients, $id);

                                continue;
                            }
                            $clients[$id]['output'] .= "event: changed\ndata: {}\n\n";
                        }
                    }
                }
                foreach ($clients as $id => &$client) {
                    if (in_array($client['socket'], $read, true) && $client['user_id'] !== null) {
                        fread($client['socket'], 1);
                        $this->close($clients, $id);

                        continue;
                    }
                    if (in_array($client['socket'], $read, true)) {
                        $chunk = fread($client['socket'], self::MAX_HEADERS + 1);
                        if ($chunk === false || $chunk === '' && feof($client['socket'])) {
                            $this->close($clients, $id);

                            continue;
                        }
                        $client['input'] .= $chunk;
                        if (strlen($client['input']) > self::MAX_HEADERS) {
                            $this->close($clients, $id);

                            continue;
                        }
                        if (str_contains($client['input'], "\r\n\r\n")) {
                            $identity = $this->authenticate($client['input']);
                            if ($identity === null) {
                                $client['output'] = "HTTP/1.1 401 Unauthorized\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
                                $client['user_id'] = 0;
                            } else {
                                $client['user_id'] = $identity['user_id'];
                                $client['session_id'] = $identity['session_id'];
                                $client['output'] = "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nCache-Control: no-store\r\nX-Accel-Buffering: no\r\nConnection: keep-alive\r\n\r\n: connected\n\n";
                            }
                            $client['input'] = '';
                        }
                    }
                    if (! isset($clients[$id])) {
                        continue;
                    }
                    if (in_array($client['socket'], $write, true) && $client['output'] !== '') {
                        $sent = fwrite($client['socket'], $client['output']);
                        if ($sent === false) {
                            $this->close($clients, $id);

                            continue;
                        }
                        $client['output'] = substr($client['output'], $sent);
                        if ($client['user_id'] === 0 && $client['output'] === '') {
                            $this->close($clients, $id);

                            continue;
                        }
                    }
                    if (($client['user_id'] === null && time() - $client['connected_at'] >= 5) ||
                        time() - $client['connected_at'] >= self::MAX_CONNECTION_SECONDS) {
                        $this->close($clients, $id);

                        continue;
                    }
                    if ($client['user_id'] > 0 && time() - $client['last_ping'] >= self::HEARTBEAT_SECONDS) {
                        $client['last_ping'] = time();
                        if (! SyncSession::whereKey($client['session_id'])->valid()->exists()) {
                            $this->close($clients, $id);

                            continue;
                        }
                        $client['output'] .= ": heartbeat\n\n";
                    }
                    if (strlen($client['output']) > 4096) {
                        $this->close($clients, $id);
                    }
                }
                unset($client);
            }
        } finally {
            foreach ($clients as $client) {
                fclose($client['socket']);
            }
            fclose($server);
            pg_close($listener);
        }
    }

    private function authenticate(string $headers): ?array
    {
        $lines = explode("\r\n", $headers);
        if (! preg_match('#^GET /api/v1/sync/notifications/stream HTTP/1\.[01]$#D', $lines[0])) {
            return null;
        }
        $ticket = null;
        foreach ($lines as $line) {
            if (! str_starts_with(strtolower($line), 'authorization:')) {
                continue;
            }
            if ($ticket !== null || ! preg_match('/^Authorization: MidoriNotification ([0-9a-f]{64})$/Di', $line, $matches)) {
                return null;
            }
            $ticket = $matches[1];
        }

        return $ticket === null ? null : $this->tickets->consume($ticket);
    }

    private function close(array &$clients, int $id): void
    {
        fclose($clients[$id]['socket']);
        unset($clients[$id]);
    }

    private function connectionString(): string
    {
        $config = config('database.connections.pgsql');
        if (DB::getDefaultConnection() !== 'pgsql') {
            throw new \RuntimeException('Sync notifications require PostgreSQL.');
        }
        if (! empty($config['url'])) {
            return $config['url'];
        }
        $parts = [
            'host' => $config['host'], 'port' => $config['port'],
            'dbname' => $config['database'], 'user' => $config['username'],
            'password' => $config['password'], 'sslmode' => $config['sslmode'] ?? null,
        ];

        return implode(' ', array_map(
            fn ($key, $value) => $key."='".str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value)."'",
            array_keys(array_filter($parts, fn ($value) => $value !== null && $value !== '')),
            array_values(array_filter($parts, fn ($value) => $value !== null && $value !== '')),
        ));
    }
}
