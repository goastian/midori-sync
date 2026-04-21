<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCollection extends Model
{
    public $timestamps = false;
    public $incrementing = false;

    protected $primaryKey = ['user_id', 'collection_id'];

    protected $fillable = [
        'user_id',
        'collection_id',
        'last_modified',
        'record_count',
        'size_bytes',
    ];

    protected function casts(): array
    {
        return [
            'last_modified' => 'decimal:6',
            'record_count' => 'integer',
            'size_bytes' => 'integer',
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

    public function getKeyName()
    {
        return ['user_id', 'collection_id'];
    }

    protected function setKeysForSaveQuery($query)
    {
        return $query
            ->where('user_id', $this->getAttribute('user_id'))
            ->where('collection_id', $this->getAttribute('collection_id'));
    }
}
