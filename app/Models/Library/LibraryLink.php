<?php

namespace App\Models\Library;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LibraryLink extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'library_links';

    protected $fillable = [
        'user_id', 'library_collection_id', 'url', 'canonical_url', 'host',
        'title', 'description', 'favicon_url', 'og_image_url', 'readability_html',
        'reading_time_min', 'is_read', 'is_archived', 'is_favorite', 'is_pinned',
        'metadata_status', 'snapshot_status',
    ];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'is_archived' => 'boolean',
            'is_favorite' => 'boolean',
            'is_pinned' => 'boolean',
            'reading_time_min' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(LibraryCollection::class, 'library_collection_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(LibraryTag::class, 'library_link_tag', 'library_link_id', 'library_tag_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(LibrarySnapshot::class, 'library_link_id');
    }

    public function highlights(): HasMany
    {
        return $this->hasMany(LibraryHighlight::class, 'library_link_id');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(LibraryShare::class, 'library_link_id');
    }
}
