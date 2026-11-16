<?php

namespace App\Services;

use App\Exceptions\SyncProtocolException;
use App\Models\CryptoKeyBundle;
use App\Models\Device;
use App\Models\Record;
use App\Models\SyncSession;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncNativeCryptoService
{
    public const MAX_KEYS = 8;

    public const MAX_REVISION = 2147483647;

    public function __construct(private SyncChangeJournal $journal, private SyncIdentityService $identity, private SyncNativeEnvelope $envelope) {}

    public function snapshot(int $userId, string $sessionId): array
    {
        return DB::transaction(function () use ($userId, $sessionId) {
            $this->journal->lockUser($userId);
            $this->requireSession($userId, $sessionId);

            return $this->format($this->state($userId));
        }, 5);
    }

    public function activate(int $userId, array $context, string $keyId, string $bundle, bool $revokeIncompatible, ?array $identityKey = null, string $registryAuth = '', int $registryAuthVersion = 1): array
    {
        $this->envelope->validateBundle($keyId, $bundle);
        $this->validateIdentityKey($identityKey, [$keyId]);
        $this->validateRegistryAuth($registryAuth);

        return DB::transaction(function () use ($userId, $context, $keyId, $bundle, $revokeIncompatible, $identityKey, $registryAuth, $registryAuthVersion) {
            $this->journal->lockUser($userId);
            $this->requireSession($userId, $context['session_id']);
            $state = $this->state($userId);
            $this->requireRevision($state, $context);
            $this->requireRegistryVersion($state, $registryAuthVersion);
            if ($state->active_key_id !== null) {
                throw new SyncProtocolException('native_keys_already_active', 409);
            }
            if ($this->migrationRequired($state)) {
                throw new SyncProtocolException('legacy_migration_required', 409);
            }
            $legacy = $this->incompatibleSessions($userId);
            if ($legacy->exists()) {
                if (! $revokeIncompatible) {
                    throw new SyncProtocolException('incompatible_devices', 409);
                }
                $legacy->delete();
            }
            $this->insertKey($userId, $keyId, $bundle);
            $this->journal->resetUser($userId);
            DB::table('sync_crypto_states')->where('user_id', $userId)->update([
                'native_enabled' => true, 'active_key_id' => $keyId, 'revision' => $state->revision + 1,
                'custody' => 'client', 'identity_key_id' => $identityKey['key_id'],
                'identity_bundle' => $identityKey['encrypted_bundle'],
                'registry_auth' => $registryAuth, 'registry_auth_version' => $registryAuthVersion,
            ]);

            return $this->format($this->state($userId));
        }, 5);
    }

    public function recoverySecret(int $userId, string $sessionId): array
    {
        throw new SyncProtocolException('server_recovery_disabled', 409);
    }

    public function passwordBackup(int $userId, string $sessionId): array
    {
        return DB::transaction(function () use ($userId, $sessionId) {
            $this->journal->lockUser($userId);
            $this->requireSession($userId, $sessionId);
            $state = $this->state($userId);
            $backup = $this->currentPasswordBackup($state)->first();
            if (! $backup) {
                throw new SyncProtocolException('password_backup_unavailable', 409);
            }

            return ['epoch' => $state->epoch, 'capsule' => json_decode($backup->capsule, true, 16, JSON_THROW_ON_ERROR)];
        }, 5);
    }

    public function savePasswordBackup(int $userId, array $context, array $capsule): void
    {
        $this->envelope->validatePasswordCapsule($capsule, $context['epoch']);
        DB::transaction(function () use ($userId, $context, $capsule) {
            $this->journal->lockUser($userId);
            $state = $this->authorizeWrite($userId, $context);
            if ($state->custody !== 'client' || ! $state->identity_key_id || ($this->rotationProgress($userId)['phase'] ?? null) === 'rewriting') {
                throw new SyncProtocolException('password_backup_unavailable', 409);
            }
            DB::table('sync_password_backups')->updateOrInsert(['user_id' => $userId], [
                'epoch' => $state->epoch, 'registry_fingerprint' => $this->passwordRegistryFingerprint($state),
                'capsule' => json_encode($capsule, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }, 5);
    }

    private function passwordRegistryFingerprint(object $state): string
    {
        $keys = DB::table('sync_native_keys')->where('user_id', $state->user_id)->orderBy('key_id')->pluck('key_id')->all();

        return hash('sha256', json_encode([$state->active_key_id, $state->identity_key_id, $keys], JSON_THROW_ON_ERROR));
    }

    private function currentPasswordBackup(object $state): \Illuminate\Database\Query\Builder
    {
        return DB::table('sync_password_backups')->where('user_id', $state->user_id)->where('epoch', $state->epoch)
            ->where('registry_fingerprint', $this->passwordRegistryFingerprint($state));
    }

    public function escrow(int $userId, array $context, array $keys, string $recoverySecret): array
    {
        throw new SyncProtocolException('server_recovery_disabled', 409);
    }

    public function rotate(int $userId, array $context, string $keyId, string $bundle): array
    {
        $this->envelope->validateBundle($keyId, $bundle);

        return DB::transaction(function () use ($userId, $context) {
            $this->journal->lockUser($userId);
            $state = $this->authorizeWrite($userId, $context);
            if (! $state) {
                throw new SyncProtocolException('native_keys_required', 409);
            }
            throw new SyncProtocolException('durable_rotation_required', 409);
        }, 5);
    }

    public function rewrap(int $userId, array $context, string $keyId, string $bundle): array
    {
        $this->envelope->validateBundle($keyId, $bundle);

        return DB::transaction(function () use ($userId, $context, $keyId) {
            $this->journal->lockUser($userId);
            $state = $this->authorizeWrite($userId, $context);
            $key = DB::table('sync_native_keys')->where('user_id', $userId)->where('key_id', $keyId);
            if (! $state || ! $key->exists()) {
                throw new SyncProtocolException('unknown_key', 404);
            }
            throw new SyncProtocolException('durable_rotation_required', 409);
        }, 5);
    }

    public function startRotation(int $userId, array $context, string $operationId, string $keyId, string $bundle, array $keys, array $identityKey, string $registryAuth, int $registryAuthVersion = 1): array
    {
        $this->envelope->validateBundle($keyId, $bundle);
        $this->validateRegistryAuth($registryAuth);
        usort($keys, fn ($left, $right) => strcmp($left['key_id'], $right['key_id']));
        $operationId = strtolower($operationId);
        $fingerprintParts = [$keyId, $bundle, $keys, $identityKey, $registryAuth];
        if ($registryAuthVersion !== 1) {
            $fingerprintParts[] = $registryAuthVersion;
        }
        $fingerprint = hash('sha256', json_encode($fingerprintParts, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($userId, $context, $operationId, $keyId, $bundle, $keys, $identityKey, $fingerprint, $registryAuth, $registryAuthVersion) {
            $this->journal->lockUser($userId);
            $this->requireSession($userId, $context['session_id']);
            $state = $this->state($userId);
            $previous = DB::table('sync_key_rotations')->where('user_id', $userId)->first();
            if ($previous?->operation_id === $operationId && $context['epoch'] === $state->epoch) {
                if (! hash_equals($previous->fingerprint, $fingerprint)) {
                    throw new SyncProtocolException('idempotency_conflict', 409);
                }

                return $this->format($state);
            }
            $this->authorizeWrite($userId, $context);
            $this->requireRegistryVersion($state, $registryAuthVersion);
            if (! $state->active_key_id) {
                throw new SyncProtocolException('native_keys_required', 409);
            }
            if ($previous?->phase === 'rewriting') {
                throw new SyncProtocolException('rotation_in_progress', 409);
            }
            $existing = DB::table('sync_native_keys')->where('user_id', $userId)->pluck('key_id')->all();
            if (in_array($keyId, $existing, true)) {
                throw new SyncProtocolException('key_id_exists', 409);
            }
            if (count($existing) >= self::MAX_KEYS) {
                throw new SyncProtocolException('key_capacity_reached', 409);
            }
            $expected = [...$existing, $keyId];
            sort($expected, SORT_STRING);
            if ($expected !== array_column($keys, 'key_id')) {
                throw new SyncProtocolException('incomplete_key_registry', 422);
            }
            $this->validateIdentityKey($identityKey, $expected, $state->identity_key_id);
            foreach ($keys as $key) {
                $this->envelope->validateBundle($key['key_id'], $key['encrypted_bundle']);
                if ($key['key_id'] === $keyId && $key['encrypted_bundle'] !== $bundle) {
                    throw new SyncProtocolException('invalid_native_bundle', 422);
                }
                DB::table('sync_native_keys')->updateOrInsert(['user_id' => $userId, 'key_id' => $key['key_id']], [
                    'encrypted_bundle' => $key['encrypted_bundle'], 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('sync_crypto_states')->where('user_id', $userId)->update([
                'active_key_id' => $keyId, 'revision' => $state->revision + 1, 'custody' => 'transition',
                'identity_key_id' => $identityKey['key_id'], 'identity_bundle' => $identityKey['encrypted_bundle'],
                'registry_auth' => $registryAuth, 'registry_auth_version' => $registryAuthVersion,
            ]);
            DB::table('sync_key_rotations')->updateOrInsert(['user_id' => $userId], [
                'operation_id' => $operationId, 'fingerprint' => $fingerprint, 'target_key_id' => $keyId,
                'source_key_ids' => json_encode($existing, JSON_THROW_ON_ERROR), 'phase' => 'rewriting',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $this->format($this->state($userId));
        }, 5);
    }

    public function completeRotation(int $userId, array $context, string $operationId, string $registryAuth, int $registryAuthVersion = 1): array
    {
        $this->validateRegistryAuth($registryAuth);

        return DB::transaction(function () use ($userId, $context, $operationId, $registryAuth, $registryAuthVersion) {
            $this->journal->lockUser($userId);
            $this->requireSession($userId, $context['session_id']);
            $state = $this->state($userId);
            $rotation = $this->rotationProgress($userId);
            if ($rotation === null || $rotation['operation_id'] !== strtolower($operationId)) {
                throw new SyncProtocolException('unknown_rotation', 404);
            }
            if ($rotation['phase'] === 'complete' && $context['epoch'] === $state->epoch) {
                return $this->format($state);
            }
            $this->authorizeWrite($userId, $context);
            $this->requireRegistryVersion($state, $registryAuthVersion);
            if ($rotation['live_records'] !== 0) {
                throw new SyncProtocolException('rotation_records_pending', 409);
            }
            DB::table('sync_server_recovery')->where('user_id', $userId)->delete();
            DB::table('sync_crypto_states')->where('user_id', $userId)->update([
                'custody' => 'client', 'revision' => $state->revision + 1, 'registry_auth' => $registryAuth, 'registry_auth_version' => $registryAuthVersion,
            ]);
            DB::table('sync_key_rotations')->where('user_id', $userId)->update(['phase' => 'complete', 'updated_at' => now()]);

            return $this->format($this->state($userId));
        }, 5);
    }

    public function retireKey(int $userId, array $context, string $keyId, string $registryAuth, int $registryAuthVersion = 1): array
    {
        $this->validateRegistryAuth($registryAuth);

        return DB::transaction(function () use ($userId, $context, $keyId, $registryAuth, $registryAuthVersion) {
            $this->journal->lockUser($userId);
            $state = $this->authorizeWrite($userId, $context);
            $this->requireRegistryVersion($state, $registryAuthVersion);
            if (! $state || $state->custody !== 'client' || $state->active_key_id === $keyId || $state->identity_key_id === $keyId) {
                throw new SyncProtocolException('key_retirement_forbidden', 409);
            }
            $key = DB::table('sync_native_keys')->where('user_id', $userId)->where('key_id', $keyId);
            if (! $key->exists()) {
                throw new SyncProtocolException('unknown_key', 404);
            }
            foreach (Record::where('user_id', $userId)->select('payload')->cursor() as $record) {
                if ($this->referencesKey($record->payload, $keyId)) {
                    throw new SyncProtocolException('key_in_use', 409);
                }
            }
            foreach ($this->journalRecords($userId)->cursor() as $change) {
                $record = json_decode($change->record, true);
                if (! is_array($record) || $this->referencesKey($record['payload'] ?? '', $keyId)) {
                    throw new SyncProtocolException('key_in_use', 409);
                }
            }
            $key->delete();
            DB::table('sync_crypto_states')->where('user_id', $userId)->update(['revision' => $state->revision + 1, 'registry_auth' => $registryAuth, 'registry_auth_version' => $registryAuthVersion]);

            return $this->format($this->state($userId));
        }, 5);
    }

    public function rotationRecords(int $userId, string $sessionId, ?string $cursor, int $limit): array
    {
        return DB::transaction(function () use ($userId, $sessionId, $cursor, $limit) {
            $this->journal->lockUser($userId);
            $this->requireSession($userId, $sessionId);
            $rotation = DB::table('sync_key_rotations')->where('user_id', $userId)->first();
            if (! $rotation) {
                throw new SyncProtocolException('unknown_rotation', 404);
            }
            $after = null;
            if ($cursor !== null) {
                try {
                    $decoded = json_decode(Crypt::decryptString($cursor), true, 16, JSON_THROW_ON_ERROR);
                } catch (DecryptException|\JsonException) {
                    throw new SyncProtocolException('invalid_cursor');
                }
                if (! is_array($decoded) || ($decoded['user'] ?? null) !== (string) $userId ||
                    ($decoded['rotation'] ?? null) !== $rotation->operation_id || ! Str::isUuid($decoded['after'] ?? '')) {
                    throw new SyncProtocolException('invalid_cursor');
                }
                $after = $decoded['after'];
            }
            $query = Record::where('user_id', $userId)->orderBy('id');
            if ($after !== null) {
                $query->where('id', '>', $after);
            }
            $records = [];
            $bytes = 0;
            $hasMore = false;
            $collectionNames = DB::table('collections')->pluck('name', 'id');
            $streams = [];
            foreach ($query->cursor() as $record) {
                $collectionName = $collectionNames[$record->collection_id];
                if (! $this->needsRewrite($record->payload, $rotation->target_key_id) &&
                    ! ($collectionName === 'history' && ! $record->deleted && str_starts_with($record->record_id, 'h1:'))) {
                    continue;
                }
                $stream = $streams[$record->collection_id] ??= $this->journal->prepare($userId, $record->collection_id);
                $value = ['collection' => $collectionName, 'generation' => $stream->generation,
                    'record' => ['id' => $record->record_id, 'revision' => (string) $record->version,
                        'payload' => $record->payload, 'deleted' => $record->deleted, 'ttl' => $record->ttl?->toIso8601String()]];
                $size = strlen(json_encode($value, JSON_THROW_ON_ERROR));
                if (count($records) >= $limit || ($records !== [] && $bytes + $size > SyncChangeJournal::MAX_PAGE_BYTES)) {
                    $hasMore = true;
                    break;
                }
                $records[] = $value;
                $bytes += $size;
                $after = $record->id;
            }

            return ['rotation_id' => $rotation->operation_id, 'records' => $records, 'has_more' => $hasMore,
                'next_cursor' => ! $hasMore ? null : Crypt::encryptString(json_encode([
                    'user' => (string) $userId, 'rotation' => $rotation->operation_id, 'after' => $after,
                ], JSON_THROW_ON_ERROR))];
        }, 5);
    }

    private function rotationProgress(int $userId): ?array
    {
        $rotation = DB::table('sync_key_rotations')->where('user_id', $userId)->first();
        if (! $rotation) {
            return null;
        }
        $live = 0;
        $historyId = DB::table('collections')->where('name', 'history')->value('id');
        foreach (Record::where('user_id', $userId)->select('payload', 'collection_id', 'record_id', 'deleted')->cursor() as $record) {
            $live += (int) ($this->needsRewrite($record->payload, $rotation->target_key_id) ||
                ($record->collection_id === $historyId && ! $record->deleted && str_starts_with($record->record_id, 'h1:')));
        }
        $journal = 0;
        foreach ($this->journalRecords($userId)->cursor() as $change) {
            $record = json_decode($change->record, true);
            if (($record['payload'] ?? '') !== '') {
                $journal += (int) $this->needsRewrite($record['payload'], $rotation->target_key_id);
            }
        }

        return ['operation_id' => $rotation->operation_id, 'target_key_id' => $rotation->target_key_id,
            'source_key_ids' => json_decode($rotation->source_key_ids, true, flags: JSON_THROW_ON_ERROR),
            'phase' => $rotation->phase, 'live_records' => $live, 'journal_records' => $journal];
    }

    private function journalRecords(int $userId): \Illuminate\Database\Query\Builder
    {
        return DB::table('sync_changes')->join('sync_streams', 'sync_streams.id', '=', 'sync_changes.stream_id')
            ->where('sync_streams.user_id', $userId)->select('sync_changes.record');
    }

    private function needsRewrite(string $payload, string $targetKeyId): bool
    {
        $value = json_decode($payload, true);

        return ! is_array($value) || ($value['key_id'] ?? null) !== $targetKeyId || ($value['context']['schema_version'] ?? null) !== 2;
    }

    private function referencesKey(string $payload, string $keyId): bool
    {
        if ($payload === '') {
            return false;
        }
        $referencedId = $this->envelope->recordKeyId($payload);

        return $referencedId === null || $referencedId === $keyId;
    }

    public function requireLegacyWriter(int $userId): void
    {
        $this->requireTransaction();
        $state = DB::table('sync_crypto_states')->where('user_id', $userId)->first();
        if ($state?->native_enabled) {
            throw new SyncProtocolException('client_upgrade_required', 426);
        }
        if ($state?->legacy_frozen) {
            throw new SyncProtocolException('legacy_migration_frozen', 409);
        }
    }

    public function validateNewSession(User $user, int $protocolVersion, ?int $deviceId): void
    {
        $this->requireTransaction();
        if ($protocolVersion === 1) {
            $this->requireLegacyWriter($user->id);
        } elseif ($protocolVersion === 2) {
            $this->identity->forUser($user);
            if (! $deviceId || ! Device::whereKey($deviceId)->where('user_id', $user->id)->exists()) {
                throw new SyncProtocolException('device_required', 409);
            }
        } else {
            throw new SyncProtocolException('unsupported_protocol', 422);
        }
    }

    public function authorizeReset(int $userId, array $context, bool $ownerRequest = false): void
    {
        $this->requireTransaction();
        $this->requireSession($userId, $context['session_id']);
        $this->requireRevision($this->state($userId), $context);
        if (! $ownerRequest && $this->state($userId)->custody !== 'server') {
            throw new SyncProtocolException('authenticated_control_required', 409);
        }
    }

    public function authorizeWrite(int $userId, ?array $context, ?int $deviceId = null): ?object
    {
        $this->requireTransaction();
        $state = DB::table('sync_crypto_states')->where('user_id', $userId)->first();
        if (! $state?->native_enabled) {
            if ($context !== null) {
                throw new SyncProtocolException('native_keys_required', 409);
            }

            $this->requireLegacyWriter($userId);

            return null;
        }
        if ($context === null) {
            throw new SyncProtocolException('client_upgrade_required', 426);
        }
        $session = $this->requireSession($userId, $context['session_id']);
        if ($deviceId !== null && $session->device_id !== $deviceId) {
            throw new SyncProtocolException('device_required', 409);
        }
        $this->requireRevision($state, $context);
        if ($state->active_key_id === null) {
            throw new SyncProtocolException('native_keys_required', 409);
        }

        return $state;
    }

    public function reset(int $userId): void
    {
        $this->requireTransaction();
        DB::table('sync_server_recovery')->where('user_id', $userId)->delete();
        DB::table('sync_key_rotations')->where('user_id', $userId)->delete();
        DB::table('sync_history_id_migrations')->where('user_id', $userId)->delete();
        DB::table('sync_controls')->where('user_id', $userId)->delete();
        DB::table('sync_native_keys')->where('user_id', $userId)->delete();
        DB::table('sync_device_enrollments')->where('user_id', $userId)->delete();
        DB::table('sync_password_backups')->where('user_id', $userId)->delete();
        DB::table('sync_crypto_states')->where('user_id', $userId)->update([
            'epoch' => (string) Str::uuid(), 'revision' => 0, 'active_key_id' => null,
            'legacy_frozen' => false, 'legacy_fingerprint' => null,
            'identity_key_id' => null, 'identity_bundle' => null, 'registry_auth' => null, 'registry_auth_version' => 1,
        ]);
    }

    private function state(int $userId): object
    {
        $query = DB::table('sync_crypto_states')->where('user_id', $userId);
        if (! $query->exists()) {
            $query->insert(['user_id' => $userId, 'epoch' => (string) Str::uuid()]);
        }

        return $query->first();
    }

    private function requireSession(int $userId, string $sessionId): SyncSession
    {
        return $this->journal->requireSession($userId, $sessionId);
    }

    private function requireRevision(object $state, array $context): void
    {
        if ($context['epoch'] !== $state->epoch || $context['revision'] !== (string) $state->revision) {
            throw new SyncProtocolException('crypto_state_conflict', 409);
        }
        if ($state->revision >= self::MAX_REVISION) {
            throw new SyncProtocolException('key_version_exhausted', 409);
        }
    }

    private function insertKey(int $userId, string $keyId, string $bundle): void
    {
        $keys = DB::table('sync_native_keys')->where('user_id', $userId);
        if ((clone $keys)->where('key_id', $keyId)->exists()) {
            throw new SyncProtocolException('key_id_exists', 409);
        }
        if ($keys->count() >= self::MAX_KEYS) {
            throw new SyncProtocolException('key_capacity_reached', 409);
        }
        DB::table('sync_native_keys')->insert([
            'user_id' => $userId, 'key_id' => $keyId, 'encrypted_bundle' => $bundle,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function validateIdentityKey(?array $key, array $dataKeyIds, ?string $existingId = null): void
    {
        if ($key === null || ! isset($key['key_id'], $key['encrypted_bundle']) ||
            in_array($key['key_id'], $dataKeyIds, true) || ($existingId !== null && $key['key_id'] !== $existingId)) {
            throw new SyncProtocolException('invalid_identity_key', 422);
        }
        $this->envelope->validateBundle($key['key_id'], $key['encrypted_bundle']);
    }

    private function requireRegistryVersion(?object $state, int $version): void
    {
        if (! in_array($version, [1, 2], true)) {
            throw new SyncProtocolException('unsupported_registry_auth_version', 422);
        }
        if ($state && $version < $state->registry_auth_version) {
            throw new SyncProtocolException('registry_auth_downgrade', 409);
        }
    }

    private function validateRegistryAuth(string $value): void
    {
        if (! preg_match('/^[0-9a-f]{64}$/D', $value)) {
            throw new SyncProtocolException('invalid_registry_auth', 422);
        }
    }

    private function incompatibleSessions(int $userId): Builder
    {
        return SyncSession::where('user_id', $userId)->valid()->where('protocol_version', '<>', 2);
    }

    private function format(object $state): array
    {
        return [
            'crypto_state_version' => 1, 'epoch' => $state->epoch, 'revision' => (string) $state->revision,
            'mode' => $state->native_enabled ? 'native' : 'legacy', 'active_key_id' => $state->active_key_id,
            'custody' => $state->custody,
            'password_unlock' => $this->currentPasswordBackup($state)->exists(),
            'registry_auth' => $state->registry_auth, 'registry_auth_version' => (int) $state->registry_auth_version,
            'identity_key' => $state->identity_key_id === null ? null : [
                'key_id' => $state->identity_key_id, 'encrypted_bundle' => $state->identity_bundle,
            ],
            'rotation' => $this->rotationProgress($state->user_id),
            'legacy_frozen' => (bool) $state->legacy_frozen,
            'incompatible_sessions' => $this->incompatibleSessions($state->user_id)->count(),
            'migration_required' => $this->migrationRequired($state),
            'server_recovery' => DB::table('sync_server_recovery')->where('user_id', $state->user_id)
                ->where('epoch', $state->epoch)->exists(),
            'keys' => DB::table('sync_native_keys')->where('user_id', $state->user_id)->orderBy('created_at')->orderBy('key_id')
                ->get(['key_id', 'encrypted_bundle'])->map(fn ($key) => (array) $key)->all(),
        ];
    }

    private function migrationRequired(object $state): bool
    {
        return $state->active_key_id === null && (Record::where('user_id', $state->user_id)->exists() ||
            CryptoKeyBundle::where('user_id', $state->user_id)->exists() ||
            DB::table('sync_changes')->join('sync_streams', 'sync_streams.id', '=', 'sync_changes.stream_id')
                ->where('sync_streams.user_id', $state->user_id)->exists());
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Native crypto mutations require an account transaction');
        }
    }
}
