<?php

$fixture = json_decode(file_get_contents(__DIR__.'/../tests/Fixtures/native-sodium.json'), true, flags: JSON_THROW_ON_ERROR);
$master = base64_decode($fixture['master_key'], true);
$key = sodium_crypto_kdf_derive_from_key(32, 7, 'MSPv2ids', $master);
$field = fn (string $value) => pack('N', strlen($value)).$value;
$prefix = "MidoriSync\0identity\0".pack('N', 1).implode('', array_map($field, $fixture['scope'])).$field('devices');
$cases = [];
foreach (['history_clear' => 'history', 'collection_clear' => 'addresses'] as $kind => $collection) {
    $control = ['version' => 1, 'epoch' => '00000000-0000-4000-8000-000000000002', 'collection' => $collection,
        'operation_id' => '00000000-0000-4000-8000-000000000003',
        'previous_generation' => '00000000-0000-4000-8000-000000000004',
        'generation' => '00000000-0000-4000-8000-000000000005', 'control_revision' => '2',
        'kind' => $kind, 'clear_before_ms' => $kind === 'history_clear' ? 1792022400123 : 0];
    $canonical = json_encode(['MidoriSync/control/v1', $control['epoch'], $collection, $control['operation_id'],
        $control['previous_generation'], $control['generation'], $control['control_revision'], $kind, $control['clear_before_ms']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $message = $prefix.$field($canonical);
    $control['proof'] = bin2hex(sodium_crypto_generichash($message, $key, 32));
    $cases[] = ['control' => $control, 'canonical' => $canonical, 'message' => base64_encode($message)];
}
echo json_encode(['generator' => 'PHP sodium; public compatibility keys from native-sodium.json',
    'scope' => $fixture['scope'], 'master_key' => $fixture['master_key'], 'key_id' => $fixture['key_id'],
    'passphrase' => $fixture['passphrase'], 'bundle' => $fixture['bundle'], 'cases' => $cases],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
