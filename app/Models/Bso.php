<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Basic Storage Object — the encrypted data unit in Firefox Sync 1.5.
 *
 * All payload data is encrypted client-side (E2E) before being stored.
 * The server never has access to the decryption keys.
 */
class Bso extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'bso';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'collection_id',
        'bso_id',
        'sortindex',
        'payload',
        'payload_size',
        'modified',
        'ttl',
        'expiry',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sortindex' => 'integer',
            'payload_size' => 'integer',
            'modified' => 'double',
            'ttl' => 'integer',
            'expiry' => 'datetime',
        ];
    }

    /**
     * Get the user this BSO belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the collection this BSO belongs to.
     */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }
}
