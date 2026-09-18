<?php

namespace App\Models\Library;

use Illuminate\Database\Eloquent\Model;

class LibraryHighlight extends Model
{
    protected $table = 'library_highlights';

    protected $fillable = ['library_link_id', 'user_id', 'quote', 'note', 'color', 'anchor'];

    protected function casts(): array
    {
        return ['anchor' => 'array'];
    }
}
