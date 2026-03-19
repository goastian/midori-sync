<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * User model for Midori Sync, authenticated via Authentik SSO.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'authentik_id',
        'name',
        'email',
        'avatar',
        'storage_quota_bytes',
        'api_token',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'remember_token',
        'api_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'storage_quota_bytes' => 'integer',
        ];
    }

    /**
     * Get the devices connected to this user.
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * Get the active Hawk tokens for this user.
     */
    public function hawkTokens(): HasMany
    {
        return $this->hasMany(HawkToken::class);
    }

    /**
     * Get the BSOs belonging to this user.
     */
    public function bsos(): HasMany
    {
        return $this->hasMany(Bso::class);
    }

    /**
     * Get the user's collection metadata.
     */
    public function userCollections(): HasMany
    {
        return $this->hasMany(UserCollection::class);
    }

    /**
     * Calculate total storage used by this user in bytes.
     */
    public function getStorageUsedAttribute(): int
    {
        return $this->bsos()->sum('payload_size');
    }
}
