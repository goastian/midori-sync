<?php

namespace App\Services;

use App\Exceptions\SyncQuotaException;
use App\Models\Record;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SyncQuota
{
    public static function payloadBytesSql(): string
    {
        return 'OCTET_LENGTH(payload)';
    }

    public function usage(int $userId, ?Carbon $at = null): int
    {
        $at ??= now();

        return (int) Record::forUser($userId)->where('deleted', false)
            ->where(fn ($query) => $query->whereNull('ttl')->orWhere('ttl', '>', $at))
            ->sum(DB::raw(self::payloadBytesSql()));
    }

    public function recordBytes(string $payload, bool $deleted, ?string $ttl, ?Carbon $at = null): int
    {
        return $deleted || ($ttl !== null && Carbon::parse($ttl)->lessThanOrEqualTo($at ?? now())) ? 0 : strlen($payload);
    }

    public function checkReplacement(User $user, int $collectionId, array $rows): void
    {
        $at = now();
        $old = Record::forUser($user->id)->inCollection($collectionId)->where('deleted', false)
            ->where(fn ($query) => $query->whereNull('ttl')->orWhere('ttl', '>', $at))
            ->whereIn('record_id', array_column($rows, 'record_id'))->sum(DB::raw(self::payloadBytesSql()));
        $new = 0;
        foreach ($rows as $row) {
            $new += $this->recordBytes($row['payload'], (bool) $row['deleted'], $row['ttl'], $at);
        }
        $this->checkDelta($user, $new - (int) $old, $this->usage($user->id, $at));
    }

    public function checkDelta(User $user, int $delta, int $used): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Quota changes require a transaction and the account lock');
        }
        if ($delta > 0 && $used + $delta > $user->storage_quota_bytes) {
            throw new SyncQuotaException((int) $user->storage_quota_bytes, $used, $delta);
        }
    }
}
