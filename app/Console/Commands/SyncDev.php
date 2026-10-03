<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\SyncIdentityService;
use App\Services\SyncPairingService;
use Database\Seeders\CollectionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncDev extends Command
{
    protected $signature = 'sync:dev {action=prepare : prepare or pair} {--create-database : Create an isolated PostgreSQL database if absent} {--json : Emit pairing data as JSON} {--fixture= : Separate synthetic account name for integration tests}';

    protected $description = 'Prepare PostgreSQL and synthetic pairing codes for isolated native Sync development';

    public function handle(SyncPairingService $pairing): int
    {
        $connection = config('database.connections.pgsql');
        $database = $connection['database'];
        $host = $connection['host'];
        if (! app()->environment(['local', 'testing']) || ! config('services.sync.local_dev')
            || config('database.default') !== 'pgsql' || ! empty($connection['url'])
            || ! preg_match('/^midori_sync_(dev|test)(_[a-z0-9]+)*$/D', $database)
            || ! (in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_starts_with($host, '/'))) {
            $this->error('Use explicit local/testing mode, SYNC_LOCAL_DEV=true and an isolated local PostgreSQL database named midori_sync_dev_* or midori_sync_test_*.');

            return self::FAILURE;
        }
        if (! in_array($this->argument('action'), ['prepare', 'pair'], true)) {
            $this->error('Action must be prepare or pair.');

            return self::FAILURE;
        }

        $fixture = $this->option('fixture');
        if ($fixture !== null && ! preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $fixture)) {
            $this->error('The synthetic fixture name must be lowercase ASCII and at most 32 characters.');

            return self::FAILURE;
        }
        $subject = 'midori-local-development'.($fixture === null ? '' : '-'.$fixture);
        $email = $fixture === null ? 'midori@local.invalid' : 'midori-'.$fixture.'@local.invalid';

        if ($this->argument('action') === 'prepare') {
            if ($this->option('create-database')) {
                config(['database.connections.sync_dev_admin' => array_replace($connection, ['database' => 'postgres'])]);
                $admin = DB::connection('sync_dev_admin');
                try {
                    if (! $admin->selectOne('SELECT 1 FROM pg_database WHERE datname = ?', [$database])) {
                        $admin->statement('CREATE DATABASE "'.$database.'"');
                    }
                } finally {
                    DB::purge('sync_dev_admin');
                }
            }
            $call = $this->option('json') ? 'callSilently' : 'call';
            if ($this->{$call}('migrate', ['--force' => true]) !== self::SUCCESS) {
                return self::FAILURE;
            }
            if ($this->{$call}('db:seed', ['--class' => CollectionSeeder::class, '--force' => true]) !== self::SUCCESS) {
                return self::FAILURE;
            }
            $user = User::firstOrCreate(['authentik_id' => $subject], [
                'name' => 'Midori Local', 'email' => $email,
                'authentik_issuer' => SyncIdentityService::DEVELOPMENT_ISSUER,
                'storage_quota_bytes' => (int) config('services.sync.default_quota'),
            ]);
            if ($user->authentik_issuer === null) {
                $user->update(['authentik_issuer' => SyncIdentityService::DEVELOPMENT_ISSUER]);
            }
        }

        $user = User::where('authentik_id', $subject)->first();
        if (! $user || $user->authentik_issuer !== SyncIdentityService::DEVELOPMENT_ISSUER) {
            $this->error('Prepare the development database before requesting another pairing code.');

            return self::FAILURE;
        }
        DB::table('sync_pairing_codes')->where('expires_at', '<=', now())->delete();
        $codes = [];
        foreach (['A', 'B'] as $profile) {
            $code = $pairing->generate($user);
            $codes[$profile] = $code;
            if (! $this->option('json')) {
                $this->line('Profile '.$profile.': '.$code['pairing_token'].' ('.$code['expires_in'].'s; single use)');
            }
        }
        if ($this->option('json')) {
            $this->line(json_encode(['profiles' => $codes, 'api_url' => config('app.url').'/api/v1'], JSON_THROW_ON_ERROR));
        } else {
            $this->info('API: '.config('app.url').'/api/v1 · account: '.$user->email.' · PostgreSQL: '.$database);
        }

        return self::SUCCESS;
    }
}
