<?php

namespace App\Jobs\Library;

use App\Models\User;
use App\Services\Library\EntitlementService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshEntitlement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $userId)
    {
        $this->onQueue('default');
    }

    public function handle(EntitlementService $entitlements): void
    {
        $user = User::find($this->userId);
        if ($user) {
            $entitlements->refresh($user);
        }
    }
}
