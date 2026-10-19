<?php

namespace App\Services;

use App\Exceptions\SyncProtocolException;

class SyncNativeEnvelope
{
    public const COLLECTIONS = ['bookmarks', 'history', 'tabs', 'browser-settings', 'midori-tab', 'midori-privacy', 'devices', 'passwords', 'credit-cards', 'form-history', 'addresses'];

    /**
     * Highest client plaintext schema generation each collection documents. The server only stores
     * ciphertext; this lets clients negotiate before writing fields an older client would quarantine.
     */
    public const DATA_SCHEMAS = [
        'bookmarks' => 2, 'history' => 2, 'tabs' => 2, 'browser-settings' => 1, 'passwords' => 1,
        'credit-cards' => 1, 'form-history' => 1, 'addresses' => 1,
    ];

    public function supportsCollection(string $name): bool
    {
        return in_array($name, self::COLLECTIONS, true);
    }

    public function validateBundle(string $keyId, string $bundle): void
    {
        $value = $this->decode($bundle, 2048);
        if (! $this->fields($value, ['bundle_version', 'crypto_version', 'key_id', 'kdf', 'salt', 'nonce', 'ciphertext']) ||
            $value['bundle_version'] !== 2 || $value['crypto_version'] !== 2 || $value['key_id'] !== $keyId ||
            ! $this->keyId($keyId) ||
            ! $this->fields($value['kdf'], ['algorithm', 'memory_kib', 'iterations', 'parallelism']) ||
            $value['kdf']['algorithm'] !== 'argon2id13' || $value['kdf']['memory_kib'] !== 65536 ||
            $value['kdf']['iterations'] !== 3 || $value['kdf']['parallelism'] !== 1 ||
            ! $this->base64($value['salt'], 16, 16) || ! $this->base64($value['nonce'], 24, 24) ||
            ! $this->base64($value['ciphertext'], 48, 48)) {
            throw new SyncProtocolException('invalid_native_bundle', 422);
        }
    }

    public function validRecord(array $operation, string $collection, string $generation, string $keyId, int $schema = 1): bool
    {
        if ($operation['deleted'] && $schema === 1) {
            return true;
        }
        $value = $this->decode($operation['payload'], 262144);

        return $this->fields($value, ['crypto_version', 'key_id', 'purpose', 'context', 'nonce', 'ciphertext']) &&
            $value['crypto_version'] === 2 && $value['key_id'] === $keyId && $value['purpose'] === 'record' &&
            $this->fields($value['context'], ['collection', 'id', 'generation', 'schema_version', 'base_revision']) &&
            $this->supportsCollection($collection) &&
            $value['context']['collection'] === $collection && $value['context']['id'] === $operation['id'] &&
            strlen($operation['id']) <= 255 && ! preg_match('/[\p{Cc}\p{Cs}]/u', $operation['id']) &&
            $value['context']['generation'] === $generation && $value['context']['schema_version'] === $schema &&
            $value['context']['base_revision'] === $operation['base_revision'] &&
            $this->base64($value['nonce'], 24, 24) && $this->base64($value['ciphertext'], 16, 190016);
    }

    public function recordKeyId(string $payload): ?string
    {
        $value = $this->decode($payload, 262144);
        $context = is_array($value) ? ($value['context'] ?? null) : null;
        if (! is_array($context) || ! is_string($value['key_id'] ?? null) || ! $this->keyId($value['key_id']) ||
            ! is_string($context['collection'] ?? null) || ! is_string($context['generation'] ?? null) ||
            ! is_string($context['id'] ?? null) || ! is_string($context['base_revision'] ?? null) ||
            ! in_array($context['schema_version'] ?? null, [1, 2], true)) {
            return null;
        }
        $operation = ['id' => $context['id'], 'base_revision' => $context['base_revision'], 'deleted' => false, 'payload' => $payload];

        return $this->validRecord($operation, $context['collection'], $context['generation'], $value['key_id'], $context['schema_version'])
            ? $value['key_id'] : null;
    }

    private function decode(string $value, int $maximum): mixed
    {
        if (strlen($value) > $maximum) {
            return null;
        }
        try {
            return json_decode($value, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    private function fields(mixed $value, array $fields): bool
    {
        return is_array($value) && count($value) === count($fields) && array_diff($fields, array_keys($value)) === [];
    }

    private function base64(mixed $value, int $minimum, int $maximum): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $bytes = base64_decode($value, true);

        return $bytes !== false && strlen($bytes) >= $minimum && strlen($bytes) <= $maximum && base64_encode($bytes) === $value;
    }

    private function keyId(string $value): bool
    {
        if (! preg_match('/^[A-Za-z0-9_-]{22}$/D', $value)) {
            return false;
        }
        $bytes = base64_decode(strtr($value, '-_', '+/').'==', true);

        return $bytes !== false && strlen($bytes) === 16 && rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=') === $value;
    }
}
