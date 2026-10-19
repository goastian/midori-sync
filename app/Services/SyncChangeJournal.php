<?php

namespace App\Services;

use App\Exceptions\SyncProtocolException;
use App\Models\Device;
use App\Models\Record;
use App\Models\SyncSession;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncChangeJournal
{
    public const MAX_PAGE_SIZE = 100;

    public const MAX_PAGE_BYTES = 4194304;

    public function __construct(private SyncIdentityService $identity) {}

    public function lockUser(int $userId): User
    {
        $this->requireTransaction();

        return User::whereKey($userId)->lockForUpdate()->firstOrFail();
    }

    public function prepare(int $userId, int $collectionId): object
    {
        $this->requireTransaction();
        $stream = $this->stream($userId, $collectionId)->first();
        if ($stream) {
            return $stream;
        }

        $this->stream($userId, $collectionId)->insert([
            'user_id' => $userId,
            'collection_id' => $collectionId,
            'generation' => (string) Str::uuid(),
            'sequence' => 0,
        ]);

        Record::forUser($userId)->inCollection($collectionId)->chunkById(100, function ($records) use ($userId, $collectionId) {
            $this->append($userId, $collectionId, $records);
        });

        return $this->stream($userId, $collectionId)->first();
    }

    public function append(int $userId, int $collectionId, iterable $records): void
    {
        $this->requireTransaction();
        $stream = $this->stream($userId, $collectionId)->first();
        if (! $stream) {
            throw new \LogicException('Prepare the stream before mutating records');
        }

        $sequence = (int) $stream->sequence;
        $rows = [];
        foreach ($records as $record) {
            if ($sequence === PHP_INT_MAX) {
                throw new \OverflowException('Sync sequence exhausted');
            }
            $rows[] = [
                'stream_id' => $stream->id,
                'sequence' => ++$sequence,
                'record' => json_encode([
                    'id' => $record->record_id,
                    'revision' => (string) $record->version,
                    'payload' => $record->payload,
                    'deleted' => $record->deleted,
                    'ttl' => $record->ttl?->toIso8601String(),
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ];
        }

        if ($rows !== []) {
            DB::table('sync_changes')->insert($rows);
            $this->stream($userId, $collectionId)->update(['sequence' => $sequence]);
        }
    }

    public function resetUser(int $userId): void
    {
        $this->requireTransaction();
        foreach (DB::table('sync_streams')->where('user_id', $userId)->get() as $stream) {
            DB::table('sync_history_clears')->where('stream_id', $stream->id)->delete();
            $this->resetStream($stream, null, null);
        }
    }

    public function installMigrationGenerations(int $userId, array $generations): void
    {
        $this->requireTransaction();
        $collections = DB::table('collections')->whereIn('name', array_keys($generations))->pluck('id', 'name');
        if ($collections->count() !== count($generations)) {
            throw new \LogicException('Migration generation has no collection');
        }
        $byId = $collections->flip();
        foreach (DB::table('sync_streams')->where('user_id', $userId)->get() as $stream) {
            DB::table('sync_history_clears')->where('stream_id', $stream->id)->delete();
            $name = $byId[$stream->collection_id] ?? null;
            $this->resetStream($stream, null, null, $name ? $generations[$name] : null);
        }
        foreach ($generations as $name => $generation) {
            $collectionId = $collections[$name];
            if (! $this->stream($userId, $collectionId)->exists()) {
                DB::table('sync_streams')->insert([
                    'user_id' => $userId,
                    'collection_id' => $collectionId,
                    'generation' => $generation,
                    'sequence' => 0,
                ]);
            }
        }
    }

    public function resetHistory(object $stream, int $clearBeforeMs): string
    {
        $this->requireTransaction();
        if (! DB::table('sync_streams')->where('id', $stream->id)
            ->where('user_id', $stream->user_id)->where('collection_id', $stream->collection_id)->exists()) {
            throw new \LogicException('Unknown sync stream');
        }

        return $this->resetStream($stream, 'history_clear', $clearBeforeMs);
    }

    private function resetStream(object $stream, ?string $kind, ?int $atMs, ?string $generation = null): string
    {
        DB::table('sync_operations')->where('stream_id', $stream->id)->delete();
        DB::table('sync_changes')->where('stream_id', $stream->id)->delete();
        DB::table('sync_device_cursors')->where('stream_id', $stream->id)->delete();
        DB::table('sync_compactions')->where('stream_id', $stream->id)->delete();
        $generation ??= (string) Str::uuid();
        DB::table('sync_streams')->where('id', $stream->id)->update([
            'generation' => $generation,
            'sequence' => 0,
            'reset_kind' => $kind,
            'reset_at_ms' => $atMs,
            'control_revision' => 0, 'control' => null, 'compacted_through' => 0,
        ]);

        return $generation;
    }

    public function controlState(object $stream): array
    {
        return ['generation' => $stream->generation, 'control_revision' => (string) $stream->control_revision,
            'control' => $stream->control === null ? null : json_decode($stream->control, true, flags: JSON_THROW_ON_ERROR)];
    }

    public function requireGeneration(object $stream, string $generation): void
    {
        if ($stream->generation !== $generation) {
            throw new SyncProtocolException('reset_required', 409, $this->controlState($stream));
        }
    }

    public function installControl(object $stream, array $control): void
    {
        $this->requireTransaction();
        $history = $control['kind'] === 'history_clear';
        $this->resetStream($stream, $history ? 'history_clear' : null, $history ? $control['clear_before_ms'] : null, $control['generation']);
        DB::table('sync_streams')->where('id', $stream->id)->update([
            'control_revision' => (int) $control['control_revision'],
            'control' => json_encode($control, JSON_THROW_ON_ERROR),
        ]);
    }

    public function compact(object $stream): array
    {
        $this->requireTransaction();
        DB::table('sync_changes')->where('stream_id', $stream->id)->delete();
        DB::table('sync_streams')->where('id', $stream->id)->update(['compacted_through' => $stream->sequence]);
        $count = 0;
        Record::forUser($stream->user_id)->inCollection($stream->collection_id)->chunkById(100, function ($records) use ($stream, &$count) {
            $this->append($stream->user_id, $stream->collection_id, $records);
            $count += $records->count();
        });
        $current = DB::table('sync_streams')->where('id', $stream->id)->first();

        return ['generation' => $stream->generation, 'compacted_through' => (string) $stream->sequence,
            'snapshot_sequence' => (string) $current->sequence, 'records' => $count];
    }

    public function changes(int $userId, int $collectionId, int $deviceId, ?string $cursor, int $limit, string $sessionId): array
    {
        if ($limit < 1 || $limit > self::MAX_PAGE_SIZE) {
            throw new SyncProtocolException('invalid_page_size');
        }

        return DB::transaction(function () use ($userId, $collectionId, $deviceId, $cursor, $limit, $sessionId) {
            $this->lockUser($userId);
            $this->requireSession($userId, $sessionId, $deviceId);
            $stream = $this->prepare($userId, $collectionId);
            $position = $cursor ? $this->decodeCursor($cursor, $stream, $deviceId) : null;
            $after = $position ? (int) $position['sequence'] : 0;
            $fence = (int) ($position['fence'] ?? $stream->sequence);
            $floor = (int) $stream->compacted_through;
            $rebased = $position !== null && ($after < $floor || (($position['fence'] ?? null) !== null && (int) $position['fence'] <= $floor));
            if ($rebased || $position === null) {
                $after = $floor;
                $fence = (int) $stream->sequence;
            }
            $changes = [];
            $bytes = 0;

            $rows = DB::table('sync_changes')->where('stream_id', $stream->id)
                ->where('sequence', '>', $after)->where('sequence', '<=', $fence)
                ->orderBy('sequence')->limit($limit)->cursor();

            foreach ($rows as $row) {
                $size = strlen($row->record);
                if ($size > self::MAX_PAGE_BYTES) {
                    throw new SyncProtocolException('record_too_large', 413);
                }
                if ($changes !== [] && $bytes + $size > self::MAX_PAGE_BYTES) {
                    break;
                }
                $changes[] = [
                    'sequence' => (string) $row->sequence,
                    'record' => json_decode($row->record, true, flags: JSON_THROW_ON_ERROR),
                ];
                $bytes += $size;
                $after = (int) $row->sequence;
            }

            $hasMore = $after < $fence;

            $result = [
                ...$this->controlState($stream),
                'changes' => $changes,
                'has_more' => $hasMore,
                'snapshot_sequence' => (string) $fence,
                'snapshot_rebased' => $rebased,
                'compacted_through' => (string) $floor,
                'next_cursor' => $this->encodeCursor($stream, $deviceId, $after, $hasMore ? $fence : null),
            ];
            if ($stream->reset_kind === 'history_clear') {
                $result['reset'] = [
                    'kind' => 'history_clear',
                    'clear_before' => Carbon::createFromTimestampMs((int) $stream->reset_at_ms, 'UTC')->toISOString(),
                ];
            }

            return $result;
        }, 5);
    }

    public function acknowledge(int $userId, int $collectionId, int $deviceId, string $cursor, string $sessionId): array
    {
        return DB::transaction(function () use ($userId, $collectionId, $deviceId, $cursor, $sessionId) {
            $this->lockUser($userId);
            $this->requireSession($userId, $sessionId, $deviceId);
            $stream = $this->prepare($userId, $collectionId);
            $position = $this->decodeCursor($cursor, $stream, $deviceId);
            $query = DB::table('sync_device_cursors')->where('stream_id', $stream->id)->where('device_id', $deviceId);
            $sequence = max((int) ($query->value('sequence') ?? 0), (int) $position['sequence']);

            DB::table('sync_device_cursors')->updateOrInsert(
                ['stream_id' => $stream->id, 'device_id' => $deviceId],
                ['sequence' => $sequence, 'acknowledged_at' => now()],
            );

            return ['generation' => $stream->generation, 'acknowledged_sequence' => (string) $sequence];
        }, 5);
    }

    private function stream(int $userId, int $collectionId): Builder
    {
        return DB::table('sync_streams')->where('user_id', $userId)->where('collection_id', $collectionId);
    }

    private function encodeCursor(object $stream, int $deviceId, int $sequence, ?int $fence): string
    {
        return Crypt::encryptString(json_encode([
            'version' => 1,
            'user' => (string) $stream->user_id,
            'collection' => (string) $stream->collection_id,
            'device' => (string) $deviceId,
            'generation' => $stream->generation,
            'sequence' => (string) $sequence,
            'fence' => $fence === null ? null : (string) $fence,
        ], JSON_THROW_ON_ERROR));
    }

    private function decodeCursor(string $cursor, object $stream, int $deviceId): array
    {
        try {
            $value = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException $e) {
            throw new SyncProtocolException('invalid_cursor');
        }

        if (! is_array($value) || ($value['version'] ?? null) !== 1
            || ($value['user'] ?? null) !== (string) $stream->user_id
            || ($value['collection'] ?? null) !== (string) $stream->collection_id
            || ($value['device'] ?? null) !== (string) $deviceId) {
            throw new SyncProtocolException('invalid_cursor');
        }
        $this->requireGeneration($stream, $value['generation'] ?? '');

        $sequence = $value['sequence'] ?? null;
        $fence = $value['fence'] ?? null;
        if (! $this->validSequence($sequence) || ($fence !== null && ! $this->validSequence($fence))
            || (int) $sequence > (int) $stream->sequence
            || ($fence !== null && ((int) $fence < (int) $sequence || (int) $fence > (int) $stream->sequence))) {
            throw new SyncProtocolException('invalid_cursor');
        }

        return $value;
    }

    private function validSequence(mixed $value): bool
    {
        return is_string($value) && preg_match('/^(0|[1-9][0-9]{0,18})$/D', $value)
            && (strlen($value) < strlen((string) PHP_INT_MAX) || strcmp($value, (string) PHP_INT_MAX) <= 0);
    }

    public function requireDevice(int $userId, int $deviceId): void
    {
        if (! Device::whereKey($deviceId)->where('user_id', $userId)->exists()) {
            throw new SyncProtocolException('device_required', 409);
        }
    }

    public function requireSession(int $userId, string $sessionId, ?int $deviceId = null): SyncSession
    {
        $this->requireTransaction();
        $session = SyncSession::whereKey($sessionId)->where('user_id', $userId)->valid()->lockForUpdate()->first();
        if (! $session) {
            throw new SyncProtocolException('auth_required', 401);
        }
        if ($session->protocol_version !== 2) {
            throw new SyncProtocolException('client_upgrade_required', 426);
        }
        if (! $session->device_id || ($deviceId !== null && $session->device_id !== $deviceId)) {
            throw new SyncProtocolException('device_required', 409);
        }
        $this->requireDevice($userId, $session->device_id);
        $this->identity->forUser(User::findOrFail($userId));

        return $session;
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('The sync journal requires a transaction and the account lock');
        }
    }
}
