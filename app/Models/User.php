<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasFactory;

    protected $fillable = [
        'authentik_id',
        'email',
        'name',
        'avatar_url',
        'storage_quota_bytes',
    ];

    protected $hidden = [
        'authentik_id',
    ];

    protected function casts(): array
    {
        return [
            'last_sync_at' => 'datetime',
            'storage_quota_bytes' => 'integer',
        ];
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(Record::class);
    }

    public function userCollections(): HasMany
    {
        return $this->hasMany(UserCollection::class);
    }

    public function syncSessions(): HasMany
    {
        return $this->hasMany(SyncSession::class);
    }

    public function cryptoKeyBundle(): HasOne
    {
        return $this->hasOne(CryptoKeyBundle::class);
    }
}
