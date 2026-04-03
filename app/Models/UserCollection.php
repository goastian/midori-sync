<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracks the last modified timestamp per user per collection.
 */
class UserCollection extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'user_collections';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public $incrementing = false;

    /**
     * This table uses a composite primary key (user_id, collection_id)
     * and does not have a standalone "id" column.
     */
    protected $primaryKey = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'collection_id',
        'modified',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'modified' => 'double',
        ];
    }

    /**
     * Get the user this record belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the collection this record belongs to.
     */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    /**
     * Ensure update queries use the composite key instead of the default "id".
     */
    protected function setKeysForSaveQuery($query): Builder
    {
        return $query
            ->where('user_id', $this->getAttribute('user_id'))
            ->where('collection_id', $this->getAttribute('collection_id'));
    }
}
