<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class Record extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'collection_id',
        'record_id',
        'version',
        'payload',
        'ttl',
        'deleted',
        'modified_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'deleted' => 'boolean',
            'modified_at' => 'decimal:6',
            'ttl' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeInCollection(Builder $query, int $collectionId): Builder
    {
        return $query->where('collection_id', $collectionId);
    }

    public function scopeModifiedSince(Builder $query, float $since): Builder
    {
        return $query->where('modified_at', '>', $since);
    }

    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereNull('ttl')->orWhere('ttl', '>', now());
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('deleted', false)->notExpired();
    }
}
