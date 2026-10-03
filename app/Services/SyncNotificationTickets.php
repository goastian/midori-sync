<?php

namespace App\Services;

use App\Exceptions\SyncProtocolException;
use App\Models\Device;
use App\Models\SyncSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SyncNotificationTickets
{
    public const LIFETIME_SECONDS = 30;

    public function issue(SyncSession $session): array
    {
        return DB::transaction(function () use ($session) {
            $current = SyncSession::whereKey($session->id)->lockForUpdate()->first();
            if (! $current || $current->isExpired() || $current->protocol_version !== 2 || ! $current->device_id ||
                ! Device::whereKey($current->device_id)->where('user_id', $current->user_id)->exists()) {
                throw new SyncProtocolException('native_session_required', 409);
            }

            DB::table('sync_notification_tickets')->where('session_id', $current->id)->delete();
            $ticket = bin2hex(random_bytes(32));
            $expiresAt = now()->addSeconds(self::LIFETIME_SECONDS);
            DB::table('sync_notification_tickets')->insert([
                'token_hash' => hash('sha256', $ticket),
                'session_id' => $current->id,
                'user_id' => $current->user_id,
                'device_id' => $current->device_id,
                'expires_at' => $expiresAt,
                'created_at' => now(),
            ]);

            return ['version' => 1, 'ticket' => $ticket, 'expires_at' => $expiresAt->toISOString()];
        }, 5);
    }

    public function consume(string $ticket): ?array
    {
        if (! preg_match('/^[0-9a-f]{64}$/D', $ticket)) {
            return null;
        }

        return DB::transaction(function () use ($ticket) {
            $query = DB::table('sync_notification_tickets')->where('token_hash', hash('sha256', $ticket));
            $record = $query->lockForUpdate()->first();
            if (! $record) {
                return null;
            }
            $query->delete();
            if (Carbon::parse($record->expires_at)->isPast()) {
                return null;
            }
            $session = SyncSession::whereKey($record->session_id)->valid()->first();
            if (! $session || $session->protocol_version !== 2 || $session->user_id !== $record->user_id ||
                $session->device_id !== $record->device_id ||
                ! Device::whereKey($record->device_id)->where('user_id', $record->user_id)->exists()) {
                return null;
            }

            return ['user_id' => (int) $record->user_id, 'session_id' => $session->id, 'device_id' => (int) $record->device_id];
        }, 5);
    }

    public function cleanupExpired(): int
    {
        return DB::table('sync_notification_tickets')->where('expires_at', '<=', now())->delete();
    }
}
