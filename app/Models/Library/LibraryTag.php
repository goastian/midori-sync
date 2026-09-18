<?php

namespace App\Models\Library;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class LibraryTag extends Model
{
    protected $table = 'library_tags';

    protected $fillable = ['user_id', 'name', 'color'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function links(): BelongsToMany
    {
        return $this->belongsToMany(LibraryLink::class, 'library_link_tag', 'library_tag_id', 'library_link_id');
    }
}
