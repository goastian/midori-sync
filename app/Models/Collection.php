<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Collection extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'name',
        'description',
    ];

    /**
     * Per-request in-memory cache. Collections are effectively static
     * (seeded once and rarely mutated), so memoizing the lookup avoids
     * hitting the DB on every sync request.
     *
     * @var array<string, self|null>
     */
    private static array $nameCache = [];

    public function records(): HasMany
    {
        return $this->hasMany(Record::class);
    }

    public function userCollections(): HasMany
    {
        return $this->hasMany(UserCollection::class);
    }

    public static function findByName(string $name): ?self
    {
        if (array_key_exists($name, self::$nameCache)) {
            return self::$nameCache[$name];
        }

        return self::$nameCache[$name] = static::where('name', $name)->first();
    }

    /** Invalidate the in-memory lookup after a collection changes. */
    public static function forgetByName(string $name): void
    {
        unset(self::$nameCache[$name]);
    }

    /**
     * Flush the in-memory cache. Useful between test cases.
     */
    public static function flushNameCache(): void
    {
        self::$nameCache = [];
    }

    protected static function booted(): void
    {
        static::saved(fn (self $c) => self::forgetByName($c->name));
        static::deleted(fn (self $c) => self::forgetByName($c->name));
    }
}
