<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncSession extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $hidden = ['token_hash', 'refresh_hash', 'refresh_identity'];

    protected $fillable = [
        'user_id',
        'device_id',
        'token_hash',
        'ip_address',
        'user_agent',
        'last_used_at',
        'expires_at',
        'created_at',
        'protocol_version',
        'refresh_hash',
        'refresh_expires_at',
        'refreshed_at',
        'refresh_identity',
    ];

    protected function casts(): array
    {
        return [
            'protocol_version' => 'integer',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
            'last_used_at' => 'datetime',
            'refresh_expires_at' => 'datetime',
            'refreshed_at' => 'datetime',
            'refresh_identity' => 'array',
        ];
    }

    public function authorizationIsActive(): bool
    {
        return ($this->expires_at?->isFuture() ?? false) ||
            ($this->refresh_hash !== null && ($this->refresh_expires_at?->isFuture() ?? false));
    }

    public function scopeAuthorized(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->where('expires_at', '>', now())->orWhere(function (Builder $query) {
                $query->whereNotNull('refresh_hash')->where('refresh_expires_at', '>', now());
            });
        });
    }

    public function scopeInactive(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->whereNull('expires_at')->orWhere('expires_at', '<=', now());
        })->where(function (Builder $query) {
            $query->whereNull('refresh_hash')->orWhereNull('refresh_expires_at')->orWhere('refresh_expires_at', '<=', now());
        });
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
