<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DeviceEnrollment extends Model
{
    use HasUuids;

    public const TTL_MINUTES = 10;

    public const MAX_PENDING = 3;

    public $timestamps = false;

    protected $table = 'sync_device_enrollments';

    protected $hidden = ['payload'];

    protected $fillable = [
        'user_id', 'session_id', 'device_id', 'public_key', 'device_name', 'status',
        'approver_public_key', 'payload', 'expires_at', 'created_at',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public static function purgeExpired(): int
    {
        return static::where('expires_at', '<=', now())->delete();
    }
}
