<?php

$scope = ['https://sync.example.invalid/', 'https://accounts.example.invalid/', 'fixture-usuario-ñ'];
$secret = str_repeat(chr(37), 32);
$salt = hex2bin('101112131415161718191a1b1c1d1e1f');
$nonce = hex2bin('000102030405060708090a0b0c0d0e0f1011121314151617');
$field = fn (string $value) => pack('N', strlen($value)).$value;
$json = fn ($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$derived = sodium_crypto_pwhash(32, $secret, $salt, 3, 67108864, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
$wrappingKey = sodium_crypto_kdf_derive_from_key(32, 0, 'MSPv2key', $derived);
$bundle = function (string $id, string $master) use ($scope, $field, $salt, $nonce, $wrappingKey, $json): array {
    $aad = "MidoriSync\0bundle\0".pack('N', 2).implode('', array_map($field, $scope)).$field($id)
        .$field('argon2id13').pack('N3', 65536, 3, 1).$field($salt);

    return ['key_id' => $id, 'encrypted_bundle' => $json([
        'bundle_version' => 2, 'crypto_version' => 2, 'key_id' => $id,
        'kdf' => ['algorithm' => 'argon2id13', 'memory_kib' => 65536, 'iterations' => 3, 'parallelism' => 1],
        'salt' => base64_encode($salt), 'nonce' => base64_encode($nonce),
        'ciphertext' => base64_encode(sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($master, $aad, $nonce, $wrappingKey)),
    ])];
};
$data = $bundle(sodium_bin2base64(str_repeat(chr(17), 16), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING), str_repeat(chr(19), 32));
$identityMaster = str_repeat(chr(23), 32);
$identity = $bundle(sodium_bin2base64(str_repeat(chr(29), 16), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING), $identityMaster);
$state = ['crypto_state_version' => 1, 'epoch' => '00000000-0000-4000-8000-000000000002', 'revision' => '7',
    'mode' => 'native', 'active_key_id' => $data['key_id'], 'keys' => [$data], 'incompatible_sessions' => 0,
    'migration_required' => false, 'server_recovery' => false, 'custody' => 'client',
    'identity_key' => $identity, 'registry_auth_version' => 2, 'rotation' => null];
$canonical = $json(['MidoriSync/key-registry/v2', $state['epoch'], $state['revision'],
    $state['active_key_id'], $identity, [$data], 'client', false, null]);
$message = "MidoriSync\0identity\0".pack('N', 1).implode('', array_map($field, $scope)).$field('devices').$field($canonical);
$identityKey = sodium_crypto_kdf_derive_from_key(32, 7, 'MSPv2ids', $identityMaster);
$state['registry_auth'] = bin2hex(sodium_crypto_generichash($message, $identityKey, 32));
echo json_encode(['generator' => 'PHP sodium; fixed public test keys and nonces, never user data',
    'scope' => $scope, 'local_secret' => bin2hex($secret), 'state' => $state, 'canonical' => $canonical],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
