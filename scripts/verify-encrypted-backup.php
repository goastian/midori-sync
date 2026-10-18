<?php

use App\Models\Device;
use App\Models\Record;
use App\Models\SyncSession;
use App\Models\User;
use App\Services\SyncAuthService;
use App\Services\SyncChangeJournal;
use App\Services\SyncIdentityService;
use App\Services\SyncNativeCryptoService;
use App\Services\SyncStorageService;
use Database\Seeders\CollectionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
umask(0077);
$originalConnection = config('database.default');
$databaseConfig = DB::connection()->getConfig();
if ($databaseConfig['driver'] !== 'pgsql' || ! extension_loaded('sodium')) {
    throw new RuntimeException('This synthetic restore check requires PostgreSQL and sodium.');
}
$run = bin2hex(random_bytes(8));
$names = ['source' => 'midori_sync_test_backup_'.$run, 'restore' => 'midori_sync_test_restore_'.$run];
$private = sys_get_temp_dir().'/midori-sync-backup-'.$run;
mkdir($private, 0700);
$artifact = storage_path('../artifacts/h1-encrypted-backup-'.$run.'.aesgcm');
$reportPath = storage_path('../artifacts/h1-encrypted-backup-'.$run.'.json');
$created = [];
$stage = 'prepare';
$secrets = [];
$originalCrypt = Crypt::getFacadeRoot();
$check = function (bool $condition, string $label): void {
    if (! $condition) {
        throw new RuntimeException($label);
    }
};
$select = function (string $name) use ($databaseConfig): void {
    config(['database.connections.backup_check' => array_replace($databaseConfig, ['name' => 'backup_check', 'url' => null, 'database' => $name]), 'database.default' => 'backup_check']);
    DB::purge('backup_check');
    if (DB::connection()->selectOne('select current_database() as name')->name !== $name) {
        throw new RuntimeException('Synthetic database selection failed.');
    }
};
$process = function (array $command, string $database) use ($databaseConfig, $check): void {
    $runner = new Process($command, env: ['PGHOST' => (string) $databaseConfig['host'], 'PGPORT' => (string) $databaseConfig['port'],
        'PGUSER' => $databaseConfig['username'], 'PGPASSWORD' => $databaseConfig['password'], 'PGDATABASE' => $database,
        'PGSSLMODE' => $databaseConfig['sslmode'] ?? 'prefer']);
    $runner->setTimeout(120);
    $runner->run();
    $check($runner->isSuccessful(), 'PostgreSQL child process failed; credentials and output intentionally omitted.');
};
$field = fn (string $value) => pack('N', strlen($value)).$value;
$packJson = fn ($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$issuer = app(SyncIdentityService::class)->configuredIssuer();
$scope = ['https://backup-proof.invalid/', $issuer, 'synthetic-backup-'.$run];
$prefix = fn (string $purpose, string $id) => "MidoriSync\0{$purpose}\0".pack('N', 2).implode('', array_map($field, $scope)).$field($id);
$bundleKey = function (string $code, string $salt): string {
    $derived = sodium_crypto_pwhash(32, $code, $salt, 3, 67108864, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    $key = sodium_crypto_kdf_derive_from_key(32, 0, 'MSPv2key', $derived);
    sodium_memzero($derived);

    return $key;
};
$bundleAad = fn (string $id, string $salt) => $prefix('bundle', $id).$field('argon2id13').pack('N3', 65536, 3, 1).$field($salt);
$wrap = function (string $master, string $id, string $code) use ($bundleKey, $bundleAad, $packJson): array {
    $salt = random_bytes(16);
    $nonce = random_bytes(24);
    $key = $bundleKey($code, $salt);
    $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($master, $bundleAad($id, $salt), $nonce, $key);
    sodium_memzero($key);

    return ['key_id' => $id, 'encrypted_bundle' => $packJson(['bundle_version' => 2, 'crypto_version' => 2, 'key_id' => $id,
        'kdf' => ['algorithm' => 'argon2id13', 'memory_kib' => 65536, 'iterations' => 3, 'parallelism' => 1],
        'salt' => base64_encode($salt), 'nonce' => base64_encode($nonce), 'ciphertext' => base64_encode($ciphertext)])];
};
$unwrap = function (string $bundle, string $code) use ($bundleKey, $bundleAad): string|false {
    $data = json_decode($bundle, true, flags: JSON_THROW_ON_ERROR);
    $salt = base64_decode($data['salt'], true);
    $key = $bundleKey($code, $salt);
    $master = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(base64_decode($data['ciphertext'], true),
        $bundleAad($data['key_id'], $salt), base64_decode($data['nonce'], true), $key);
    sodium_memzero($key);

    return $master;
};
$authenticateRegistry = function (array $state, string $identity) use ($packJson, $field, $scope): string {
    $keys = $state['keys'];
    usort($keys, fn ($left, $right) => strcmp($left['key_id'], $right['key_id']));
    $registry = $packJson(['MidoriSync/key-registry/v2', $state['epoch'], $state['revision'],
        $state['active_key_id'], $state['identity_key'], $keys, $state['custody'], $state['server_recovery'], null]);
    $subkey = sodium_crypto_kdf_derive_from_key(32, 7, 'MSPv2ids', $identity);
    try {
        return bin2hex(sodium_crypto_generichash("MidoriSync\0identity\0".pack('N', 1).implode('', array_map($field, $scope)).$field('devices').$field($registry), $subkey, 32));
    } finally {
        sodium_memzero($subkey);
    }
};
try {
    $admin = DB::connection($originalConnection);
    foreach ($names as $name) {
        $check((bool) preg_match('/^midori_sync_test_(backup|restore)_[0-9a-f]{16}$/D', $name), 'Unsafe database name.');
        $admin->statement('CREATE DATABASE "'.$name.'" TEMPLATE template0');
        $created[] = $name;
    }
    $stage = 'migrate_synthetic_source';
    $select($names['source']);
    $check(! $app->configurationIsCached(), 'Synthetic migration requires uncached environment configuration.');
    $migration = new Process([PHP_BINARY, 'artisan', 'migrate', '--force'], base_path(),
        ['DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $names['source'], 'DB_URL' => '', 'APP_ENV' => 'testing']);
    $migration->setTimeout(120);
    $migration->run();
    $check($migration->isSuccessful(), 'Synthetic migrations failed.');
    (new CollectionSeeder)->run();
    $stage = 'seed_client_custody';
    $user = User::create(['name' => 'Synthetic backup proof', 'email' => 'backup.'.$run.'@local.invalid',
        'authentik_id' => $scope[2], 'authentik_issuer' => $issuer]);
    $device = Device::create(['user_id' => $user->id, 'device_id' => (string) Str::uuid(), 'name' => 'Synthetic Linux device', 'type' => 'desktop', 'os' => 'linux']);
    $session = app(SyncAuthService::class)->createSessionToken($user, $device->id);
    $sessionId = SyncSession::where('token_hash', hash('sha256', $session['token']))->value('id');
    sodium_memzero($session['token']);
    $crypto = app(SyncNativeCryptoService::class);
    $state = $crypto->snapshot($user->id, $sessionId);
    $secrets['recovery'] = bin2hex(random_bytes(32));
    $secrets['data'] = random_bytes(32);
    $secrets['identity'] = random_bytes(32);
    $keyId = sodium_bin2base64(random_bytes(16), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    $identityId = sodium_bin2base64(random_bytes(16), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    $dataBundle = $wrap($secrets['data'], $keyId, $secrets['recovery']);
    $identityBundle = $wrap($secrets['identity'], $identityId, $secrets['recovery']);
    $registryAuth = $authenticateRegistry(['epoch' => $state['epoch'], 'revision' => (string) ((int) $state['revision'] + 1),
        'active_key_id' => $keyId, 'identity_key' => $identityBundle, 'keys' => [$dataBundle],
        'custody' => 'client', 'server_recovery' => false], $secrets['identity']);
    $state = $crypto->activate($user->id, ['epoch' => $state['epoch'], 'revision' => $state['revision'], 'session_id' => $sessionId],
        $keyId, $dataBundle['encrypted_bundle'], false, $identityBundle, $registryAuth, 2);
    $check($state['registry_auth_version'] === 2 && hash_equals($state['registry_auth'], $authenticateRegistry($state, $secrets['identity'])), 'Revision-authenticated registry activation failed.');
    $collectionId = DB::table('collections')->where('name', 'addresses')->value('id');
    $generation = DB::transaction(function () use ($user, $collectionId) {
        $journal = app(SyncChangeJournal::class);
        $journal->lockUser($user->id);

        return $journal->prepare($user->id, $collectionId)->generation;
    });
    $id = 'synthetic-'.$run;
    $plaintext = $packJson(['version' => 2, 'deleted' => false, 'ttl' => null, 'value' => ['version' => 1, 'fields' => ['name' => 'Synthetic Example', 'street-address' => 'Synthetic Street 42']]]);
    $aad = $prefix('record', $keyId).pack('N', 2).$field('addresses').$field($id).$field($generation).$field('0');
    $nonce = random_bytes(24);
    $subkey = sodium_crypto_kdf_derive_from_key(32, 13, 'MSPv2rec', $secrets['data']);
    $recordCiphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $aad, $nonce, $subkey);
    sodium_memzero($subkey);
    $payload = $packJson(['crypto_version' => 2, 'key_id' => $keyId, 'purpose' => 'record',
        'context' => ['collection' => 'addresses', 'id' => $id, 'generation' => $generation, 'schema_version' => 2, 'base_revision' => '0'],
        'nonce' => base64_encode($nonce), 'ciphertext' => base64_encode($recordCiphertext)]);
    $result = app(SyncStorageService::class)->applyOperations($user->id, 'addresses', $device->id, $generation,
        [['operation_id' => (string) Str::uuid(), 'id' => $id, 'base_revision' => '0', 'payload' => $payload, 'deleted' => false]],
        ['epoch' => $state['epoch'], 'revision' => $state['revision'], 'session_id' => $sessionId]);
    $check($result['results'][0]['status'] === 'applied', 'Synthetic encrypted write failed.');
    $stage = 'dump_encrypt_restore';
    $dump = $private.'/source.sql';
    $process(['pg_dump', '--no-owner', '--no-acl', '--file='.$dump], $names['source']);
    chmod($dump, 0600);
    $sql = file_get_contents($dump);
    $check(! str_contains($sql, $secrets['recovery']) && ! str_contains($sql, 'Synthetic Street 42'), 'Client plaintext leaked into SQL.');
    $secrets['backup'] = random_bytes(32);
    file_put_contents($private.'/backup.key', $secrets['backup']);
    chmod($private.'/backup.key', 0600);
    $iv = random_bytes(12);
    $backupAad = 'MidoriSync/synthetic-backup/v1';
    $encrypted = openssl_encrypt($sql, 'aes-256-gcm', $secrets['backup'], OPENSSL_RAW_DATA, $iv, $tag, $backupAad, 16);
    $check(is_string($encrypted), 'Backup encryption failed.');
    file_put_contents($artifact, 'MSB1'.$iv.$tag.$encrypted);
    chmod($artifact, 0600);
    $tampered = $encrypted;
    $tampered[0] = chr(ord($tampered[0]) ^ 1);
    $check(openssl_decrypt($tampered, 'aes-256-gcm', $secrets['backup'], OPENSSL_RAW_DATA, $iv, $tag, $backupAad) === false, 'Tampered backup accepted.');
    $check(openssl_decrypt($encrypted, 'aes-256-gcm', $originalCrypt->getKey(), OPENSSL_RAW_DATA, $iv, $tag, $backupAad) === false, 'Application key opened the backup.');
    $decrypted = openssl_decrypt($encrypted, 'aes-256-gcm', file_get_contents($private.'/backup.key'), OPENSSL_RAW_DATA, $iv, $tag, $backupAad);
    $check(is_string($decrypted) && hash_equals(hash('sha256', $sql), hash('sha256', $decrypted)), 'Backup round trip mismatch.');
    file_put_contents($private.'/restore.sql', $decrypted);
    chmod($private.'/restore.sql', 0600);
    $process(['psql', '--no-psqlrc', '--set=ON_ERROR_STOP=1', '--file='.$private.'/restore.sql'], $names['restore']);
    sodium_memzero($sql);
    sodium_memzero($decrypted);
    $select($names['restore']);
    $stage = 'verify_restored_custody_and_recovery';
    $restored = $crypto->snapshot($user->id, $sessionId);
    $check($restored === $state, 'Restored crypto registry changed.');
    $check($restored['registry_auth_version'] === 2, 'Restore downgraded registry authentication.');
    $check($restored['custody'] === 'client' && ! $restored['server_recovery'] && DB::table('sync_server_recovery')->count() === 0, 'Restore reactivated server custody.');
    $restoredPayload = Record::where('record_id', $id)->value('payload');
    $check(hash_equals($payload, $restoredPayload), 'Restored payload changed.');
    $bundle = $restored['keys'][0]['encrypted_bundle'];
    foreach ([$originalCrypt->getKey(), bin2hex(random_bytes(32))] as $wrongCode) {
        $check($unwrap($bundle, $wrongCode) === false, 'A server-held or unrelated secret opened the client bundle.');
        sodium_memzero($wrongCode);
    }
    $secrets['recovered'] = $unwrap($bundle, $secrets['recovery']);
    $check(is_string($secrets['recovered']) && hash_equals($secrets['data'], $secrets['recovered']), 'Legitimate recovery failed.');
    $secrets['recovered_identity'] = $unwrap($restored['identity_key']['encrypted_bundle'], $secrets['recovery']);
    $check(is_string($secrets['recovered_identity']) && hash_equals($secrets['identity'], $secrets['recovered_identity']), 'Identity recovery failed.');
    $check(hash_equals($restored['registry_auth'], $authenticateRegistry($restored, $secrets['recovered_identity'])), 'Restored version-two registry authentication failed.');
    $inflated = array_replace($restored, ['revision' => (string) ((int) $restored['revision'] + 1)]);
    $check(! hash_equals($restored['registry_auth'], $authenticateRegistry($inflated, $secrets['recovered_identity'])), 'Registry revision tampering remained authenticated.');
    $subkey = sodium_crypto_kdf_derive_from_key(32, 13, 'MSPv2rec', $secrets['recovered']);
    $check(sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($recordCiphertext, $aad, $nonce, $subkey) === $plaintext, 'Recovered client key cannot read restored record.');
    sodium_memzero($subkey);
    $legacyAppCiphertext = $originalCrypt->encryptString('synthetic historical application ciphertext');
    $replacement = new Encrypter(random_bytes(32), 'AES-256-CBC');
    $replacement->previousKeys([$originalCrypt->getKey()]);
    $check($replacement->decryptString($legacyAppCiphertext) === 'synthetic historical application ciphertext', 'Previous application-key restore failed.');
    Crypt::swap($replacement);
    $check($crypto->snapshot($user->id, $sessionId) === $state, 'Application key rotation changed client custody.');
    $report = ['status' => 'passed', 'scope' => 'Synthetic PostgreSQL databases only; no production backup or credential export',
        'encryption' => 'AES-256-GCM; 256-bit independent random key; authenticated version AAD', 'archive' => basename($artifact),
        'archive_sha256' => hash_file('sha256', $artifact), 'backup_key_file' => $private.'/backup.key',
        'key_mode' => '0600, separate from archive and APP_KEY', 'restored_records' => 1, 'restored_collections' => DB::table('collections')->count(),
        'registry_auth_version' => $restored['registry_auth_version'], 'authenticated_crypto_revision' => $restored['revision'],
        'checks' => ['encrypted SQL archive restored through psql', 'tampering rejected', 'APP_KEY cannot decrypt archive or client bundle',
            'recovery code and native fields absent from SQL', 'client custody and registry preserved; escrow absent',
            'version-two registry MAC verified with recovered identity; inflated revision rejected',
            'unrelated recovery secret rejected; legitimate client recovery succeeded', 'previous application key can restore historical application ciphertext',
            'application key rotation preserves client custody'], 'databases_removed' => false, 'plaintext_removed' => false];
    $stage = 'cleanup';
} catch (Throwable $error) {
    fwrite(STDERR, 'FAILED stage='.$stage.' exception='.$error::class.' at '.basename($error->getFile()).':'.$error->getLine()."; details omitted to protect configuration.\n");
    if ($stage === 'migrate_synthetic_source' && $error->getPrevious() instanceof PDOException) {
        $reason = $error->getPrevious()->errorInfo[2] ?? 'No PostgreSQL detail';
        foreach (['password', 'username', 'database', 'host'] as $fieldName) {
            if (is_string($databaseConfig[$fieldName] ?? null) && $databaseConfig[$fieldName] !== '') {
                $reason = str_replace($databaseConfig[$fieldName], '[redacted]', $reason);
            }
        }
        fwrite(STDERR, $reason."\n");
    }
    $failed = true;
} finally {
    Crypt::swap($originalCrypt);
    DB::purge('backup_check');
    config(['database.default' => $originalConnection]);
    foreach (array_reverse($created) as $name) {
        DB::connection($originalConnection)->statement('DROP DATABASE "'.$name.'"');
    }
    foreach (['source.sql', 'restore.sql'] as $file) {
        if (is_file($private.'/'.$file)) {
            unlink($private.'/'.$file);
        }
    }
    foreach ($secrets as &$secret) {
        if (is_string($secret)) {
            sodium_memzero($secret);
        }
    }
    unset($secret);
}
if (isset($failed)) {
    exit(1);
}
$report['databases_removed'] = true;
$report['plaintext_removed'] = true;
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo 'PASS synthetic encrypted backup and PostgreSQL restore; report='.basename($reportPath)."\n";
