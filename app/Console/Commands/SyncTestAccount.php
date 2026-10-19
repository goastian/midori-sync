<?php

namespace App\Console\Commands;

use App\Models\Record;
use App\Models\User;
use App\Services\SyncChangeJournal;
use App\Services\SyncIdentityService;
use App\Services\SyncPairingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SyncTestAccount extends Command
{
    protected $signature = 'sync:test-account {--output=/tmp/midori-sync-h1-fixture.json} {--base-url=http://127.0.0.1:8000} {--fresh} {--seed-legacy-deletion}';

    protected $description = 'Create or renew pairing codes for an isolated synthetic account on an existing local Sync instance';

    public function handle(SyncIdentityService $identity, SyncPairingService $pairing): int
    {
        $path = $this->option('output');
        $base = rtrim($this->option('base-url'), '/');
        if (! is_string($path) || realpath(dirname($path)) !== '/tmp' || is_link($path) ||
            ! preg_match('/^midori-sync-h1-[a-z0-9-]+\.json$/D', basename($path)) ||
            ! in_array($base, ['http://127.0.0.1:8000', 'http://localhost:8000'], true)) {
            throw new \RuntimeException('Use a midori-sync-h1-*.json file directly in /tmp and the existing loopback server on port 8000.');
        }
        $issuer = $identity->configuredIssuer();
        $capabilities = Http::timeout(5)->get($base.'/api/v1/capabilities')->throw()->json();
        if ($issuer === null || ($capabilities['authentication']['issuer'] ?? null) !== $issuer ||
            ($capabilities['protocol'] ?? null) !== 'MSP' || ($capabilities['crypto_custody_version'] ?? null) !== 1) {
            throw new \RuntimeException('The running backend does not match this checkout and configured issuer.');
        }
        $saved = ! $this->option('fresh') && is_file($path) ? json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR) : null;
        if ($this->option('seed-legacy-deletion') && ($saved === null || (fileperms($path) & 0777) !== 0600)) {
            throw new \RuntimeException('Legacy deletion seeding requires an existing private synthetic fixture; never combine it with --fresh.');
        }
        $fixture = DB::transaction(function () use ($saved, $base, $issuer, $pairing) {
            if ($saved !== null) {
                if (($saved['kind'] ?? null) !== 'h1-live' || ($saved['database'] ?? null) !== DB::connection()->getDatabaseName() ||
                    ! is_string($saved['subject'] ?? null) || ! str_starts_with($saved['subject'], 'synthetic-h1:')) {
                    throw new \RuntimeException('Existing fixture does not identify a synthetic account in this database.');
                }
                $user = User::whereKey($saved['user_id'])->where('authentik_id', $saved['subject'])
                    ->where('authentik_issuer', $issuer)->where('email', 'like', 'h1.%@local.invalid')->lockForUpdate()->firstOrFail();
            } else {
                $id = (string) Str::uuid();
                $user = User::create(['authentik_id' => 'synthetic-h1:'.$id, 'authentik_issuer' => $issuer,
                    'email' => 'h1.'.$id.'@local.invalid', 'name' => 'Synthetic H1 integration',
                    'storage_quota_bytes' => (int) config('services.sync.default_quota')]);
            }
            if ($this->option('seed-legacy-deletion')) {
                return array_replace($saved, ['legacyDeletion' => $this->seedLegacyDeletion($user)]);
            }
            $profiles = [];
            foreach (['A', 'B', 'C', 'D'] as $name) {
                $profiles[$name] = $pairing->generate($user);
            }

            return ['version' => 1, 'kind' => 'h1-live', 'baseURL' => $base.'/', 'profiles' => $profiles,
                'created_at' => now()->toISOString(), 'user_id' => (string) $user->id,
                'subject' => $user->authentik_id, 'database' => DB::connection()->getDatabaseName()];
        }, 5);
        $temporary = $path.'.'.bin2hex(random_bytes(8));
        $mask = umask(0077);
        try {
            $file = fopen($temporary, 'x');
            if ($file === false) {
                throw new \RuntimeException('Could not create the private fixture.');
            }
            try {
                $body = json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
                if (fwrite($file, $body) !== strlen($body) || ! fflush($file) || ! fsync($file)) {
                    throw new \RuntimeException('Could not persist the private fixture.');
                }
            } finally {
                fclose($file);
            }
            if (! rename($temporary, $path)) {
                throw new \RuntimeException('Could not install the private fixture.');
            }
        } finally {
            umask($mask);
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
        $this->info('Synthetic account fixture saved privately to '.$path.($this->option('seed-legacy-deletion')
            ? '; legacy deletion seeded without renewing pairing codes.'
            : '; pairing expires in '.config('services.sync.pairing_ttl').' seconds.'));
        $this->line('Runtime: '.app()->environment().'; database: '.DB::connection()->getDatabaseName().'; backend: '.$base);

        return self::SUCCESS;
    }

    private function seedLegacyDeletion(User $user): array
    {
        $state = DB::table('sync_crypto_states')->where('user_id', $user->id)->first();
        if (! $state || ! $state->native_enabled || $state->custody !== 'client' || $state->active_key_id === null ||
            DB::table('sync_key_rotations')->where('user_id', $user->id)->exists()) {
            throw new \RuntimeException('Seed only after synthetic activation and before its first rotation.');
        }
        $collectionId = DB::table('collections')->where('name', 'bookmarks')->value('id');
        $journal = app(SyncChangeJournal::class);
        $stream = $journal->prepare($user->id, $collectionId);
        $record = Record::where('user_id', $user->id)->where('collection_id', $collectionId)->where('record_id', 'zzH1Legacy01_')->first();
        if ($record && (! $record->deleted || $record->payload !== '' || $record->version !== 1)) {
            throw new \RuntimeException('Synthetic legacy deletion conflicts with existing data.');
        }
        if (! $record) {
            $record = Record::create(['user_id' => $user->id, 'collection_id' => $collectionId, 'record_id' => 'zzH1Legacy01_',
                'payload' => '', 'deleted' => true, 'ttl' => null, 'version' => 1, 'modified_at' => microtime(true)]);
            $journal->append($user->id, $collectionId, [$record]);
        }

        return ['collection' => 'bookmarks', 'id' => $record->record_id, 'generation' => $stream->generation, 'revision' => (string) $record->version];
    }
}
