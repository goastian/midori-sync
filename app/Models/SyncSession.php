<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class SyncSession extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'device_id',
        'token_hash',
        'ip_address',
        'user_agent',
        'last_used_at',
        'expires_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function scopeValid(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    public static function findByTokenHash(string $hash): ?self
    {
        return static::where('token_hash', $hash)->valid()->first();
    }
}
