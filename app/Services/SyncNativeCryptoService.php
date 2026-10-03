<?php

namespace App\Services;

use App\Exceptions\SyncProtocolException;
use App\Models\CryptoKeyBundle;
use App\Models\Device;
use App\Models\Record;
use App\Models\SyncSession;
use App\Models\User;
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

    public function activate(int $userId, array $context, string $keyId, string $bundle, bool $revokeIncompatible, ?string $recoverySecret = null): array
    {
        $this->envelope->validateBundle($keyId, $bundle);

        return DB::transaction(function () use ($userId, $context, $keyId, $bundle, $revokeIncompatible, $recoverySecret) {
            $this->journal->lockUser($userId);
            $this->requireSession($userId, $context['session_id']);
            $state = $this->state($userId);
            $this->requireRevision($state, $context);
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
            if ($recoverySecret !== null) {
                $this->storeRecoverySecret($userId, $state->epoch, $recoverySecret);
            }
            $this->journal->resetUser($userId);
            DB::table('sync_crypto_states')->where('user_id', $userId)->update([
                'native_enabled' => true, 'active_key_id' => $keyId, 'revision' => $state->revision + 1,
            ]);

            return $this->format($this->state($userId));
        }, 5);
    }

    public function recoverySecret(int $userId, string $sessionId): array
    {
        return DB::transaction(function () use ($userId, $sessionId) {
            $this->journal->lockUser($userId);
            $this->requireSession($userId, $sessionId);
            $state = $this->state($userId);
            $saved = DB::table('sync_server_recovery')->where('user_id', $userId)->where('epoch', $state->epoch)->first();
            if (! $state->active_key_id || ! $saved) {
                throw new SyncProtocolException('recovery_unavailable', 404);
            }

            return ['epoch' => $state->epoch, 'revision' => (string) $state->revision,
                'recovery_secret' => Crypt::decryptString($saved->encrypted_secret)];
        }, 5);
    }

    public function escrow(int $userId, array $context, array $keys, string $recoverySecret): array
    {
        return DB::transaction(function () use ($userId, $context, $keys, $recoverySecret) {
            $this->journal->lockUser($userId);
            $state = $this->authorizeWrite($userId, $context);
            $existing = DB::table('sync_native_keys')->where('user_id', $userId)->pluck('key_id')->all();
            $received = array_column($keys, 'key_id');
            sort($existing);
            sort($received);
            if (! $state || $existing !== $received) {
                throw new SyncProtocolException('crypto_state_conflict', 409);
            }
            foreach ($keys as $key) {
                $this->envelope->validateBundle($key['key_id'], $key['encrypted_bundle']);
                DB::table('sync_native_keys')->where('user_id', $userId)->where('key_id', $key['key_id'])
                    ->update(['encrypted_bundle' => $key['encrypted_bundle'], 'updated_at' => now()]);
            }
            $this->storeRecoverySecret($userId, $state->epoch, $recoverySecret);
            DB::table('sync_crypto_states')->where('user_id', $userId)->update(['revision' => $state->revision + 1]);

            return $this->format($this->state($userId));
        }, 5);
    }

    public function rotate(int $userId, array $context, string $keyId, string $bundle): array
    {
        $this->envelope->validateBundle($keyId, $bundle);

        return DB::transaction(function () use ($userId, $context, $keyId, $bundle) {
            $this->journal->lockUser($userId);
            $state = $this->authorizeWrite($userId, $context);
            if (! $state) {
                throw new SyncProtocolException('native_keys_required', 409);
            }
            $this->insertKey($userId, $keyId, $bundle);
            DB::table('sync_server_recovery')->where('user_id', $userId)->delete();
            DB::table('sync_crypto_states')->where('user_id', $userId)->update([
                'active_key_id' => $keyId, 'revision' => $state->revision + 1,
            ]);

            return $this->format($this->state($userId));
        }, 5);
    }

    public function rewrap(int $userId, array $context, string $keyId, string $bundle): array
    {
        $this->envelope->validateBundle($keyId, $bundle);

        return DB::transaction(function () use ($userId, $context, $keyId, $bundle) {
            $this->journal->lockUser($userId);
            $state = $this->authorizeWrite($userId, $context);
            $key = DB::table('sync_native_keys')->where('user_id', $userId)->where('key_id', $keyId);
            if (! $state || ! $key->exists()) {
                throw new SyncProtocolException('unknown_key', 404);
            }
            $key->update(['encrypted_bundle' => $bundle, 'updated_at' => now()]);
            DB::table('sync_server_recovery')->where('user_id', $userId)->delete();
            DB::table('sync_crypto_states')->where('user_id', $userId)->update(['revision' => $state->revision + 1]);

            return $this->format($this->state($userId));
        }, 5);
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

    public function authorizeReset(int $userId, array $context): void
    {
        $this->requireTransaction();
        $this->requireSession($userId, $context['session_id']);
        $this->requireRevision($this->state($userId), $context);
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
        DB::table('sync_native_keys')->where('user_id', $userId)->delete();
        DB::table('sync_crypto_states')->where('user_id', $userId)->update([
            'epoch' => (string) Str::uuid(), 'revision' => 0, 'active_key_id' => null,
            'legacy_frozen' => false, 'legacy_fingerprint' => null,
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
        $session = SyncSession::whereKey($sessionId)->where('user_id', $userId)->valid()->lockForUpdate()->first();
        if (! $session) {
            throw new SyncProtocolException('auth_required', 401);
        }
        if ($session->protocol_version !== 2) {
            throw new SyncProtocolException('client_upgrade_required', 426);
        }
        if (! $session->device_id || ! Device::whereKey($session->device_id)->where('user_id', $userId)->exists()) {
            throw new SyncProtocolException('device_required', 409);
        }
        $this->identity->forUser(User::findOrFail($userId));

        return $session;
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

    private function storeRecoverySecret(int $userId, string $epoch, string $secret): void
    {
        DB::table('sync_server_recovery')->updateOrInsert(['user_id' => $userId], [
            'epoch' => $epoch, 'encrypted_secret' => Crypt::encryptString($secret),
            'created_at' => now(), 'updated_at' => now(),
        ]);
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
