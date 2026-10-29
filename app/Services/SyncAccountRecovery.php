<?php

namespace App\Services;

use App\Exceptions\SyncProtocolException;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class SyncAccountRecovery
{
    public function __construct(private SyncNativeEnvelope $envelope, private SyncIdentityService $identity) {}

    public function available(object $state): bool
    {
        try {
            $this->read((int) $state->user_id, $state);

            return true;
        } catch (SyncProtocolException $error) {
            return false;
        }
    }

    public function store(int $userId, object $state, array $capsule): void
    {
        $this->validate($userId, $state, $capsule);
        $fingerprint = $this->fingerprint($state);
        $payload = json_encode(['user_id' => $userId, 'epoch' => $state->epoch,
            'registry_fingerprint' => $fingerprint, 'capsule' => $capsule], JSON_THROW_ON_ERROR);
        DB::table('sync_account_recovery')->updateOrInsert(['user_id' => $userId], [
            'epoch' => $state->epoch, 'registry_fingerprint' => $fingerprint,
            'encrypted_capsule' => Crypt::encryptString($payload), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function read(int $userId, object $state): array
    {
        $stored = $this->current($state)->first();
        if (! $stored) {
            throw new SyncProtocolException('account_recovery_pending', 409);
        }
        try {
            $payload = json_decode(Crypt::decryptString($stored->encrypted_capsule), true, 16, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException $error) {
            throw new SyncProtocolException('account_recovery_unavailable', 503);
        }
        if (! is_array($payload) || ($payload['user_id'] ?? null) !== $userId ||
            ($payload['epoch'] ?? null) !== $state->epoch ||
            ($payload['registry_fingerprint'] ?? null) !== $this->fingerprint($state) ||
            ! is_array($payload['capsule'] ?? null)) {
            throw new SyncProtocolException('account_recovery_unavailable', 503);
        }
        $this->validate($userId, $state, $payload['capsule']);

        return ['epoch' => $state->epoch, 'capsule' => $payload['capsule']];
    }

    private function validate(int $userId, object $state, array $capsule): void
    {
        $expected = ['version', 'scope', 'epoch', 'secret', 'keys', 'identity_key'];
        if (count($capsule) !== count($expected) || array_diff($expected, array_keys($capsule)) ||
            ($capsule['version'] ?? null) !== 1 || ($capsule['epoch'] ?? null) !== $state->epoch ||
            ! is_string($capsule['scope'] ?? null) || strlen($capsule['scope']) > 4096 ||
            ! is_string($capsule['secret'] ?? null) || ! preg_match('/^[0-9a-f]{64}$/D', $capsule['secret']) ||
            ! is_array($capsule['keys'] ?? null) || ! array_is_list($capsule['keys']) ||
            count($capsule['keys']) < 1 || count($capsule['keys']) > SyncNativeCryptoService::MAX_KEYS ||
            ! is_array($capsule['identity_key'] ?? null) ||
            ! $state->active_key_id || ! $state->identity_key_id || (int) $state->registry_auth_version !== 2) {
            throw new SyncProtocolException('invalid_account_capsule', 422);
        }
        try {
            $scope = json_decode($capsule['scope'], true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new SyncProtocolException('invalid_account_capsule', 422);
        }
        $identity = $this->identity->forUser(User::findOrFail($userId));
        if (! is_array($scope) || ! array_is_list($scope) || count($scope) !== 3 ||
            ! is_string($scope[0]) || strlen($scope[0]) > 2048 ||
            $scope[1] !== $identity['issuer'] || $scope[2] !== $identity['subject']) {
            throw new SyncProtocolException('account_capsule_identity_mismatch', 422);
        }
        $ids = [];
        foreach ([...$capsule['keys'], $capsule['identity_key']] as $key) {
            if (! is_array($key) || count($key) !== 2 || ! is_string($key['key_id'] ?? null) ||
                ! is_string($key['encrypted_bundle'] ?? null)) {
                throw new SyncProtocolException('invalid_account_capsule', 422);
            }
            $this->envelope->validateBundle($key['key_id'], $key['encrypted_bundle']);
            $ids[] = $key['key_id'];
        }
        $identityId = array_pop($ids);
        $expectedIds = DB::table('sync_native_keys')->where('user_id', $userId)->orderBy('key_id')->pluck('key_id')->all();
        sort($ids, SORT_STRING);
        sort($expectedIds, SORT_STRING);
        if ($ids !== $expectedIds || $identityId !== $state->identity_key_id) {
            throw new SyncProtocolException('account_capsule_registry_mismatch', 422);
        }
    }

    private function fingerprint(object $state): string
    {
        $ids = DB::table('sync_native_keys')->where('user_id', $state->user_id)->orderBy('key_id')->pluck('key_id')->all();
        sort($ids, SORT_STRING);

        return hash('sha256', json_encode([$state->active_key_id, $state->identity_key_id, $ids], JSON_THROW_ON_ERROR));
    }

    private function current(object $state): Builder
    {
        return DB::table('sync_account_recovery')->where('user_id', $state->user_id)->where('epoch', $state->epoch)
            ->where('registry_fingerprint', $this->fingerprint($state));
    }
}
