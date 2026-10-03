<?php

namespace App\Console\Commands;

use App\Services\SyncNotificationStream;
use Illuminate\Console\Command;

class ServeSyncNotifications extends Command
{
    protected $signature = 'sync:notifications {--port=8765}';

    protected $description = 'Serve native Sync change notifications';

    public function handle(SyncNotificationStream $stream): int
    {
        $port = filter_var($this->option('port'), FILTER_VALIDATE_INT);
        if ($port === false || $port < 1024 || $port > 65535) {
            $this->error('Invalid notification port.');

            return self::FAILURE;
        }

        $stream->serve($port);

        return self::SUCCESS;
    }
}
